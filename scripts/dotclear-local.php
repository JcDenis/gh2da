<?php

/**
 * Test a Dotclear module locally in one command, without Docker.
 *
 * Same checks as the reusable workflow dotclear-module.yml (jobs check and test):
 * - PHP lint of module files
 * - PHPStan (level max with deprecation rules) against the Dotclear version required by the module
 * - fresh Dotclear install (SQLite) with the module and its dependencies,
 *   served by the PHP built-in web server, then admin and public pages browsed
 *   with dotclear-test.php, module enabled check, PHP errors from server log
 *
 * Usage, from anywhere:
 *   php path/to/gh2da/scripts/dotclear-local.php [module directory] [options]
 *
 * Module directory defaults to the current directory. Options:
 *   --ref=<ref>       Dotclear tag or branch for all steps (ex: 2.40.1, master)
 *                     default: like CI, PHPStan on the latest release of the version required
 *                     in _define.php, pages on the latest stable release (master if required)
 *   --skip=<steps>    comma separated steps to skip: lint, phpstan, pages
 *   --serve           keep the web server running after tests (Ctrl+C to stop)
 *   --port=<port>     web server port (default 8080)
 *   --id=<id>         module ID (default: repository name of git remote origin, else directory name)
 *   --no-update       do not fetch Dotclear master, dependencies and PHPStan again
 *
 * Requirements: PHP CLI (version required by Dotclear) with curl, pdo_sqlite, mbstring, intl; git.
 * Network access to github.com only (Dotclear, dependencies and PHPStan are taken from GitHub).
 *
 * Cache (Dotclear clones, dependencies, PHPStan, test instances): $GH2DA_CACHE or ~/.cache/gh2da
 * Test instance of a module: <cache>/instances/<module>/ (app, plugins, var, cache, php.log, pages)
 * Admin login: admin / Dotclear-Local-2026
 *
 * Exit with 1 if a step fails.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// Options
$args    = array_slice($argv, 1);
$options = ['ref' => '', 'skip' => '', 'port' => '8080'];
$flags   = [];
$module  = '.';
foreach ($args as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z-]+)$/', $arg, $m)) {
        $flags[$m[1]] = true;
    } else {
        $module = $arg;
    }
}
if (isset($flags['help']) || isset($flags['h'])) {
    preg_match('#/\*\*(.*?)\*/#s', (string) file_get_contents(__FILE__), $m);
    echo preg_replace('/^ ?\* ?/m', '', trim($m[1])) . "\n";
    exit(0);
}
$skip   = array_filter(explode(',', $options['skip']));
$port   = (int) $options['port'];
$update = !isset($flags['no-update']);
$serve  = isset($flags['serve']);

const DC_REPO  = 'https://github.com/dotclear/dotclear.git';
const OWNERS   = ['JcDenis', 'franck-paul'];
const USER     = 'admin';
const PASSWORD = 'Dotclear-Local-2026';

$scripts = __DIR__;
putenv('GIT_TERMINAL_PROMPT=0');
$git = 'git -c advice.detachedHead=false';
$cache   = rtrim((string) (getenv('GH2DA_CACHE') ?: (getenv('HOME') ?: sys_get_temp_dir()) . '/.cache/gh2da'), '/');

// Helpers
$title = function (string $text): void {
    echo "\n\033[1m== $text\033[0m\n";
};
$fail = function (string $text): never {
    fwrite(STDERR, "\033[31mERROR: $text\033[0m\n");
    exit(1);
};
/** @param array<string, string> $env */
$run = function (string $command, array $env = [], bool $quiet = false): int {
    $prefix = '';
    foreach ($env as $k => $v) {
        $prefix .= $k . '=' . escapeshellarg($v) . ' ';
    }
    $output = [];
    $code   = 0;
    if ($quiet) {
        exec($prefix . $command . ' 2>&1', $output, $code);
        if ($code !== 0) {
            echo implode("\n", $output) . "\n";
        }
    } else {
        passthru($prefix . $command, $code);
    }

    return $code;
};
$rmdir = function (string $dir) use ($run): void {
    if (is_dir($dir) || is_link($dir)) {
        $run('rm -rf ' . escapeshellarg($dir));
    }
};

