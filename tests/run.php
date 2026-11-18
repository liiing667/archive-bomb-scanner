<?php

declare(strict_types = 1);

/**
 * Standalone acceptance test runner.
 *
 * Usage: php tests/run.php
 *
 * The script boots Composer's autoloader when available and exercises the
 * tri-state RAR scanning, the multi-engine evidence identity, the shared
 * expansion budget and the encrypted / damaged / multi-volume handling.
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoloader not found. Run 'composer install' first.\n");
    exit(2);
}

require $autoload;

use Selective\ArchiveBomb\Engine\PngBompEngine;
use Selective\ArchiveBomb\Engine\Rar\RarEntryMetadata;
use Selective\ArchiveBomb\Engine\RarBombEngine;
use Selective\ArchiveBomb\Engine\ZipBombEngine;
use Selective\ArchiveBomb\Scanner\BombEvidence;
use Selective\ArchiveBomb\Scanner\BombScanner;
use Selective\ArchiveBomb\Scanner\BombStatus;
use Selective\ArchiveBomb\Test\Fixture\Rar4ArchiveBuilder;

/**
 * Mutable counters for the standalone acceptance runner.
 */
final class AcceptanceCounters
{
    /**
     * @var int The number of failed checks
     */
    public static int $failures = 0;

    /**
     * @var int The number of executed checks
     */
    public static int $checks = 0;
}

/**
 * Assert that a condition holds and record the result.
 *
 * @param bool $condition The condition
 * @param string $message The assertion message
 */
function assertThat(bool $condition, string $message): void
{
    AcceptanceCounters::$checks++;

    if ($condition) {
        echo "  [ok] {$message}\n";

        return;
    }

    AcceptanceCounters::$failures++;
    echo "  [FAIL] {$message}\n";
}

/**
 * Write a blob to a temporary file and return its path.
 *
 * @param string $contents The contents
 *
 * @return string The file path
 */
function writeTemporaryFile(string $contents): string
{
    $path = (string)tempnam(sys_get_temp_dir(), 'bomb-scan');
    file_put_contents($path, $contents);

    return $path;
}

/**
 * Scan a binary blob with the RAR engine.
 *
 * @param string $contents The archive contents
 *
 * @return Selective\ArchiveBomb\Scanner\BombScannerResult The result
 */
function scanRar(string $contents): Selective\ArchiveBomb\Scanner\BombScannerResult
{
    $path = writeTemporaryFile($contents);

    try {
        return (new RarBombEngine())->scanFile(new SplFileObject($path));
    } finally {
        unlink($path);
    }
}

/**
 * Scan a binary blob with the full multi-engine scanner.
 *
 * @param string $contents The archive contents
 *
 * @return Selective\ArchiveBomb\Scanner\BombScannerResult The result
 */
function scanAllEngines(string $contents): Selective\ArchiveBomb\Scanner\BombScannerResult
{
    $path = writeTemporaryFile($contents);

    try {
        $scanner = new BombScanner();
        $scanner->addEngine(new ZipBombEngine());
        $scanner->addEngine(new PngBompEngine());
        $scanner->addEngine(new RarBombEngine());

        return $scanner->scanFile(new SplFileObject($path));
    } finally {
        unlink($path);
    }
}

/**
 * Find the first evidence matching a status and identity fragment.
 *
 * @param array<int, BombEvidence> $evidence The evidence
 * @param BombStatus $status The expected status
 * @param string $identityFragment The identity fragment
 *
 * @return BombEvidence|null The matching evidence
 */
function findEvidence(array $evidence, BombStatus $status, string $identityFragment): ?BombEvidence
{
    foreach ($evidence as $item) {
        if ($item->getStatus() === $status && str_contains($item->getIdentity(), $identityFragment)) {
            return $item;
        }
    }

    return null;
}

$fixtures = dirname(__DIR__) . '/tests/files';

echo "RAR engine tri-state and shared budget\n";

$bombResult = scanRar((string)file_get_contents($fixtures . '/rar/10GB.rar'));
assertThat($bombResult->isBomb(), 'real 10GB RAR bomb is detected');
assertThat($bombResult->getStatus() === BombStatus::Bomb, 'bomb result exposes the Bomb status');
$bombEvidence = findEvidence($bombResult->getEvidence(), BombStatus::Bomb, 'archive#total');
assertThat($bombEvidence !== null, 'bomb is reported through the shared archive#total budget evidence');
assertThat(
    $bombEvidence !== null && $bombEvidence->getEngineId() === RarBombEngine::ENGINE_ID,
    'bomb evidence carries the stable rar engine identity'
);

$cleanResult = scanRar((string)file_get_contents($fixtures . '/rar-ok/test.rar'));
assertThat($cleanResult->isSafe(), 'legitimate multi-entry RAR stays safe');
assertThat(!$cleanResult->isBomb() && !$cleanResult->isUnknown(), 'clean RAR is neither bomb nor unknown');

echo "\nShared total expansion budget across entries\n";

// Two entries that are individually below the ratio but cross the budget together.
$collectiveBomb = (new Rar4ArchiveBuilder())
    ->addFile('part-a.bin', 100, 400000, 0, str_repeat('a', 100))
    ->addFile('part-b.bin', 100, 400000, 0, str_repeat('b', 100))
    ->build();
$collectiveResult = scanRar($collectiveBomb);
assertThat(
    $collectiveResult->isBomb(),
    'entries that are safe in isolation trigger the bomb through the shared total budget'
);

$collectiveSafe = (new Rar4ArchiveBuilder())
    ->addFile('one.txt', 100, 120, 0, str_repeat('a', 100))
    ->addFile('two.txt', 200, 240, 0, str_repeat('b', 200))
    ->build();
