<?php

/**
 * Check Dotclear modules against the compatibility rules of the next Dotclear version.
 *
 * Usage: php scripts/dotclear-scan.php [options] <module directory> [<module directory>...]
 *
 * Options:
 *   --rules=<path or URL>  rules file (default: dotclear/rules.json next to this script, else the online one)
 *   --version=<x.y.z>      current Dotclear version: rules already applied to it are skipped
 *   --php=<x.y.z>          also check this PHP version against the minimum PHP version of the next Dotclear version
 *   --json                 JSON output (one report per module)
 *   --strict               exit code 1 when an alert is found (for a CI)
 *
 * See dotclear/RULES.md and scripts/DotclearRulesScanner.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/DotclearRulesScanner.php';

$options = ['rules' => '', 'version' => '', 'php' => '', 'json' => false, 'strict' => false];
$dirs    = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(rules|version|php)=(.*)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    } elseif ($arg === '--json' || $arg === '--strict') {
        $options[substr($arg, 2)] = true;
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option $arg\n");
        exit(2);
    } else {
        $dirs[] = $arg;
    }
}
if ($dirs === []) {
    fwrite(STDERR, "Usage: php dotclear-scan.php [--rules=<file>] [--version=<x.y.z>] [--php=<x.y.z>] [--json] [--strict] <module directory>...\n");
    exit(2);
}
if ($options['rules'] === '') {
    $local            = dirname(__DIR__) . '/dotclear/rules.json';
    $options['rules'] = is_file($local) ? $local : 'https://raw.githubusercontent.com/JcDenis/gh2da/master/dotclear/rules.json';
}

try {
    $scanner = DotclearRulesScanner::fromFile($options['rules'], $options['version']);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

$header  = $scanner->header();
$reports = [];
$alerts  = 0;
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        fwrite(STDERR, "Not a directory: $dir\n");

        continue;
    }
    $report = $scanner->scan($dir);
    $alerts += $report['alerts'];
    $reports[basename(rtrim($dir, '/'))] = $report;
}
$php = $options['php'] !== '' ? $scanner->checkPhp($options['php']) : null;
if ($php !== null) {
    $alerts++;
}

if ($options['json']) {
    echo json_encode(['dotclear' => $header, 'php' => $php, 'modules' => $reports], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
} else {
    echo 'Dotclear ', $header['stable'] ?? '?', ' → ', $header['next'] ?? '?', ($header['commit'] ?? '') !== '' ? ' (' . $header['commit'] . ')' : '', "\n";
    if ($php !== null) {
        echo 'ALERT  ', $php['note'], "\n";
    }
    foreach ($reports as $module => $report) {
        echo "\n", $module, ': ', strtoupper($report['level']), ' (', $report['alerts'], ' alerts, ', $report['notices'], ' notices, ', $report['files'], " files)\n";
        foreach ($report['findings'] as $f) {
            echo '  ', str_pad(strtoupper($f['level']), 6), ' ', $f['file'], ':', $f['line'], ' ', $f['symbol'],
                $f['match'] === 'probable' ? ' (probable)' : '',
                $f['guarded'] ? ' (already checked by the module?)' : '',
                "\n         ", $f['note'],
                $f['replace'] !== '' ? "\n         → " . $f['replace'] : '',
                "\n";
        }
    }
}

exit($options['strict'] && $alerts > 0 ? 1 : 0);