// Module
$module = realpath($module);
if ($module === false || !is_file($module . '/_define.php')) {
    $fail('No _define.php in module directory');
}
foreach (['curl', 'pdo_sqlite', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        $fail("PHP extension $ext is required");
    }
}

// Module ID: repository name like in CI (from git remote origin, else module directory name)
$origin = trim((string) shell_exec('git -C ' . escapeshellarg($module) . ' remote get-url origin 2>/dev/null'));
$id     = $options['id'] ?? (preg_match('#([A-Za-z0-9_]+?)(\.git)?/?$#', $origin, $m) ? $m[1] : basename($module));

// Read module definition (same rules as job check of dotclear-module.yml)
$define   = (string) file_get_contents($module . '/_define.php');
$dcmin    = '';
$deps     = [];
// Requires array of a _define.php: [[module, version], ...] (balanced brackets, may be multiline with comments)
$requires = function (string $define): array {
    $requires = '';
    if (preg_match('/[\'"]requires[\'"]\s*=>\s*\[/', $define, $m, PREG_OFFSET_CAPTURE)) {
        $start = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        for ($i = $start; $i < strlen($define) && $depth > 0; $i++) {
            $depth += $define[$i] === '[' ? 1 : ($define[$i] === ']' ? -1 : 0);
        }
        $requires = preg_replace('#//[^\n]*#', '', substr($define, $start, $i - $start - 1));
    }
    preg_match_all('/\[\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*(?:,\s*[\'"]([0-9.]+)[\'"])?\s*\]/', (string) $requires, $reqs, PREG_SET_ORDER);

    return $reqs;
};
foreach ($requires($define) as $req) {
    if ($req[1] === 'core') {
        $dcmin = $req[2] ?? '';
    } elseif ($req[1] !== 'php') {
        $deps[] = $req[1];
    }
}
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($module, FilesystemIterator::SKIP_DOTS));
$php   = [];
foreach ($files as $file) {
    $path = $file->getPathname();
    if ($file->getExtension() === 'php' && !preg_match('#/(vendor|\.git)/#', $path)) {
        $php[] = $path;
        preg_match_all('/Dotclear\\\\(?:Plugin|Theme)\\\\([A-Za-z0-9_]+)\\\\/', (string) file_get_contents($path), $uses);
        $deps = array_merge($deps, $uses[1]);
    }
}
$deps     = array_values(array_unique(array_filter($deps, fn ($d) => strcasecmp($d, $id) !== 0)));
$type     = preg_match('/[\'"]type[\'"]\s*=>\s*[\'"]theme[\'"]/', $define) ? 'theme' : 'plugin';
$disabled = file_exists($module . '/_disabled');

echo "Module: $id ($type), Dotclear: " . ($dcmin ?: 'any') . ', dependencies: ' . ($deps === [] ? 'none' : implode(', ', $deps)) . "\n";
echo "Cache: $cache\n";
@mkdir($cache . '/dotclear', 0o777, true);
@mkdir($cache . '/deps', 0o777, true);

