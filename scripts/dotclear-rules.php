<?php

/**
 * Build the compatibility rules of the next Dotclear version, for modules (plugins and themes).
 *
 * Usage: php scripts/dotclear-rules.php /path/to/dotclear-stable /path/to/dotclear-next [manual.json] > dotclear/rules.json
 *
 * Compares the code of a stable tree (last release) and of a next tree (master) and writes one JSON rule per change
 * a module can meet:
 *
 * - alert (breaks with the next version): class, interface, trait, enum, method, constant or function removed,
 *   more required parameters for a method or a function, behavior no longer called;
 * - notice (breaks later): element marked `@deprecated` in the next tree, behavior flagged as deprecated.
 *
 * Methods are compared with their traits and parent classes resolved: a method moved to a trait or a parent is not removed.
 * Rules of the manual file (same format) are added; a manual rule with the id of a generated one replaces it,
 * and `"disabled": true` removes it.
 *
 * Text parsing with the PHP tokenizer only (no code of the trees is run). See dotclear/RULES.md.
 */
declare(strict_types=1);

require_once __DIR__ . '/dotclear-behaviors.php';

$stable = rtrim($argv[1] ?? '', '/');
$next   = rtrim($argv[2] ?? '', '/');
$manual = $argv[3] ?? '';
if (!is_dir($stable . '/src') || !is_dir($next . '/src')) {
    fwrite(STDERR, "Usage: php dotclear-rules.php /path/to/dotclear-stable /path/to/dotclear-next [manual.json]\n");
    exit(1);
}

/**
 * Read the release file of a tree.
 *
 * @return  array<string, mixed>
 */
function dcrRelease(string $root): array
{
    $json = json_decode((string) file_get_contents($root . '/release.json'), true);

    return is_array($json) ? $json : [];
}

/**
 * Get the git commit of a tree, if any.
 */
function dcrCommit(string $root): string
{
    $out = [];
    exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>/dev/null', $out);

    return trim($out[0] ?? '');
}

/**
 * Get a version without its development suffix: 2.41-dev → 2.41.
 */
function dcrVersion(string $version): string
{
    return (string) preg_replace('/-.*$/', '', $version);
}

/**
 * Read the deprecation note of a doc comment, or null when not deprecated.
 */
function dcrDeprecated(string $doc): ?string
{
    if (!preg_match('/@deprecated\b([^\n]*(?:\n\s*\*(?!\s*@)(?!\s*\/)[^\n]*)*)/', $doc, $m)) {
        return null;
    }
    $note = (string) preg_replace('/\s*\n\s*\*\s*/', ' ', $m[1]);

    return trim((string) preg_replace('/\s+/', ' ', $note));
}

/**
 * Parse the PHP files of a tree.
 *
 * Classes, interfaces, traits and enums of `src/`, `inc/` and `plugins/<id>/src/`, with their parent, traits,
 * public and protected methods and public constants; namespaced and global functions.
 *
 * @return  array{classes: array<string, array<string, mixed>>, functions: array<string, array<string, mixed>>}
 */
function dcrParse(string $root): array
{
    $dirs = ['src', 'inc'];
    foreach (glob($root . '/plugins/*/src') ?: [] as $d) {
        $dirs[] = substr($d, strlen($root) + 1);
    }

    $classes   = [];
    $functions = [];
    foreach ($dirs as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = (string) $file;
            if (str_ends_with($path, '.php')) {
                dcrParseFile($path, substr($path, strlen($root) + 1), $classes, $functions);
            }
        }
    }
    ksort($classes);
    ksort($functions);

    return ['classes' => $classes, 'functions' => $functions];
}

/**
 * Parse one PHP file.
 *
 * @param   array<string, array<string, mixed>>  $classes
 * @param   array<string, array<string, mixed>>  $functions
 */
