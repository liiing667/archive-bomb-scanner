<?php

/**
 * Acceptance tests with synthetic ZIP archives.
 *
 * Usage: php tests/run.php
 */

ini_set('memory_limit', '512M');

require __DIR__ . '/../src/Scanner/BombScannerResult.php';
require __DIR__ . '/../src/Scanner/BombScanner.php';
require __DIR__ . '/../src/Engine/EngineInterface.php';
require __DIR__ . '/../src/Engine/ZipBombEngine.php';

use Selective\ArchiveBomb\Engine\ZipBombEngine;
use Selective\ArchiveBomb\Scanner\BombScanner;
use Selective\ArchiveBomb\Scanner\BombScannerResult;

/**
 * Assert a condition.
 *
 * @param string $name The test name
 * @param bool $condition The condition
 */
function check(string $name, bool $condition): void
{
    if ($condition) {
        echo "PASS: {$name}\n";
    } else {
        $GLOBALS['failures'] = (int)($GLOBALS['failures'] ?? 0) + 1;

        echo "FAIL: {$name}\n";
    }
}

/**
 * Build a synthetic ZIP archive.
 *
 * @param array<int, array<string, int|string>> $entries The entries
 *
 * @return string The ZIP archive contents
 */
function buildZip(array $entries): string
{
    $contents = '';
    $centralDirectory = '';

    foreach ($entries as $entry) {
        $name = (string)$entry['name'];
        $data = (string)$entry['data'];
        $method = (int)($entry['method'] ?? 0);
        $flags = (int)($entry['flags'] ?? 0);
        $payload = $method === 8 ? (string)gzdeflate($data) : $data;
        $crc = crc32($data);
        $offset = strlen($contents);

        $contents .= "PK\3\4" . pack(
            'vvvvvVVVvv',
            20,
            $flags,
            $method,
            0,
            0,
            $crc,
            strlen($payload),
            strlen($data),
            strlen($name),
            0
        ) . $name . $payload;

        $centralDirectory .= "PK\1\2" . pack(
            'vvvvvvVVVvvvvvVV',
            20,
            20,
            $flags,
            $method,
            0,
            0,
            $crc,
            strlen($payload),
            strlen($data),
            strlen($name),
            0,
            0,
            0,
            0,
            0,
            $offset
        ) . $name;
    }

    $contents .= $centralDirectory;
    $contents .= "PK\5\6" . pack(
        'vvvvVVv',
        0,
        0,
        count($entries),
        count($entries),
        strlen($centralDirectory),
        strlen($contents) - strlen($centralDirectory),
        0
    );

    return $contents;
}

/**
 * Write contents to a temporary file.
 *
 * @param string $contents The file contents
 *
 * @return SplFileObject The file
 */