// Dotclear tags (cached for an hour)
$tags = function () use ($cache, $update): array {
    $file = $cache . '/dotclear-tags.txt';
    if (!is_file($file) || ($update && filemtime($file) < time() - 3600)) {
        $list = (string) shell_exec('git ls-remote --tags --refs ' . DC_REPO . ' 2>/dev/null');
        if ($list !== '') {
            file_put_contents($file, $list);
        }
    }
    $tags = array_map(fn ($l) => preg_replace('#.*refs/tags/#', '', $l), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    usort($tags, 'version_compare');

    return $tags;
};
// Latest release of a Dotclear version (ex: 2.40 => 2.40.1), master if not yet released
$release = function (string $version) use ($tags): string {
    $found = 'master';
    foreach ($tags() as $tag) {
        if ($version === '' ? preg_match('/^\d+(\.\d+)+$/', $tag) : preg_match('/^' . preg_quote($version, '/') . '(\.\d+)*$/', $tag)) {
            $found = $tag;
        }
    }

    return $found;
};
// Clone (once) or update (master) Dotclear at a ref
$dotclear = function (string $ref) use ($cache, $update, $run, $fail, $git): string {
    $dir = $cache . '/dotclear/' . $ref;
    if (!is_dir($dir . '/.git')) {
        echo "Getting Dotclear $ref\n";
        if ($run($git . ' clone -q --depth 1 --branch ' . escapeshellarg($ref) . ' ' . DC_REPO . ' ' . escapeshellarg($dir)) !== 0) {
            $fail("Can not get Dotclear $ref");
        }
    } elseif ($update && !preg_match('/^\d+(\.\d+)+$/', $ref)) {
        $run('git -C ' . escapeshellarg($dir) . ' fetch -q --depth 1 origin ' . escapeshellarg($ref) . ' && git -C ' . escapeshellarg($dir) . ' reset -q --hard FETCH_HEAD');
    }

    return $dir;
};

// Dependencies: from Dotclear, else JcDenis or franck-paul repositories
$distributed = $dotclear($release($dcmin));
$depdirs     = [];
// Git submodules of a dependency (ex: libraries of legacyMarkdown),
// default branch of the submodule when its commit is not available
$submodules = function (string $dir) use ($git, $run, $rmdir): void {
    if (!is_file($dir . '/.gitmodules')) {
        return;
    }
    if ($run('git -C ' . escapeshellarg($dir) . ' submodule update -q --init --depth 1 2>/dev/null', [], true) === 0) {
        return;
    }
    $paths = (string) shell_exec('git -C ' . escapeshellarg($dir) . " config -f .gitmodules --get-regexp '^submodule\\..*\\.path\$'");
    foreach (array_filter(explode("\n", $paths)) as $line) {
        [$key, $path] = explode(' ', trim($line), 2);
        $url          = trim((string) shell_exec('git -C ' . escapeshellarg($dir) . ' config -f .gitmodules --get ' . escapeshellarg(substr($key, 0, -5) . '.url')));
        $files        = array_diff(scandir($dir . '/' . $path) ?: [], ['.', '..', '.git']);
        if ($files === [] && $url !== '') {
            $rmdir($dir . '/' . $path);
            if ($run($git . ' clone -q --depth 1 ' . escapeshellarg($url) . ' ' . escapeshellarg($dir . '/' . $path)) === 0) {
                echo "WARNING: submodule $path of " . basename($dir) . " from default branch of $url\n";
            }
        }
    }
};
// Dependencies of dependencies are added to the list (ex: TemplateHelper for legacyMarkdown)
for ($d = 0; $d < count($deps); $d++) {
    $dep = $deps[$d];
    if (is_dir($distributed . '/plugins/' . $dep) || is_dir($distributed . '/themes/' . $dep)) {
        continue;
    }
    $dir = $cache . '/deps/' . $dep;
    if (!is_dir($dir . '/.git')) {
        foreach (OWNERS as $owner) {
            exec($git . ' clone -q --depth 1 https://github.com/' . $owner . '/' . $dep . '.git ' . escapeshellarg($dir) . ' 2>/dev/null', $o, $rc);
            if ($rc === 0) {
                echo "Dependency $dep from $owner\n";

                break;
            }
            $rmdir($dir);
        }
        if (!is_dir($dir)) {
            echo "WARNING: dependency $dep not found\n";
        }
    } elseif ($update) {
        $run('git -C ' . escapeshellarg($dir) . ' pull -q --ff-only', [], true);
    }
    if (is_dir($dir)) {
        $submodules($dir);
        $depdirs[$dep] = $dir;
        foreach ($requires((string) @file_get_contents($dir . '/_define.php')) as $req) {
            if (!in_array($req[1], ['core', 'php'], true) && strcasecmp($req[1], $id) !== 0 && !in_array($req[1], $deps, true)) {
                echo "Dependency {$req[1]} required by $dep\n";
                $deps[] = $req[1];
            }
        }
    }
}

// Dotclear refs: PHPStan on required version, pages on latest stable (master if required version is not released)
$dcref = $options['ref'] ?: $release($dcmin);
if ($options['ref'] === '') {
    // A dependency may require a newer Dotclear than the module
    $max = $dcmin;
    foreach ($depdirs as $dep => $dir) {
        if (preg_match('/\[\s*[\'"]core[\'"]\s*,\s*[\'"]([0-9.]+)[\'"]/', (string) @file_get_contents($dir . '/_define.php'), $m) && version_compare($m[1], $max, '>')) {
            echo "Dependency $dep requires Dotclear {$m[1]}\n";
            $max = $m[1];
        }
    }
    if ($max !== $dcmin && $dcref !== 'master') {
        $dcref = $release($max);
    }
}
$pagesref = $options['ref'] ?: ($dcref === 'master' ? 'master' : $release(''));

$results = [];
$failed  = false;
$step    = function (string $name, bool $ok) use (&$results, &$failed): void {
    $results[$name] = $ok;
    $failed         = $failed || !$ok;
};

// 1. Lint
if (!in_array('lint', $skip, true)) {
    $title('PHP lint');
    $errors = 0;
    foreach ($php as $file) {
        if ($run(PHP_BINARY . ' -l ' . escapeshellarg($file), [], true) !== 0) {
            $errors++;
        }
    }
    echo count($php) . " file(s), $errors error(s)\n";
    $step('lint', $errors === 0);
}

// 2. PHPStan
if (!in_array('phpstan', $skip, true)) {
    $title("PHPStan (Dotclear $dcref)");
    $dc    = $dotclear($dcref);
    // PHPStan and its deprecation rules: latest releases from GitHub (phar, no composer needed)
    $tools  = $cache . '/tools';
    $latest = function (string $repo) use ($git, $tools, $update, $run): string {
        $dir = $tools . '/' . basename($repo);
        if (is_dir($dir) && (!$update || filemtime($dir) > time() - 7 * 86400)) {
            return $dir;
        }
        $tags = array_map(fn ($l) => preg_replace('#.*refs/tags/#', '', $l), explode("\n", trim((string) shell_exec('git ls-remote --tags --refs https://github.com/' . $repo . '.git 2>/dev/null'))));
        $tags = array_values(array_filter($tags, fn ($t) => (bool) preg_match('/^\d+\.\d+\.\d+$/', (string) $t)));
        usort($tags, 'version_compare');
        $tag = end($tags);
        if ($tag !== false) {
            echo "Getting $repo $tag\n";
            $run('rm -rf ' . escapeshellarg($dir) . ' && ' . $git . ' clone -q --depth 1 --branch ' . escapeshellarg($tag) . ' https://github.com/' . $repo . '.git ' . escapeshellarg($dir));
        }

        return $dir;
    };
    @mkdir($tools, 0o777, true);
    $phpstan = $latest('phpstan/phpstan') . '/phpstan.phar';
    $rules   = $latest('phpstan/phpstan-deprecation-rules');
    if (!is_file($phpstan) || !is_file($rules . '/rules.neon')) {
        echo "PHPStan not available (github.com needed)\n";
        $step('phpstan', false);
    } else {
        // Autoload of deprecation rules (normally done by composer)
        file_put_contents($tools . '/autoload.php', '<?php
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, "PHPStan\\\\Rules\\\\Deprecations\\\\") || str_starts_with($class, "PHPStan\\\\DependencyInjection\\\\")) {
        $file = ' . var_export($rules . '/src/', true) . ' . str_replace("\\\\", "/", substr($class, 8)) . ".php";
        if (is_file($file)) {
            require $file;
        }
    }
});
');
        // Same configuration as job check of dotclear-module.yml, with absolute paths
        $exists = fn (string $path): bool => file_exists($path);
        $list   = fn (array $items): string => implode('', array_map(fn (string $i): string => "    - $i\n", $items));
        $scan   = array_values(array_filter([$dc . '/src', $dc . '/plugins', $dc . '/inc'], $exists));
        foreach ($depdirs as $dir) {
            $scan[] = is_dir("$dir/src") ? "$dir/src" : $dir;
        }
        foreach (glob($module . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (in_array(basename($dir), ['src', 'locales', 'default-templates', 'js', 'css', 'img', 'tpl', 'vendor'], true)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $scan[] = $dir;

                    break;
                }
            }
        }
        $paths = array_values(array_filter([$module . '/src', $module . '/_define.php'], $exists));
        $neon  = "includes:\n  - $rules/rules.neon\n"
            . "parameters:\n"
            . "  level: max\n"
            . "  tmpDir: $cache/phpstan-tmp/$id\n"
            . "  checkBenevolentUnionTypes: true\n"
            . "  checkDynamicProperties: true\n"
            . "  checkExplicitMixedMissingReturn: true\n"
            . "  checkFunctionNameCase: true\n"
            . "  reportAlwaysTrueInLastCondition: true\n"
            . "  reportWrongPhpDocTypeInVarTag: true\n"
            . "  reportUnmatchedIgnoredErrors: false\n"
            . ($exists("$dc/phpstan.bootstrap") ? "  bootstrapFiles:\n" . $list(["$dc/phpstan.bootstrap"]) : '')
            . "  scanFiles:\n" . $list(["$dc/index.php"])
            . "  scanDirectories:\n" . $list($scan)
            . "  paths:\n" . $list($paths)
            . ($exists("$dc/stubs/MetaRecord.stub") ? "  stubFiles:\n" . $list(["$dc/stubs/MetaRecord.stub"]) : '')
            . "  universalObjectCratesClasses:\n" . $list([
                'Dotclear\\Core\\Backend\\Filter\\FilterPosts',
                'Dotclear\\Core\\Backend\\Utility',
                'Dotclear\\Core\\Frontend\\Ctx',
                'Dotclear\\Database\\Cursor',
                'Dotclear\\Database\\Record',
                'Dotclear\\Helper\\Html\\XmlTag',
                'Dotclear\\Helper\\Stack\\Filter',
            ])
            . "  ignoreErrors:\n"
            . '    - message: \'#Variable \$this might not be defined#\'' . "\n"
            . '    - message: \'#Cannot call method registerModule\(\) on mixed#\'' . "\n"
            . '    - message: \'#Call to an undefined method Dotclear\\\\Helper\\\\Html\\\\XmlTag::#\'' . "\n";
        @mkdir($cache . '/phpstan', 0o777, true);
        file_put_contents($neon_file = $cache . '/phpstan/' . $id . '.neon', $neon);
        $step('phpstan', $run(PHP_BINARY . ' -d memory_limit=2G -d auto_prepend_file=' . escapeshellarg($tools . '/autoload.php') . ' ' . escapeshellarg($phpstan) . ' analyse --no-progress -c ' . escapeshellarg($neon_file)) === 0);
    }
}