function dcrParseFile(string $path, string $rel, array &$classes, array &$functions): void
{
    $tokens = token_get_all((string) file_get_contents($path));
    $count  = count($tokens);

    $ns      = '';
    $uses    = [];
    $doc     = '';
    $mods    = [];
    $depth   = 0;
    $current = null; // name of the class being read
    $cdepth  = -1;   // depth of its body

    // Resolve a class name read in the file
    $resolve = function (string $name) use (&$ns, &$uses): string {
        if ($name === '' || $name[0] === '\\') {
            return ltrim($name, '\\');
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($uses[$first])) {
            $rest = substr($name, strlen(explode('\\', $name)[0]));

            return $uses[$first] . $rest;
        }
        if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
            return $name;
        }

        return $ns === '' ? $name : $ns . '\\' . $name;
    };

    // Next significant token index
    $nextSig = function (int $i) use ($tokens, $count): int {
        for ($i++; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return $count;
    };

    // Read a name (possibly qualified) at index $i
    $readName = function (int &$i) use ($tokens): string {
        $t = $tokens[$i] ?? null;
        if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            return $t[1];
        }

        return '';
    };

    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];

        if (!is_array($t)) {
            if ($t === '{') {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
                if ($current !== null && $depth < $cdepth) {
                    $current = null;
                    $cdepth  = -1;
                }
            } elseif ($t === ';' || $t === '(' || $t === '=') {
                $mods = [];
                $doc  = '';
            }

            continue;
        }

        switch ($t[0]) {
            case T_CURLY_OPEN:
            case T_DOLLAR_OPEN_CURLY_BRACES:
                $depth++;

                break;

            case T_DOC_COMMENT:
                $doc = $t[1];

                break;

            case T_PUBLIC:
            case T_PROTECTED:
            case T_PRIVATE:
            case T_STATIC:
            case T_ABSTRACT:
            case T_FINAL:
            case T_READONLY:
                $mods[] = strtolower($t[1]);

                break;

            case T_NAMESPACE:
                $j = $nextSig($i);
                if (is_array($tokens[$j] ?? null) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                    $ns   = $tokens[$j][1];
                    $uses = [];
                    $i    = $j;
                }

                break;

            case T_USE:
                if ($current === null && $depth === 0) {
                    // Import: use A\B [as C], D; (functions and constants imports ignored)
                    $j = $nextSig($i);
                    if (is_array($tokens[$j]) && in_array(strtolower($tokens[$j][1]), ['function', 'const'], true)) {
                        break;
                    }
                    $name  = '';
                    $alias = '';
                    $as    = false;
                    for (; $j < $count && $tokens[$j] !== ';'; $j++) {
                        $tk = $tokens[$j];
                        if ($tk === ',' || $tk === '{' || $tk === '}') {
                            if ($name !== '') {
                                $uses[strtolower($alias !== '' ? $alias : basename(str_replace('\\', '/', $name)))] = ltrim($name, '\\');
                            }
                            $name  = $tk === '{' ? $name : '';
                            $alias = '';
                            $as    = false;

                            continue;
                        }
                        if (!is_array($tk) || $tk[0] === T_WHITESPACE) {
                            continue;
                        }
                        if ($tk[0] === T_AS) {
                            $as = true;
                        } elseif (in_array($tk[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                            if ($as) {
                                $alias = $tk[1];
                            } else {
                                $name .= $tk[1];
                            }
                        }
                    }
                    if ($name !== '') {
                        $uses[strtolower($alias !== '' ? $alias : basename(str_replace('\\', '/', $name)))] = ltrim($name, '\\');
                    }
                    $i = $j;
                } elseif ($current !== null && $depth === $cdepth) {
                    // Traits used by the class
                    for ($j = $nextSig($i); $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j = $nextSig($j)) {
                        $n = $readName($j);
                        if ($n !== '') {
                            $classes[$current]['traits'][] = $resolve($n);
                        }
                    }
                    $i = $j - 1;
                }
                $mods = [];
                $doc  = '';

                break;

            case T_CLASS:
            case T_INTERFACE:
            case T_TRAIT:
            case T_ENUM:
                // Skip Foo::class and anonymous classes
                $p = $i - 1;
                while ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) {
                    $p--;
                }
                if (is_array($tokens[$p] ?? null) && in_array($tokens[$p][0], [T_DOUBLE_COLON, T_NEW], true)) {
                    break;
                }
                $j = $nextSig($i);
                if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $current !== null) {
                    break;
                }
                $name = $ns === '' ? $tokens[$j][1] : $ns . '\\' . $tokens[$j][1];
                $kind = strtolower($t[1]);
                $info = [
                    'kind'       => $kind,
                    'file'       => $rel,
                    'parent'     => [],
                    'traits'     => [],
                    'methods'    => [],
                    'consts'     => [],
                    'private'    => [],
                    'props'      => [],
                    'deprecated' => dcrDeprecated($doc),
                ];
                // extends / implements (interfaces may extend several)
                for ($k = $nextSig($j); $k < $count && $tokens[$k] !== '{'; $k = $nextSig($k)) {
                    if (is_array($tokens[$k]) && $tokens[$k][0] === T_IMPLEMENTS) {
                        break;
                    }
                    if (is_array($tokens[$k]) && $tokens[$k][0] === T_EXTENDS) {
                        for ($k = $nextSig($k); $k < $count && $tokens[$k] !== '{'; $k = $nextSig($k)) {
                            if (is_array($tokens[$k]) && $tokens[$k][0] === T_IMPLEMENTS) {
                                break 2;
                            }
                            $n = $readName($k);
                            if ($n !== '') {
                                $info['parent'][] = $resolve($n);
                            }
                        }

                        break;
                    }
                }
                $classes[$name] = $info;
                $current        = $name;
                $cdepth         = $depth + 1;
                $i              = $j;
                $mods           = [];
                $doc            = '';

                break;

            case T_FUNCTION:
                $j = $nextSig($i);
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $fname = $tokens[$j][1];
                } elseif ($tokens[$j] === '&') {
                    $j     = $nextSig($j);
                    $fname = is_array($tokens[$j]) ? $tokens[$j][1] : '';
                } else {
                    // closure
                    $mods = [];
                    $doc  = '';

                    break;
                }
                // Parameters
                $k = $nextSig($j);
                if ($tokens[$k] !== '(') {
                    break;
                }
                $level  = 0;
                $params = [];
                $cur    = '';
                for (; $k < $count; $k++) {
                    $tk = $tokens[$k];
                    $s  = is_array($tk) ? $tk[1] : $tk;
                    if ($tk === '(' || $tk === '[' || $tk === '{') {
                        $level++;
                        if ($level === 1 && $tk === '(') {
                            continue;
                        }
                    } elseif ($tk === ')' || $tk === ']' || $tk === '}') {
                        $level--;
                        if ($level === 0) {
                            break;
                        }
                    } elseif ($tk === ',' && $level === 1) {
                        $params[] = $cur;
                        $cur      = '';

                        continue;
                    }
                    if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $cur .= $s;
                }
                if (trim($cur) !== '') {
                    $params[] = $cur;
                }
                $params   = array_values(array_filter(array_map(fn ($p): string => trim((string) preg_replace('/\s+/', ' ', $p)), $params), fn ($p): bool => $p !== ''));
                $required = 0;
                foreach ($params as $p) {
                    if (!str_contains($p, '=') && !str_contains($p, '...')) {
                        $required++;
                    }
                }
                $entry = [
                    'params'     => $params,
                    'required'   => $required,
                    'deprecated' => dcrDeprecated($doc),
                ];
                if ($current !== null && $depth === $cdepth && strtolower($fname) === '__construct') {
                    // Promoted constructor properties
                    foreach ($params as $p) {
                        if (preg_match('/^((?:(?:public|protected|private|readonly)(?:\([a-z]+\))?\s+)+).*?\$(\w+)/i', $p, $pm)) {
                            if (preg_match('/\b(?:protected|private)\b/i', $pm[1])) {
                                $classes[$current]['private']['$' . $pm[2]] = true;
                            } else {
                                $classes[$current]['props'][$pm[2]] = ['deprecated' => null];
                            }
                        }
                    }
                }
                if ($current !== null && $depth === $cdepth) {
                    $entry['static']  = in_array('static', $mods, true);
                    $entry['private'] = in_array('private', $mods, true);
                    if ($entry['private']) {
                        $classes[$current]['private'][strtolower($fname)] = true;
                    } else {
                        $classes[$current]['methods'][strtolower($fname)] = ['name' => $fname] + $entry;
                    }
                } elseif ($current === null) {
                    $full             = $ns === '' ? $fname : $ns . '\\' . $fname;
                    $functions[$full] = ['name' => $full, 'file' => $rel] + $entry;
                }
                $i    = $k;
                $mods = [];
                $doc  = '';

                break;

            case T_VARIABLE:
                if ($current !== null && $depth === $cdepth && $mods !== []) {
                    $prop = substr($t[1], 1);
                    if (in_array('private', $mods, true) || in_array('protected', $mods, true)) {
                        $classes[$current]['private']['$' . $prop] = true;
                    } else {
                        $classes[$current]['props'][$prop] = ['deprecated' => dcrDeprecated($doc)];
                    }
                    // keep modifiers for "public $a, $b;" until ';'
                }

                break;

            case T_CONST:
                if ($current !== null && $depth === $cdepth) {
                    // const [type] NAME = ...
                    $last = '';
                    for ($j = $nextSig($i); $j < $count && $tokens[$j] !== '=' && $tokens[$j] !== ';'; $j = $nextSig($j)) {
                        if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                            $last = $tokens[$j][1];
                        }
                    }
                    if ($last !== '' && (in_array('private', $mods, true) || in_array('protected', $mods, true))) {
                        $classes[$current]['private'][$last] = true;
                    } elseif ($last !== '') {
                        $classes[$current]['consts'][$last] = ['deprecated' => dcrDeprecated($doc)];
                    }
                }
                $mods = [];
                $doc  = '';

                break;
        }
    }
}

