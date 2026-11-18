<?php

/**
 * Verifiable runner for the renamed-extension nested archive fixtures.
 *
 * Usage:
 *   php tests/run.php
 *
 * Exit code is 0 only when every assertion passes.
 */

use Selective\ArchiveBomb\Engine\PngBompEngine;
use Selective\ArchiveBomb\Engine\RarBombEngine;
use Selective\ArchiveBomb\Engine\ZipBombEngine;
use Selective\ArchiveBomb\Scanner\BombScanner;
use Selective\ArchiveBomb\Scanner\BombScannerResult;
use Selective\ArchiveBomb\Scanner\NestedArchiveEntry;
use Selective\ArchiveBomb\Type\ArchiveType;
use Selective\ArchiveBomb\Type\ScanStatus;

require __DIR__ . '/../vendor/autoload.php';

$fixtureDir = __DIR__ . '/files/nested';

if (!is_dir($fixtureDir)) {
    fwrite(STDERR, "Missing fixtures, run: php tests/build_nested_fixtures.php\n");

    exit(2);
}

/** @var int $failures */
$failures = 0;

/** @var int $checks */
$checks = 0;

/**
 * Assert that two values are identical.
 *
 * @param string $label The assertion label
 * @param mixed $expected The expected value
 * @param mixed $actual The actual value
 *
 * @return void
 */
function assertSame(string $label, mixed $expected, mixed $actual): void
{
    global $checks, $failures;

    $checks++;

    if ($expected === $actual) {
        echo sprintf("[PASS] %s (%s)\n", $label, is_scalar($actual) ? (string)$actual : json_encode($actual));

        return;
    }

    $failures++;
    echo sprintf(
        "[FAIL] %s: expected %s, got %s\n",
        $label,
        json_encode($expected),
        json_encode($actual)
    );
}

/**
 * Build a scanner with all engines, mirroring the upload gateway wiring.
 *
 * @return BombScanner The scanner
 */
function createScanner(): BombScanner
{
    $scanner = new BombScanner();
    $scanner->addEngine(new ZipBombEngine());
    $scanner->addEngine(new PngBompEngine());
    $scanner->addEngine(new RarBombEngine());

    return $scanner;
}

/**
 * Scan a fixture with a stable container id.
 *
 * @param string $relativePath The fixture path relative to the nested dir
 * @param string $containerId The container id
 *
 * @return BombScannerResult The result
 */
function scanFixture(string $relativePath, string $containerId): BombScannerResult
{
    global $fixtureDir;

    $scanner = createScanner();

    return $scanner->scanFile(new SplFileObject($fixtureDir . '/' . $relativePath), $containerId);
}

/**
 * Find a nested member by its name.
 *
 * @param array<int, NestedArchiveEntry> $entries The entries
 * @param string $name The member name
 *
 * @return NestedArchiveEntry|null The entry
 */
function findEntry(array $entries, string $name): ?NestedArchiveEntry
{
    foreach ($entries as $entry) {
        if ($entry->getName() === $name) {
            return $entry;
        }
    }

    return null;
}

echo "--- Renamed extension nested archives ---\n";

$result = scanFixture('disguised-nested.zip', 'container-disguised');

assertSame('disguised container is detected as zip', ArchiveType::ZIP, $result->getType()->getId());
assertSame('disguised container status', ScanStatus::ArchiveScanned->value, $result->getStatus()->value);
assertSame('disguised container keeps container id', 'container-disguised', $result->getContainerId());
assertSame('disguised nested bomb is flagged', true, $result->isBomb());

$bombEntry = findEntry($result->getNestedEntries(), 'bomb-disguised.txt');
assertSame('renamed member still recognised by magic', true, $bombEntry !== null);

if ($bombEntry !== null) {
    assertSame('renamed member format', ArchiveType::ZIP, $bombEntry->getType()->getId());
    assertSame('renamed member status', ScanStatus::ArchiveScanned->value, $bombEntry->getStatus()->value);
    assertSame('renamed member keeps container id', 'container-disguised', $bombEntry->getContainerId());
}