// 3. Pages
if (!in_array('pages', $skip, true) && $disabled) {
    echo "\nModule has a _disabled file, pages are not tested\n";
} elseif (!in_array('pages', $skip, true) || $serve) {
    $title("Dotclear $pagesref with $id");
    $dc   = $dotclear($pagesref);
    $inst = $cache . '/instances/' . $id;
    $app  = $inst . '/app';
    $url  = "http://127.0.0.1:$port";

    // Fresh instance, like docker-dotclear structure (app, plugins, cache, var)
    $rmdir($inst);
    foreach (['app', 'inc', 'plugins', 'cache', 'var', 'pages'] as $dir) {
        @mkdir($inst . '/' . $dir, 0o777, true);
    }
    $run('cp -a ' . escapeshellarg($dc) . '/. ' . escapeshellarg($app) . ' && rm -rf ' . escapeshellarg($app . '/.git'));
    // Blog public directory (in release archives, not in git)
    @mkdir($app . '/public', 0o777, true);
    // Dotclear includes compiled templates with @, which hides PHP errors of public pages
    $tpl = $app . '/src/Helper/Html/Template/Template.php';
    if (is_file($tpl)) {
        file_put_contents($tpl, str_replace('@include $dest_file;', 'include $dest_file;', (string) file_get_contents($tpl)));
    }
    // Http::head() sends an invalid status header with PHP built-in server (empty JSON responses): treat cli-server like cgi
    $http = $app . '/src/Helper/Network/Http.php';
    if (is_file($http)) {
        file_put_contents($http, str_replace("preg_match('/cgi/', PHP_SAPI)", "preg_match('/cgi|cli-server/', PHP_SAPI)", (string) file_get_contents($http)));
    }
    $release_json = json_decode((string) file_get_contents($app . '/release.json'), true);
    $php_min      = is_array($release_json) ? (string) ($release_json['php_min'] ?? '') : '';
    if ($php_min !== '' && version_compare(PHP_VERSION, $php_min, '<')) {
        $fail("Dotclear $pagesref requires PHP $php_min (PHP " . PHP_VERSION . ')');
    }
    echo 'PHP ' . PHP_VERSION . ', Dotclear ' . (is_array($release_json) ? $release_json['release_version'] : $pagesref) . "\n";

    // Install from command line: first run writes configuration file, second run creates database and super admin
    $config  = $inst . '/inc/config.php';
    $install = fn (): int => $run('cd ' . escapeshellarg($app) . ' && ' . PHP_BINARY . ' admin/install/index.php ' . escapeshellarg($config) . ' -n'
        . ' --dbdriver=pdosqlite --dbname=' . escapeshellarg($inst . '/dotclear.sqlite') . ' --dbprefix=dc_'
        . ' --adminemail=local@example.org --adminurl=' . escapeshellarg("$url/admin/")
        . ' --uemail=local@example.org --ulogin=' . USER . ' --upassword=' . PASSWORD
        . ' --blogurl=' . escapeshellarg("$url/default/") . ' --urlscan=path_info < /dev/null >> ' . escapeshellarg($inst . '/install.log') . ' 2>&1', [
            'DC_PLUGINS_ROOT' => $inst . '/plugins',
            'DC_TPL_CACHE'    => $inst . '/cache',
            'DC_VAR'          => $inst . '/var',
        ]);
    $code      = $install() ?: $install();
    $installed = is_file($inst . '/dotclear.sqlite') && (int) (new PDO('sqlite:' . $inst . '/dotclear.sqlite'))->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'dc_post'")->fetchColumn() === 1;
    if ($code !== 0 || !is_file($config) || !$installed) {
        echo (string) @file_get_contents($inst . '/install.log');
        $fail('Dotclear installation failed');
    }
    // Plugins root with module dir (older installers ignore DC_PLUGINS_ROOT), debug and dev modes (deprecated logs)
    $conf = (string) file_get_contents($config);
    $conf = (string) preg_replace("/define\('DC_PLUGINS_ROOT',.*\);/", "define('DC_PLUGINS_ROOT', " . var_export($app . '/plugins' . PATH_SEPARATOR . $inst . '/plugins', true) . ');', $conf);
    $conf .= "\ndefine('DC_DEBUG', true);\ndefine('DC_DEV', true);\n";
    file_put_contents($config, $conf);
    $db = fn (string $command, string $arg = ''): string => trim((string) shell_exec(PHP_BINARY . ' ' . escapeshellarg($scripts . '/dotclear-db.php') . ' ' . escapeshellarg($inst . '/dotclear.sqlite') . ' ' . $command . ($arg !== '' ? ' ' . escapeshellarg($arg) : '')));
    $db('blogurl', "$url/default/");

    // Module and dependencies (copied, not linked, so file paths in errors are the ones of the instance)
    $copy = function (string $from, string $name) use ($inst, $app, $run): void {
        $dir = preg_match('/[\'"]type[\'"]\s*=>\s*[\'"]theme[\'"]/', (string) @file_get_contents($from . '/_define.php')) ? $app . '/themes' : $inst . '/plugins';
        $run('cp -a ' . escapeshellarg($from) . ' ' . escapeshellarg($dir . '/' . $name) . ' && rm -rf ' . escapeshellarg($dir . '/' . $name . '/.git'));
    };
    foreach ($depdirs as $dep => $dir) {
        if (!is_dir($app . '/plugins/' . $dep) && !is_dir($app . '/themes/' . $dep)) {
            $copy($dir, $dep);
        }
    }
    $copy($module, $id);
    if ($type === 'theme') {
        $db('theme', $id);
    }
    // Plugins installed by Dotclear on first admin page
    $db('version', $id);

    // Router for PHP built-in server, like docker-dotclear nginx configuration
    $router = $inst . '/router.php';
    file_put_contents($router, '<?php
$_SERVER["DC_RC_PATH"] = ' . var_export($config, true) . ';
$root = $_SERVER["DOCUMENT_ROOT"];
$uri  = rawurldecode((string) parse_url((string) $_SERVER["REQUEST_URI"], PHP_URL_PATH));
if (preg_match("#^/(db|cache|inc|var)(/|$)#", $uri)) {
    http_response_code(404);
    return true;
}
// Blogs public and themes paths
if (preg_match("#^/[^/]+/(public|themes)/(.*)$#", $uri, $m) && is_file($root . "/" . $m[1] . "/" . $m[2])) {
    $file = $root . "/" . $m[1] . "/" . $m[2];
    $types = ["css" => "text/css", "js" => "application/javascript", "svg" => "image/svg+xml", "png" => "image/png", "jpg" => "image/jpeg", "gif" => "image/gif", "webp" => "image/webp", "woff2" => "font/woff2", "json" => "application/json"];
    header("Content-Type: " . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? (mime_content_type($file) ?: "application/octet-stream")));
    readfile($file);
    return true;
}
// PHP files, with path info
if (preg_match("#^(.+?\.php)(/.*)?$#", $uri, $m) && is_file($root . $m[1])) {
    $script = $m[1];
    $_SERVER["PATH_INFO"] = $m[2] ?? "";
} elseif (is_dir($root . $uri) && is_file($root . rtrim($uri, "/") . "/index.php")) {
    if (!str_ends_with($uri, "/")) {
        header("Location: " . $uri . "/" . (isset($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"] !== "" ? "?" . $_SERVER["QUERY_STRING"] : ""));
        return true;
    }
    $script = $uri . "index.php";
    $_SERVER["PATH_INFO"] = "";
} elseif ($uri !== "/" && is_file($root . $uri)) {
    return false;
} else {
    // Blogs path_info URL: first part is blog id
    preg_match("#^/([^/]+)(/.*|$)#", $uri, $m);
    $_SERVER["DC_BLOG_ID"] = $m[1] ?? "default";
    $script = "/index.php";
    $_SERVER["PATH_INFO"] = ($m[2] ?? "") === "" ? "/" : $m[2];
}
$_SERVER["SCRIPT_NAME"] = $_SERVER["PHP_SELF"] = $script;
$_SERVER["SCRIPT_FILENAME"] = $root . $script;
chdir(dirname($root . $script));
require $root . $script;
');

    // Web server, all PHP errors logged
    $log    = $inst . '/php.log';
    touch($log);
    $server = proc_open([
        PHP_BINARY,
        '-d', 'error_reporting=' . E_ALL, '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_log=' . $log,
        '-d', 'upload_max_filesize=32M', '-d', 'post_max_size=32M',
        '-S', "127.0.0.1:$port", '-t', $app, $router,
    ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $inst . '/server.log', 'a'], 2 => ['file', $inst . '/server.log', 'a']], $pipes, $app, array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '4']));
    if (!is_resource($server)) {
        $fail('Can not start PHP web server');
    }
    $stop = function () use ($server): void {
        $status = proc_get_status($server);
        if ($status['running']) {
            // Stop workers too
            exec('pkill -P ' . (int) $status['pid'] . ' 2>/dev/null');
            proc_terminate($server);
        }
        proc_close($server);
    };
    register_shutdown_function($stop);
    $ready = false;
    for ($i = 0; $i < 50 && !$ready; $i++) {
        usleep(200000);
        $ready = @fsockopen('127.0.0.1', $port) !== false;
    }
    if (!$ready) {
        echo (string) @file_get_contents($inst . '/server.log');
        $fail("Web server not started on port $port (port already used? use --port=)");
    }

    if (!in_array('pages', $skip, true)) {
        // Browse pages (dotclear-test.php, as in CI)
        $env = [
            'DC_ADMIN_URL'      => "$url/admin/",
            'DC_BLOG_URL'       => "$url/default/",
            'DC_USER'           => USER,
            'DC_PASSWORD'       => PASSWORD,
            'DC_POST_URL'       => $db('posturl'),
            'MODULE_ID'         => $id,
            'MODULE_TYPE'       => $type,
            'MODULE_DIR'        => $module,
            'MODULE_INSTALLED'  => ($type === 'theme' ? $app . '/themes/' : $inst . '/plugins/') . $id,
            'OUT_DIR'           => $inst . '/pages',
            'GITHUB_STEP_SUMMARY' => '',
        ];
        $title('Pages');
        $step('pages', $run(PHP_BINARY . ' ' . escapeshellarg($scripts . '/dotclear-test.php'), $env) === 0);

        // Module enabled
        $title('Module enabled');
        if ($type === 'theme') {
            $ok = str_contains((string) @file_get_contents("$url/default/"), "/themes/$id/");
            echo $ok ? "Theme $id used by blog\n" : "Theme $id is not used by blog (missing dependency or error on load)\n";
        } else {
            $version = $db('version', $id);
            $ok      = $version !== '';
            echo $ok ? "Module $id $version enabled\n" : "Module $id is not enabled in Dotclear (missing dependency or error on load)\n";
        }
        $step('enabled', $ok);

        // Server logs
        $title('Server logs');
        file_put_contents($inst . '/deprecated.log', $db('deprecated'));
        $step('logs', $run(PHP_BINARY . ' ' . escapeshellarg($scripts . '/dotclear-test.php') . ' logs ' . escapeshellarg($log) . ' ' . escapeshellarg($inst . '/deprecated.log'), $env) === 0);
        if (glob($inst . '/pages/*.html')) {
            echo "Failing pages saved in $inst/pages\n";
        }
    }
}

// Summary
$title('Summary');
foreach ($results as $name => $ok) {
    echo ($ok ? "\033[32mOK  \033[0m" : "\033[31mFAIL\033[0m") . " $name\n";
}
if ($results === []) {
    echo "Nothing tested\n";
}

if ($serve && isset($server)) {
    echo "\nDotclear is running: $url/admin/ (" . USER . ' / ' . PASSWORD . "), blog $url/default/\n";
    echo "PHP errors: $log\nModule copy: " . ($type === 'theme' ? $app . '/themes/' : $inst . '/plugins/') . "$id\nCtrl+C to stop\n";
    while (proc_get_status($server)['running']) {
        sleep(1);
    }
}

exit($failed ? 1 : 0);
