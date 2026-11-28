<?php

/**
 * Test of scripts/DotclearRulesScanner.php on a fake module (tests/scanner/module) with fixed rules (tests/scanner/rules.json).
 *
 * Usage: php tests/scanner/test.php [--update]
 *
 * Compares the findings with tests/scanner/expected.txt; --update rewrites it (check the diff before committing).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/scripts/DotclearRulesScanner.php';

$scanner = DotclearRulesScanner::fromFile(__DIR__ . '/rules.json', '2.40.1');
$report  = $scanner->scan(__DIR__ . '/module');
$lines   = [];
foreach ($report['findings'] as $f) {
    $lines[] = sprintf('%s:%d %s %s %s %s%s%s', $f['file'], $f['line'], $f['level'], $f['match'], $f['context'], $f['id'], $f['guarded'] ? ' guarded' : '', $f['forecast'] ? ' forecast ' . $f['since'] : '');
}
$lines[] = 'php 8.1.0: ' . ($scanner->checkPhp('8.1.0') === null ? 'ok' : 'alert');
$lines[] = 'php 8.4.0: ' . ($scanner->checkPhp('8.4.0') === null ? 'ok' : 'alert');
$got     = implode("\n", $lines) . "\n";

// Same module given in memory (as read from a zip): same findings, excluded directories skipped
$files = [];
$it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/module', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f instanceof SplFileInfo && $f->isFile()) {
        $files[str_replace('\\', '/', substr($f->getPathname(), strlen(__DIR__ . '/module') + 1))] = (string) file_get_contents($f->getPathname());
    }
}
$memory = $scanner->scanFiles($files);
if ($memory !== $report) {
    echo "Scanner test failed: scanFiles() and scan() differ\n";
    exit(1);
}

$file = __DIR__ . '/expected.txt';
if (in_array('--update', $argv, true)) {
    file_put_contents($file, $got);
    echo "Updated $file\n";
    exit(0);
}
$expected = is_file($file) ? (string) file_get_contents($file) : '';
if ($got !== $expected) {
    echo "Scanner test failed\n--- expected\n$expected--- got\n$got";
    exit(1);
}
echo 'Scanner test passed (' . count($report['findings']) . " findings)\n";
