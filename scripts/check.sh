#!/usr/bin/env bash
#
# RunPlan local checks. Run this before pushing; CI runs the same script.
#
#   ./scripts/check.sh
#
set -euo pipefail

cd "$(dirname "$0")/.."

PHP="${PHP_BIN:-php}"

if ! command -v "$PHP" >/dev/null 2>&1; then
  echo "php not found (set PHP_BIN=/path/to/php if it is not on PATH)" >&2
  exit 2
fi

echo "== $($PHP -v | head -1)"
echo
echo "== syntax"
"$PHP" -r '
  $out = [];
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(".", FilesystemIterator::SKIP_DOTS)
  );
  foreach ($it as $f) {
    $p = str_replace(DIRECTORY_SEPARATOR, "/", $f->getPathname());
    if (substr($p, -4) !== ".php") continue;
    foreach (["/.git/", "/vendor/", "/runtime/", "/node_modules/"] as $skip) {
      if (strpos($p, $skip) !== false) continue 2;
    }
    $out[] = $p;
  }
  sort($out);
  $bad = 0;
  foreach ($out as $file) {
    $src = file_get_contents($file);
    try {
      token_get_all($src, TOKEN_PARSE);
    } catch (ParseError $e) {
      fwrite(STDERR, "  FAIL  {$file}:{$e->getLine()}  {$e->getMessage()}\n");
      $bad++;
    }
  }
  if ($bad > 0) {
    fwrite(STDERR, "\n  {$bad} of " . count($out) . " files failed to parse\n");
    exit(1);
  }
  echo "  ok    " . count($out) . " files parse cleanly\n";
'

echo
echo "== tests"
"$PHP" tests/run.php

echo
echo "all checks passed"