/**
 * Get the methods of a class with its traits and parents resolved.
 *
 * @param   array<string, array<string, mixed>>  $classes
 * @param   array<string, true>                  $seen
 *
 * @return  array<string, array<string, mixed>>  Methods by lower case name, with the declaring class in 'class'
 */
function dcrMethods(array $classes, string $name, array $seen = []): array
{
    if (!isset($classes[$name]) || isset($seen[$name])) {
        return [];
    }
    $seen[$name] = true;
    $c           = $classes[$name];
    $methods     = [];
    foreach ($c['parent'] as $p) {
        $methods = array_merge($methods, dcrMethods($classes, $p, $seen));
    }
    foreach ($c['traits'] as $t) {
        $methods = array_merge($methods, dcrMethods($classes, $t, $seen));
    }
    foreach ($c['methods'] as $k => $m) {
        $methods[$k] = $m + ['class' => $name];
    }

    return $methods;
}

/**
 * Get the public constants of a class with its parents resolved.
 *
 * @param   array<string, array<string, mixed>>  $classes
 * @param   array<string, true>                  $seen
 *
 * @return  array<string, array<string, mixed>>
 */
function dcrConsts(array $classes, string $name, array $seen = []): array
{
    if (!isset($classes[$name]) || isset($seen[$name])) {
        return [];
    }
    $seen[$name] = true;
    $consts      = [];
    foreach (array_merge($classes[$name]['parent'], $classes[$name]['traits']) as $p) {
        $consts = array_merge($consts, dcrConsts($classes, $p, $seen));
    }

    return array_merge($consts, $classes[$name]['consts']);
}