assertThat(scanRar($collectiveSafe)->isSafe(), 'low ratio multi-entry archive stays safe');

echo "\nZero packed size handling\n";

$zeroPacked = (new Rar4ArchiveBuilder())
    ->addFile('zero.bin', 0, 1000, 0, '')
    ->build();
$zeroResult = scanRar($zeroPacked);
assertThat($zeroResult->isUnknown(), 'entry with zero packed size returns unknown');
assertThat(
    findEvidence($zeroResult->getEvidence(), BombStatus::Unknown, 'entry#0:zero.bin') !== null,
    'zero packed size is attributed to the concrete entry identity'
);

echo "\nEncrypted entry handling\n";

$encrypted = (new Rar4ArchiveBuilder())
    ->addFile('secret.txt', 100, 1000, RarEntryMetadata::FLAG_ENCRYPTED, str_repeat('x', 100))
    ->build();
$encryptedResult = scanRar($encrypted);
assertThat($encryptedResult->isUnknown(), 'encrypted entry returns unknown instead of safe');
assertThat(
    findEvidence($encryptedResult->getEvidence(), BombStatus::Unknown, 'entry#0:secret.txt') !== null,
    'encrypted entry is reported with its stable identity'
);

echo "\nVolume boundary handling\n";

$splitEntry = (new Rar4ArchiveBuilder())
    ->addFile(
        'split.bin',
        100,
        5000,
        RarEntryMetadata::FLAG_CONTINUED_FROM_PREVIOUS_VOLUME | RarEntryMetadata::FLAG_CONTINUED_IN_NEXT_VOLUME,
        str_repeat('y', 100)
    )
    ->build();
$splitResult = scanRar($splitEntry);
assertThat($splitResult->isUnknown(), 'entry crossing a volume boundary returns unknown');
assertThat(
    findEvidence($splitResult->getEvidence(), BombStatus::Unknown, 'entry#0:split.bin') !== null,
    'split entry is reported with its stable identity'
);

$legitimateVolume = (new Rar4ArchiveBuilder())
    ->makeMultiVolume()
    ->addFile('volume/one.txt', 100, 120, 0, str_repeat('a', 100))
    ->addFile('volume/two.txt', 200, 240, 0, str_repeat('b', 200))
    ->build();
assertThat(
    scanRar($legitimateVolume)->isSafe(),
    'legitimate multi-volume archive with complete entries stays safe'
);

echo "\nDamaged volume handling\n";

$validArchive = (new Rar4ArchiveBuilder())
    ->addFile('damaged.bin', 100, 1000, 0, str_repeat('z', 100))
    ->build();
$truncatedResult = scanRar(substr($validArchive, 0, strlen($validArchive) - 30));
assertThat($truncatedResult->isUnknown(), 'truncated archive with a broken boundary returns unknown');

$corruptedArchive = $validArchive;
$corruptedArchive[30] = chr(0x55);
assertThat(scanRar($corruptedArchive)->isUnknown(), 'corrupted RAR header returns unknown');

$rar5 = "Rar!\x1a\x07\x01\x00" . str_repeat("\x00", 50);
assertThat(scanRar($rar5)->isUnknown(), 'RAR5 archive that cannot be fully inspected returns unknown');

echo "\nStable multi-engine evidence identity\n";

$multiResult = scanAllEngines((string)file_get_contents($fixtures . '/rar/10GB.rar'));
assertThat($multiResult->isBomb(), 'multi-engine scanner detects the RAR bomb');
$rarBombEvidence = findEvidence($multiResult->getEvidence(), BombStatus::Bomb, 'rar:');
assertThat($rarBombEvidence !== null, 'the dangerous engine is identifiable as rar in the evidence');
assertThat(
    $rarBombEvidence !== null && str_starts_with($rarBombEvidence->getIdentity(), 'rar:entry') === false
        && str_starts_with($rarBombEvidence->getIdentity(), 'rar:archive#total'),
    'the evidence identity is deterministic and engine namespaced'
);

$multiUnknown = scanAllEngines($encrypted);
assertThat($multiUnknown->isUnknown(), 'multi-engine scanner propagates the unknown verdict');
assertThat($multiUnknown->isBomb() === false, 'unknown verdict is never reported as a bomb');
assertThat(
    findEvidence($multiUnknown->getUnknownEvidence(), BombStatus::Unknown, 'rar:entry#0:secret.txt') !== null,
    'unknown evidence keeps the rar engine and entry identity across engines'
);

// Identity stability: scanning twice yields identical identities.
$firstRun = scanRar($encrypted)->getEvidence();
$secondRun = scanRar($encrypted)->getEvidence();
assertThat(
    $firstRun[0]->getIdentity() === $secondRun[0]->getIdentity()
        && $firstRun[0]->equals($secondRun[0]),
    'evidence identity is stable across independent scans'
);

echo "\nResult value object behaviour\n";

$safeStatusResult = scanRar($collectiveSafe);
assertThat(
    $safeStatusResult->equals(new Selective\ArchiveBomb\Scanner\BombScannerResult(BombStatus::Safe)),
    'result equality works on the tri-state status'
);
assertThat((new RarBombEngine())->getId() === 'rar', 'RAR engine exposes a stable identifier');
assertThat((new ZipBombEngine())->getId() === 'zip', 'ZIP engine exposes a stable identifier');
assertThat((new PngBompEngine())->getId() === 'png', 'PNG engine exposes a stable identifier');

echo "\n";

if (AcceptanceCounters::$failures > 0) {
    echo sprintf("FAILED: %d of %d checks failed.\n", AcceptanceCounters::$failures, AcceptanceCounters::$checks);
    exit(1);
}

echo sprintf("OK: %d checks passed.\n", AcceptanceCounters::$checks);
exit(0);
