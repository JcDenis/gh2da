<?php

/**
 * Browse a Dotclear installation with a module enabled and check pages for PHP errors.
 *
 * Environment:
 * - DC_ADMIN_URL   admin URL (ex: http://localhost:8080/admin/)
 * - DC_BLOG_URL    default blog URL (ex: http://localhost:8080/default/)
 * - DC_USER        super admin login
 * - DC_PASSWORD    super admin password
 * - DC_POST_URL    URL part of a published post (optional)
 * - MODULE_ID      module ID
 * - MODULE_TYPE    plugin or theme
 * - MODULE_DIR     local path of module (to find Manage.php, Config.php and extra URLs)
 * - OUT_DIR        directory where failing pages are saved
 *
 * Extra URLs can be listed in module file .github/dotclear-test.txt, one per line:
 * "admin:index.php?process=Plugin&p=myModule&part=xxx" or "public:my-url/xxx".
 *
 * Exit with 1 if a page fails (HTTP status >= 500 or PHP error in page).
 *
 * Second mode, after pages: php dotclear-test.php logs <php log file> [<deprecated log file>]
 * Reports PHP errors from server logs: errors in module files fail, others are warnings.
 * Deprecated calls logged by Dotclear (DEV mode) from module files are warnings.
 */
declare(strict_types=1);

$env = fn (string $name, string $default = ''): string => (string) (getenv($name) ?: $default);

if (($argv[1] ?? '') === 'logs') {
    $id      = $env('MODULE_ID');
    $type    = $env('MODULE_TYPE', 'plugin');
    $in_module = fn (string $text): bool => (bool) preg_match('#/' . ($type === 'theme' ? 'themes' : 'plugins') . '/' . preg_quote($id, '#') . '/#', $text);
    $errors  = [];
    $notices = [];

    // PHP errors (same error is reported once)
    foreach (file((string) ($argv[2] ?? ''), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('#PHP (Fatal error|Parse error|Recoverable fatal error|Warning|Notice|Deprecated):\s+(.*?) in (/\S+?)(?::| on line )(\d+)#', $line, $m)) {
            $file = preg_replace('#^.*?/(plugins|themes)/#', '$1/', $m[3]);
            $text = "PHP {$m[1]}: {$m[2]} in $file:{$m[4]}";
            if ($in_module($m[3])) {
                $errors[$text] = $text;
            } else {
                $notices[$text] = $text;
            }
        }
    }

    // Dotclear deprecated logs (DEV mode only)
    if (isset($argv[3]) && file_exists($argv[3])) {
        foreach (file($argv[3], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($in_module($line)) {
                $text = 'Deprecated: ' . preg_replace('#/var/www/dotclear/#', '', $line);
                $notices[$text] = $text;
            }
        }
    }

    foreach ($errors as $text) {
        echo "::error title=PHP error in $id::$text\n";
    }
    foreach ($notices as $text) {
        echo "::warning::$text\n";
    }
    if (getenv('GITHUB_STEP_SUMMARY')) {
        $summary = "\n#### Server logs\n\n" . ($errors === [] && $notices === [] ? "No PHP error.\n" : '')
            . implode('', array_map(fn ($t) => "- ❌ $t\n", $errors))
            . implode('', array_map(fn ($t) => "- ⚠️ $t\n", $notices));
        file_put_contents((string) getenv('GITHUB_STEP_SUMMARY'), $summary, FILE_APPEND);
    }
    echo count($errors) . " PHP error(s) in module, " . count($notices) . " other warning(s)\n";
    exit($errors === [] ? 0 : 1);
}

$admin  = rtrim($env('DC_ADMIN_URL'), '/') . '/';
$blog   = $env('DC_BLOG_URL');
$id     = $env('MODULE_ID');
$type   = $env('MODULE_TYPE', 'plugin');
$dir    = rtrim($env('MODULE_DIR'), '/');
$out    = $env('OUT_DIR', sys_get_temp_dir() . '/dotclear-test');
$cookie = tempnam(sys_get_temp_dir(), 'dc');

@mkdir($out, 0o777, true);

/**
 * @param   array<string, string>   $post
 *
 * @return  array{0: int, 1: string}
 */
$request = function (string $url, array $post = []) use ($cookie): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR      => $cookie,
        CURLOPT_COOKIEFILE     => $cookie,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => 'Dotclear module test',
    ]);
    if ($post !== []) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$code, $body];
};

$failures = 0;
$results  = [];