/**
 * Clean a deprecation note and find the replacement in it.
 *
 * @return  array{0: string, 1: string}  The note and the replacement
 */
function dcrReplace(string $note): array
{
    // Doxygen namespaces (Dotclear::Core::Foo) to PHP ones
    $note    = (string) preg_replace_callback('/\b[A-Z]\w*(?:::\w+){2,}(?:::\*)?/', fn ($m): string => str_replace('::', '\\', $m[0]), $note);
    $replace = '';
    if (preg_match('/\b(?:use|replaced by|see)\s+(.+)$/i', $note, $m)) {
        $replace = trim((string) preg_replace('/\s*(?:instead|instaed)\b.*$/i', '', rtrim(trim($m[1]), '.')));
    }

    return [$note, $replace];
}

/**
 * Get the public properties of a class with its parents resolved.
 *
 * @param   array<string, array<string, mixed>>  $classes
 * @param   array<string, true>                  $seen
 *
 * @return  array<string, array<string, mixed>>
 */
function dcrProps(array $classes, string $name, array $seen = []): array
{
    if (!isset($classes[$name]) || isset($seen[$name])) {
        return [];
    }
    $seen[$name] = true;
    $props       = [];
    foreach (array_merge($classes[$name]['parent'], $classes[$name]['traits']) as $p) {
        $props = array_merge($props, dcrProps($classes, $p, $seen));
    }

    return array_merge($props, $classes[$name]['props']);
}

/**
 * Get a comparable signature: parameters types and defaults, without names.
 *
 * @param   array<int, string>  $params
 */
function dcrSignature(array $params): string
{
    return implode(',', array_map(fn ($p): string => (string) preg_replace('/\$\w+/', '$', $p), $params));
}

// --- Read both trees

