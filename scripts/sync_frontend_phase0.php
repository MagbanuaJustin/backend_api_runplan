#!/usr/bin/env php
<?php
/**
 * Syncs the verified Phase 0 React frontend updates into the sibling
 * `runplan_frontend` workspace (e.g. D:\laragon\www\runplan_frontend).
 *
 * Usage (from runplan_lavalust_api):
 *   php scripts/sync_frontend_phase0.php
 *   php scripts/sync_frontend_phase0.php "D:\path\to\runplan_frontend"
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from the command line.\n");
    exit(2);
}

$repo_root   = dirname(__DIR__);
$source_root = $repo_root . DIRECTORY_SEPARATOR . 'frontend-sync' . DIRECTORY_SEPARATOR . 'phase0';
$target_root = isset($argv[1]) && trim($argv[1]) !== ''
    ? rtrim($argv[1], "/\\")
    : dirname($repo_root) . DIRECTORY_SEPARATOR . 'runplan_frontend';

if (!is_dir($source_root . DIRECTORY_SEPARATOR . 'src')) {
    fwrite(STDERR, "Frontend Phase 0 bundle not found at: {$source_root}\n");
    exit(1);
}

if (!is_dir($target_root . DIRECTORY_SEPARATOR . 'src') || !is_file($target_root . DIRECTORY_SEPARATOR . 'package.json')) {
    fwrite(STDERR, "Could not find runplan_frontend at: {$target_root}\n");
    fwrite(STDERR, "Pass the folder path explicitly, e.g.:\n");
    fwrite(STDERR, "  php scripts/sync_frontend_phase0.php \"D:\\laragon\\www\\runplan_frontend\"\n");
    exit(1);
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source_root, FilesystemIterator::SKIP_DOTS)
);

$copied = array();
foreach ($it as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $rel  = substr($file->getPathname(), strlen($source_root) + 1);
    $dest = $target_root . DIRECTORY_SEPARATOR . $rel;
    $dir  = dirname($dest);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Failed to create directory: {$dir}\n");
        exit(1);
    }
    if (!copy($file->getPathname(), $dest)) {
        fwrite(STDERR, "Failed to write file: {$dest}\n");
        exit(1);
    }
    $copied[] = str_replace('\\', '/', $rel);
}

sort($copied);
echo "Synced " . count($copied) . " Phase 0 frontend files into {$target_root}:\n";
foreach ($copied as $rel_path) {
    echo "  updated  {$rel_path}\n";
}
echo "\nDone! Refresh http://localhost:5173 (Vite will hot-reload automatically).\n";
