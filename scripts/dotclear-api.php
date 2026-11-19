<?php

/**
 * Dump the public API of a Dotclear tree: classes, interfaces, traits and enums of `src/` and `plugins/<id>/src/`,
 * their public and protected methods with signatures, and their public constants. One sorted line per symbol.
 *
 * Usage: php scripts/dotclear-api.php /path/to/dotclear > api.txt
 * Compare two versions: diff api-2.39.3.txt api-2.40.1.txt
 *
 * Text parsing only (no code of the tree is run), good enough to spot API changes, not a full parser.
 */
declare(strict_types=1);

$root = rtrim($argv[1] ?? '', '/');
if ($root === '' || !is_dir($root . '/src')) {
    fwrite(STDERR, "Usage: php dotclear-api.php /path/to/dotclear\n");
    exit(1);
}
$dirs = ['src'];
foreach (glob($root . '/plugins/*/src') as $d) {
    $dirs[] = substr($d, strlen($root) + 1);
}
$out = [];
foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!str_ends_with((string) $f, '.php')) continue;
        $code = file_get_contents((string) $f);
        $ns = preg_match('/^namespace\s+([^;]+);/m', $code, $m) ? $m[1] : '';
        if (!preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(class|interface|trait|enum)\s+(\w+)([^{]*)/m', $code, $m)) continue;
        $cls = $ns . '\\' . $m[2];
        $out[] = $cls . ' [' . $m[1] . trim(preg_replace('/\s+/', ' ', $m[3])) . ']';
        preg_match_all('/^\s*((?:(?:final|abstract|public|protected|static)\s+)*)function\s+(\w+)\s*\(((?:[^()]|\((?:[^()]|\([^()]*\))*\))*)\)\s*(?::\s*([^{;]+))?/m', $code, $mm, PREG_SET_ORDER);
        foreach ($mm as $x) {
            $mods = trim($x[1]);
            if (str_contains($mods, 'private')) continue;
            if ($m[1] !== 'interface' && !str_contains($mods, 'public') && !str_contains($mods, 'protected') && $mods !== '' && !str_contains($mods,'static')) continue;
            if (preg_match('/\bprivate\b/', $x[0])) continue;
            $args = preg_replace('/\s+/', ' ', trim($x[3]));
            $args = preg_replace('#/\*.*?\*/#', '', $args);
            $out[] = $cls . '::' . $x[2] . '(' . $args . ')' . (isset($x[4]) ? ': ' . trim($x[4]) : '') . ($mods ? ' {' . $mods . '}' : '');
        }
        preg_match_all('/^\s*(?:final\s+)?public\s+const\s+(?:\w+\s+)?(\w+)\s*=/m', $code, $cc);
        foreach ($cc[1] as $c) $out[] = $cls . '::' . $c . ' [const]';
    }
}
sort($out);
echo implode("\n", array_unique($out)), "\n";