$rel_stable = dcrRelease($stable);
$rel_next   = dcrRelease($next);
$v_stable   = (string) ($rel_stable['release_version'] ?? '');
$v_next     = (string) ($rel_next['release_version'] ?? '');
$since      = dcrVersion($v_next);

$old = dcrParse($stable);
$new = dcrParse($next);

// Method names still known somewhere in the next tree (a removed method with a name still used elsewhere
// can only be found by its class, not by its name alone)
$known_methods = [];
foreach ($new['classes'] as $c) {
    foreach (array_keys($c['methods']) as $k) {
        $known_methods[$k] = true;
    }
}

$rules      = [];
$signatures = [];
$add        = function (array $rule) use (&$rules, $since): void {
    $rule = array_merge(['id' => '', 'type' => '', 'symbol' => '', 'since' => $since, 'level' => 'alert', 'replace' => '', 'note' => ''], $rule);
    $rules[$rule['id']] = $rule;
};

// --- Removed or changed in the next tree (alerts)

foreach ($old['classes'] as $name => $c) {
    if (!isset($new['classes'][$name])) {
        // The removed elements were often deprecated before: keep their replacement
        $removed = function (string $id, string $type, string $symbol, string $what, ?string $deprecated) use ($add): void {
            [$note, $replace] = dcrReplace($deprecated ?? '');
            $add([
                'id'      => $id,
                'type'    => $type,
                'symbol'  => $symbol,
                'replace' => $replace,
                'note'    => $what . ' removed' . ($deprecated !== null && $note !== '' ? ', was deprecated: ' . $note : ''),
            ]);
        };
        $removed('class:' . $name, 'class', $name, ucfirst($c['kind']), $c['deprecated']);
        foreach ($c['methods'] as $m) {
            if ($m['deprecated'] !== null) {
                $removed('method:' . $name . '::' . $m['name'], 'method', $name . '::' . $m['name'], 'Method', $m['deprecated']);
            }
        }
        foreach ($c['consts'] as $k => $x) {
            if ($x['deprecated'] !== null) {
                $removed('const:' . $name . '::' . $k, 'const', $name . '::' . $k, 'Constant', $x['deprecated']);
            }
        }
        foreach ($c['props'] as $k => $x) {
            if ($x['deprecated'] !== null) {
                $removed('property:' . $name . '::$' . $k, 'property', $name . '::$' . $k, 'Property', $x['deprecated']);
            }
        }

        continue;
    }
    $old_m = dcrMethods($old['classes'], $name);
    $new_m = dcrMethods($new['classes'], $name);
    foreach ($old_m as $k => $m) {
        // Report a method once, on the class that declared it
        if ($m['class'] !== $name) {
            continue;
        }
        if (!isset($new_m[$k])) {
            $add([
                'id'     => 'method:' . $name . '::' . $m['name'],
                'type'   => 'method',
                'symbol' => $name . '::' . $m['name'],
                'unique' => !isset($known_methods[$k]),
                'note'   => isset($new['classes'][$name]['private'][$k]) ? 'Method no longer public or protected' : 'Method removed',
            ]);
        } elseif ($new_m[$k]['required'] <= $m['required'] && dcrSignature($new_m[$k]['params']) !== dcrSignature($m['params'])) {
            $signatures[$name . '::' . $m['name']] = [$m['params'], $new_m[$k]['params']];
        } elseif ($new_m[$k]['required'] > $m['required']) {
            $add([
                'id'     => 'method:' . $name . '::' . $m['name'],
                'type'   => 'method',
                'symbol' => $name . '::' . $m['name'],
                'unique' => count(array_filter($new['classes'], fn ($x): bool => isset($x['methods'][$k]))) === 1,
                'note'   => 'More required parameters: (' . implode(', ', $new_m[$k]['params']) . ')',
            ]);
        }
    }
    $new_p = dcrProps($new['classes'], $name);
    foreach ($c['props'] as $k => $x) {
        if (!isset($new_p[$k])) {
            $add([
                'id'     => 'property:' . $name . '::$' . $k,
                'type'   => 'property',
                'symbol' => $name . '::$' . $k,
                'note'   => isset($new['classes'][$name]['private']['$' . $k]) ? 'Property no longer public' : 'Property removed',
            ]);
        }
    }
    $new_c = dcrConsts($new['classes'], $name);
    foreach ($c['consts'] as $k => $x) {
        if (!isset($new_c[$k])) {
            $add([
                'id'     => 'const:' . $name . '::' . $k,
                'type'   => 'const',
                'symbol' => $name . '::' . $k,
                'note'   => isset($new['classes'][$name]['private'][$k]) ? 'Constant no longer public' : 'Constant removed',
            ]);
        }
    }
}

