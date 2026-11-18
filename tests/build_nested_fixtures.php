<?php

/**
 * Builds the nested-archive fixtures used by tests/run.php and the PHPUnit
 * suite. Run: php tests/build_nested_fixtures.php
 */

$dir = __DIR__ . '/files/nested';

if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException(sprintf('Cannot create fixture directory: %s', $dir));
}

// Inner archive: a zip with a very compressible large payload (bomb-like).
$innerZipPath = $dir . '/inner-bomb.zip';
$zip = new ZipArchive();

if ($zip->open($innerZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create inner bomb fixture.');
}

$zip->addFromString('payload.bin', str_repeat('A', 2_000_000));
$zip->close();
$innerZipBytes = (string)file_get_contents($innerZipPath);

// Clean inner zip, small content.
$innerCleanPath = $dir . '/inner-clean.zip';
$zip = new ZipArchive();

if ($zip->open($innerCleanPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create inner clean fixture.');
}

$zip->addFromString('readme.txt', 'hello world');
$zip->close();
$innerCleanBytes = (string)file_get_contents($innerCleanPath);

// Outer container: inner zips with faked / missing extensions + regular file.
$disguisedPath = $dir . '/disguised-nested.zip';
$zip = new ZipArchive();

if ($zip->open($disguisedPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create disguised fixture.');
}

$zip->addFromString('bomb-disguised.txt', $innerZipBytes);
$zip->addFromString('bomb-noext', $innerZipBytes);
$zip->addFromString('clean-disguised.dat', $innerCleanBytes);
$zip->addFromString('plain.txt', 'just a regular file');
$zip->close();

// Truncated nested member: ZIP signature but cut before the central directory.
$truncatedPath = $dir . '/truncated-nested.zip';
$zip = new ZipArchive();

if ($zip->open($truncatedPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create truncated fixture.');
}

$zip->addFromString('cut.bin', substr($innerZipBytes, 0, 60));
$zip->addFromString('plain.txt', 'plain content');
$zip->close();

// Unknown format nested member with a faked .zip name.
$unknownPath = $dir . '/unknown-nested.zip';
$zip = new ZipArchive();

if ($zip->open($unknownPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create unknown fixture.');
}

$zip->addFromString('fake.zip', 'this is definitely not a zip archive');
$zip->addFromString('plain.txt', 'plain content');
$zip->close();

// Plain file only (ordinary upload, not affected).
file_put_contents($dir . '/plain-file.txt', 'ordinary file without archive magic');

echo sprintf('Fixtures written to %s%s', $dir, PHP_EOL);