$check = function (string $label, string $url, array $post = []) use ($request, $out, &$failures, &$results): string {
    [$code, $body] = $request($url, $post);

    $errors = [];
    if ($code === 0) {
        $errors[] = 'no response';
    } elseif ($code >= 500) {
        $errors[] = "HTTP $code";
    }
    // PHP errors displayed in page (display_errors on), with or without HTML
    if (preg_match_all('#(?:<b>)?(Fatal error|Parse error|Warning|Notice|Deprecated|Uncaught \w+)(?:</b>)?:\s+(.{0,300}?)(?: in (?:<b>)?(\S+?)(?:</b>)? on line (?:<b>)?(\d+))#s', $body, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $errors[] = trim(strip_tags($m[1] . ': ' . $m[2] . ' in ' . $m[3] . ':' . $m[4]));
        }
    }

    $status = $errors === [] ? 'OK' : 'FAIL';
    $results[] = sprintf('| %s | %s | %d | %s |', $status === 'OK' ? '✅' : '❌', $label, $code, implode('<br>', array_map(fn ($e) => htmlspecialchars($e), $errors)));
    echo sprintf("%-4s %3d %s (%s)\n", $status, $code, $label, $url);
    foreach ($errors as $error) {
        echo "::error title=$label::$error\n";
    }
    if ($errors !== []) {
        $failures++;
        file_put_contents($out . '/' . preg_replace('/[^a-z0-9]+/i', '-', $label) . '.html', $body);
    }

    return $body;
};

// Log in
[$code, $body] = $request($admin . 'index.php?process=Auth');
[$code, $body] = $request($admin . 'index.php?process=Auth', [
    'user_id'  => $env('DC_USER', 'admin'),
    'user_pwd' => $env('DC_PASSWORD'),
]);
if (!str_contains($body, 'process=Logout') && !str_contains($body, 'logout')) {
    echo "::error::Can not log in to Dotclear admin (HTTP $code)\n";
    file_put_contents($out . '/login.html', $body);
    // Diagnostic: redirections and page text
    $ch = curl_init($admin . 'index.php?process=Home');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_COOKIEFILE => $cookie]);
    $headers = (string) curl_exec($ch);
    curl_close($ch);
    preg_match_all('/^(HTTP\/\S+ \d+.*|Location: .*)$/mi', $headers, $m);
    echo "Redirections:\n" . implode("\n", array_map('trim', $m[0])) . "\n";
    echo 'Page: ' . substr(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('#<(script|style)[^>]*>.*?</\1>#s', '', $body)))), 0, 400) . "\n";
    exit(1);
}
echo "Logged in to $admin\n";

// Admin pages
$pages = [
    'admin dashboard'     => 'index.php?process=Home',
    'admin plugins'       => 'index.php?process=Plugins',
    'admin themes'        => 'index.php?process=BlogTheme',
    'admin blog settings' => 'index.php?process=BlogPref',
    'admin user prefs'    => 'index.php?process=UserPreferences',
    'admin search'        => 'index.php?process=Search&q=dotclear',
    'admin new post'      => 'index.php?process=Post',
    'admin posts'         => 'index.php?process=Posts',
    'admin categories'    => 'index.php?process=Categories',
    'admin comments'      => 'index.php?process=Comments',
    'admin media'         => 'index.php?process=Media',
    'admin users'         => 'index.php?process=Users',
    'admin widgets'       => 'index.php?process=Plugin&p=widgets',
];
if (file_exists($dir . '/src/Manage.php')) {
    $pages['admin module page'] = 'index.php?process=Plugin&p=' . rawurlencode($id);
}
if (file_exists($dir . '/src/Config.php')) {
    $pages['admin module config'] = $type === 'theme' ?
        'index.php?process=BlogTheme&module=' . rawurlencode($id) . '&conf=1' :
        'index.php?process=Plugins&module=' . rawurlencode($id) . '&conf=1';
}

// Public pages
$public = [
    'public home'    => '',
    'public page 2'  => 'page/2',
    'public archive' => 'archive',
    'public search'  => '?q=dotclear',
    'public atom'    => 'feed/atom',
    'public rss2'    => 'feed/rss2',
    'public 404'     => 'this-page-does-not-exist',
];
if ($env('DC_POST_URL') !== '') {
    $public['public post'] = 'post/' . $env('DC_POST_URL');
}

// Extra URLs from module
if (file_exists($dir . '/.github/dotclear-test.txt')) {
    foreach (file($dir . '/.github/dotclear-test.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^(admin|public):(.*)$/', trim($line), $m)) {
            if ($m[1] === 'admin') {
                $pages['admin ' . $m[2]] = $m[2];
            } else {
                $public['public ' . $m[2]] = $m[2];
            }
        }
    }
}

foreach ($pages as $label => $path) {
    $check($label, $admin . $path);
}
foreach ($public as $label => $path) {
    $check($label, $blog . $path);
}

// Summary
$summary = "### Dotclear test of $type $id\n\n| | Page | HTTP | Errors |\n|---|---|---|---|\n" . implode("\n", $results) . "\n";
if (getenv('GITHUB_STEP_SUMMARY')) {
    file_put_contents((string) getenv('GITHUB_STEP_SUMMARY'), $summary, FILE_APPEND);
}

echo $failures === 0 ? "All pages OK\n" : "$failures page(s) failed\n";
exit($failures === 0 ? 0 : 1);