foreach ($old['functions'] as $name => $f) {
    if (!isset($new['functions'][$name])) {
        $add([
            'id'     => 'function:' . $name,
            'type'   => 'function',
            'symbol' => $name,
            'note'   => 'Function removed (' . $f['file'] . ')',
        ]);
    } elseif ($new['functions'][$name]['required'] > $f['required']) {
        $add([
            'id'     => 'function:' . $name,
            'type'   => 'function',
            'symbol' => $name,
            'note'   => 'More required parameters: (' . implode(', ', $new['functions'][$name]['params']) . ')',
        ]);
    }
}

$old_b = dotclearBehaviors($stable);
$new_b = dotclearBehaviors($next);
foreach ($old_b as $name => $b) {
    if (!isset($new_b[$name])) {
        $add([
            'id'     => 'behavior:' . $name,
            'type'   => 'behavior',
            'symbol' => $name,
            'note'   => 'Behavior no longer called' . ($b['note'] !== '' ? ', deprecated since ' . $b['note'] : ''),
        ]);
    }
}

// --- Deprecated in the next tree (notices)

$notice = function (string $id, string $type, string $symbol, string $note) use (&$rules, $add): void {
    if (isset($rules[$id])) {
        return;
    }
    [$note, $replace] = dcrReplace($note);
    $add([
        'id'      => $id,
        'type'    => $type,
        'symbol'  => $symbol,
        'level'   => 'notice',
        'replace' => $replace,
        'note'    => 'Deprecated' . ($note !== '' ? ': ' . $note : ''),
    ]);
};

foreach ($new['classes'] as $name => $c) {
    if ($c['deprecated'] !== null) {
        $notice('class:' . $name, 'class', $name, $c['deprecated']);
    }
    foreach ($c['methods'] as $m) {
        if ($m['deprecated'] !== null) {
            $notice('method:' . $name . '::' . $m['name'], 'method', $name . '::' . $m['name'], $m['deprecated']);
            if (isset($rules['method:' . $name . '::' . $m['name']])) {
                $rules['method:' . $name . '::' . $m['name']]['unique'] = count(array_filter($new['classes'], fn ($x): bool => isset($x['methods'][strtolower($m['name'])]))) === 1;
            }
        }
    }
    foreach ($c['consts'] as $k => $x) {
        if ($x['deprecated'] !== null) {
            $notice('const:' . $name . '::' . $k, 'const', $name . '::' . $k, $x['deprecated']);
        }
    }
}
foreach ($new['functions'] as $name => $f) {
    if ($f['deprecated'] !== null) {
        $notice('function:' . $name, 'function', $name, $f['deprecated']);
    }
}
foreach ($new_b as $name => $b) {
    if ($b['deprecated']) {
        $notice('behavior:' . $name, 'behavior', $name, $b['note']);
    }
}

// Parameters changed without more required ones: breaks overrides, and calls in some cases
foreach ($signatures as $symbol => [$before, $after]) {
    $notice('method:' . $symbol, 'method', $symbol, '');
    $rules['method:' . $symbol]['note'] = 'Parameters changed, check calls and overrides: (' . implode(', ', $before) . ') → (' . implode(', ', $after) . ')';
}

// --- Manual rules

if ($manual !== '') {
    $json = json_decode((string) file_get_contents($manual), true);
    if (!is_array($json) || !is_array($json['rules'] ?? null)) {
        fwrite(STDERR, "Invalid manual rules file: $manual\n");
        exit(1);
    }
    foreach ($json['rules'] as $rule) {
        if (!is_array($rule) || empty($rule['id'])) {
            continue;
        }
        if (!empty($rule['disabled'])) {
            unset($rules[$rule['id']]);

            continue;
        }
        $add(array_merge($rules[$rule['id']] ?? [], $rule, ['manual' => true]));
    }
}

ksort($rules);

echo json_encode([
    'dotclear' => [
        'stable'  => $v_stable,
        'next'    => $v_next,
        'commit'  => dcrCommit($next),
        'php_min' => (string) ($rel_next['php_min'] ?? ''),
    ],
    'rules' => array_values($rules),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