$noExtEntry = findEntry($result->getNestedEntries(), 'bomb-noext');
assertSame('extension-less member recognised by magic', true, $noExtEntry !== null);

if ($noExtEntry !== null) {
    assertSame('extension-less member format', ArchiveType::ZIP, $noExtEntry->getType()->getId());
    assertSame('extension-less member status', ScanStatus::ArchiveScanned->value, $noExtEntry->getStatus()->value);
}

$cleanEntry = findEntry($result->getNestedEntries(), 'clean-disguised.dat');
assertSame('clean renamed member recognised', true, $cleanEntry !== null);

if ($cleanEntry !== null) {
    assertSame('clean renamed member format', ArchiveType::ZIP, $cleanEntry->getType()->getId());
    assertSame('clean renamed member status', ScanStatus::ArchiveScanned->value, $cleanEntry->getStatus()->value);
}

assertSame(
    'plain member is not reported as archive',
    null,
    findEntry($result->getNestedEntries(), 'plain.txt')
);

echo "--- Truncated nested member ---\n";

$result = scanFixture('truncated-nested.zip', 'container-truncated');

assertSame('truncated container keeps container id', 'container-truncated', $result->getContainerId());

$truncatedEntry = findEntry($result->getNestedEntries(), 'cut.bin');
assertSame('truncated member is detected', true, $truncatedEntry !== null);

if ($truncatedEntry !== null) {
    assertSame('truncated member format', ArchiveType::ZIP, $truncatedEntry->getType()->getId());
    assertSame('truncated member explicit status', ScanStatus::Truncated->value, $truncatedEntry->getStatus()->value);
}

echo "--- Unknown format nested member ---\n";

$result = scanFixture('unknown-nested.zip', 'container-unknown');

$unknownEntry = findEntry($result->getNestedEntries(), 'fake.zip');
assertSame('unknown member is detected', true, $unknownEntry !== null);

if ($unknownEntry !== null) {
    assertSame('unknown member format', ArchiveType::UNKNOWN, $unknownEntry->getType()->getId());
    assertSame('unknown member explicit status', ScanStatus::UnknownFormat->value, $unknownEntry->getStatus()->value);
    assertSame('unknown member keeps container id', 'container-unknown', $unknownEntry->getContainerId());
}

assertSame('unknown container is not a bomb', false, $result->isBomb());

echo "--- Ordinary file stays unaffected ---\n";

$result = scanFixture('plain-file.txt', 'container-plain');

assertSame('plain file not flagged as bomb', false, $result->isBomb());
assertSame('plain file format', ArchiveType::UNKNOWN, $result->getType()->getId());
assertSame('plain file status', ScanStatus::RegularFile->value, $result->getStatus()->value);
assertSame('plain file keeps container id', 'container-plain', $result->getContainerId());
assertSame('plain file has no nested entries', [], $result->getNestedEntries());

echo "--- Top-level truncated archive ---\n";

$topLevelTruncated = $fixtureDir . '/top-level-truncated.bin';
$innerBytes = (string)file_get_contents($fixtureDir . '/inner-bomb.zip');
file_put_contents($topLevelTruncated, substr($innerBytes, 0, 100));

$scanner = createScanner();
$result = $scanner->scanFile(new SplFileObject($topLevelTruncated), 'container-top-truncated');

assertSame('top-level truncated format', ArchiveType::ZIP, $result->getType()->getId());
assertSame('top-level truncated status', ScanStatus::Truncated->value, $result->getStatus()->value);
assertSame('top-level truncated keeps container id', 'container-top-truncated', $result->getContainerId());
assertSame('top-level truncated is not a bomb', false, $result->isBomb());

unlink($topLevelTruncated);

echo sprintf("\n%d checks, %d failure(s)\n", $checks, $failures);

if ($failures > 0) {
    exit(1);
}

exit(0);