function tempFile(string $contents): SplFileObject
{
    static $paths = [];
    static $registered = false;

    $path = tempnam(sys_get_temp_dir(), 'abs-');

    if ($path === false) {
        throw new RuntimeException('Unable to create temp file');
    }

    file_put_contents($path, $contents);
    $paths[] = $path;

    if (!$registered) {
        $registered = true;

        register_shutdown_function(static function () use (&$paths): void {
            foreach ($paths as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
        });
    }

    return new SplFileObject($path);
}

/**
 * Get the number of cached nested archive sizes of an engine.
 *
 * @param ZipBombEngine $engine The engine
 *
 * @return int The cache size
 */
function cacheSize(ZipBombEngine $engine): int
{
    $property = new ReflectionProperty(ZipBombEngine::class, 'nestedArchiveSizes');
    $property->setAccessible(true);

    return count($property->getValue($engine));
}

// 1. Clean ZIP archive
$engine = new ZipBombEngine(100);
$clean = buildZip([
    ['name' => 'readme.txt', 'data' => str_repeat('hello world ', 100), 'method' => 8],
]);
$result = $engine->scanFile(tempFile($clean));
check('clean zip is not a bomb', !$result->isBomb());
check('clean zip status is safe', $result->getStatus() === BombScannerResult::STATUS_SAFE && $result->isSafe());
check('clean zip keeps engine name', $result->getEngineName() === 'zip');
check('clean zip keeps reason', $result->getReason() !== '');

// 2. Flat ZIP bomb
$flatBomb = buildZip([
    ['name' => 'zeros.bin', 'data' => str_repeat("\0", 2000000), 'method' => 8],
]);
$result = $engine->scanFile(tempFile($flatBomb));
check('flat zip bomb is detected', $result->isBomb());
check('flat zip bomb status is bomb', $result->getStatus() === BombScannerResult::STATUS_BOMB);
check('flat zip bomb keeps engine name', $result->getEngineName() === 'zip');
check('flat zip bomb keeps reason', str_contains($result->getReason(), 'ratio'));

// 3. Nested ZIP bomb scanned with the same engine instance under different budgets.
// The nested archive is identical in both files, but the budgets (file size * maxRatio)
// differ. A stale cache entry from the small-budget scan must not be reused.
$nested = buildZip([
    ['name' => 'pad.bin', 'data' => str_repeat("\0", 5000000), 'method' => 0],
    ['name' => 'zeros.bin', 'data' => str_repeat("\0", 60000000), 'method' => 8],
]);
$smallBudget = buildZip([
    ['name' => 'nested.zip', 'data' => $nested, 'method' => 8],
]);
$largeBudget = buildZip([
    ['name' => 'nested.zip', 'data' => $nested, 'method' => 0],
]);

$engine = new ZipBombEngine(100);
$result = $engine->scanFile(tempFile($smallBudget));
check('nested zip bomb with small budget is detected', $result->isBomb());
check('scan populated the cache', cacheSize($engine) > 0);

$result = $engine->scanFile(tempFile($largeBudget));
check('nested zip bomb with larger budget is still detected (no stale cache)', $result->isBomb());

$result = $engine->scanFile(tempFile($largeBudget));
check('repeated scan of the same file is still detected', $result->isBomb());

// 4. The cache is reset for every scan
$engine->scanFile(tempFile($clean));
check('cache is reset per scan', cacheSize($engine) === 0);

// 5. The cache has a hard upper bound
$entries = [];

for ($i = 0; $i < 1100; $i++) {
    $entries[] = [
        'name' => "nested-{$i}.zip",
        'data' => buildZip([['name' => "file-{$i}.txt", 'data' => "content-{$i}", 'method' => 0]]),
        'method' => 0,
    ];
}

$engine = new ZipBombEngine(100);
$result = $engine->scanFile(tempFile(buildZip($entries)));
check('archive with many nested zips is not a bomb', !$result->isBomb());
check('cache has a hard upper bound', cacheSize($engine) <= 1000);

// 6. Encrypted entries are not silently reported as safe
$encrypted = buildZip([
    ['name' => 'secret.zip', 'data' => 'encrypted-content', 'method' => 8, 'flags' => 1],
]);
$engine = new ZipBombEngine(100);
$result = $engine->scanFile(tempFile($encrypted));
check('encrypted entry is not a bomb', !$result->isBomb());
check('encrypted entry status is unknown', $result->getStatus() === BombScannerResult::STATUS_UNKNOWN);
check('encrypted entry is not safe', !$result->isSafe());

// 7. Corrupted entries are not silently reported as safe
$corruptedNested = buildZip([
    ['name' => 'broken.zip', 'data' => "PK\3\4" . random_bytes(64), 'method' => 0],
]);
$result = $engine->scanFile(tempFile($corruptedNested));
check('corrupted nested zip is not a bomb', !$result->isBomb());
check('corrupted nested zip status is unknown', $result->getStatus() === BombScannerResult::STATUS_UNKNOWN);

$result = $engine->scanFile(tempFile("PK\3\4" . random_bytes(64)));
check('corrupted archive status is unknown', $result->getStatus() === BombScannerResult::STATUS_UNKNOWN);

// 8. The scanner keeps engine, reason and status
$scanner = new BombScanner();
$scanner->addEngine(new ZipBombEngine(100));

$result = $scanner->scanFile(tempFile($encrypted));
check('scanner propagates unknown status', $result->getStatus() === BombScannerResult::STATUS_UNKNOWN);
check('scanner keeps engine name', $result->getEngineName() === 'zip');
check('scanner keeps reason', $result->getReason() !== '');

$result = $scanner->scanFile(tempFile($flatBomb));
check('scanner detects bomb', $result->isBomb());
check('scanner keeps bomb engine name', $result->getEngineName() === 'zip');

$result = $scanner->scanFile(tempFile($clean));
check('scanner reports clean file as safe', $result->isSafe() && !$result->isBomb());

$failures = (int)($GLOBALS['failures'] ?? 0);

if ($failures > 0) {
    echo "\n" . $failures . " test(s) failed\n";
    exit(1);
}

echo "\nAll tests passed\n";
