<?php

namespace Selective\ArchiveBomb\Test;

use PHPUnit\Framework\TestCase;
use Selective\ArchiveBomb\Engine\PngBompEngine;
use Selective\ArchiveBomb\Engine\Rar\RarEntryMetadata;
use Selective\ArchiveBomb\Engine\RarBombEngine;
use Selective\ArchiveBomb\Engine\ZipBombEngine;
use Selective\ArchiveBomb\Scanner\BombScanner;
use Selective\ArchiveBomb\Scanner\BombStatus;
use Selective\ArchiveBomb\Test\Fixture\Rar4ArchiveBuilder;
use SplFileObject;

/**
 * RAR bomb engine tri-state and evidence tests.
 */
final class RarBombEngineTest extends TestCase
{
    /**
     * Scan a binary RAR blob with the RAR engine.
     *
     * @param string $contents The archive contents
     *
     * @return \Selective\ArchiveBomb\Scanner\BombScannerResult The result
     */
    private function scanRar(string $contents): \Selective\ArchiveBomb\Scanner\BombScannerResult
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'rar-bomb');
        file_put_contents($path, $contents);

        try {
            return (new RarBombEngine())->scanFile(new SplFileObject($path));
        } finally {
            unlink($path);
        }
    }

    /**
     * Scan a binary blob with all engines registered.
     *
     * @param string $contents The archive contents
     *
     * @return \Selective\ArchiveBomb\Scanner\BombScannerResult The result
     */
    private function scanAllEngines(string $contents): \Selective\ArchiveBomb\Scanner\BombScannerResult
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'rar-bomb');
        file_put_contents($path, $contents);

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
     * Test that the real bomb fixture is detected through the shared budget.
     */
    public function testRealRarBombUsesSharedBudget(): void
    {
        $result = $this->scanRar((string)file_get_contents(__DIR__ . '/files/rar/10GB.rar'));

        $this->assertTrue($result->isBomb());
        $this->assertSame(BombStatus::Bomb, $result->getStatus());

        $bombEvidence = $result->getBombEvidence();
        $this->assertCount(1, $bombEvidence);
        $this->assertSame('rar:archive#total', $bombEvidence[0]->getIdentity());
        $this->assertSame(RarBombEngine::ENGINE_ID, $bombEvidence[0]->getEngineId());
    }

    /**
     * Test that a legitimate multi-entry archive stays safe.
     */
    public function testLegitimateMultiEntryArchiveIsSafe(): void
    {
        $blob = (new Rar4ArchiveBuilder())
            ->addFile('one.txt', 100, 120, 0, str_repeat('a', 100))
            ->addFile('two.txt', 200, 240, 0, str_repeat('b', 200))
            ->build();

        $result = $this->scanRar($blob);

        $this->assertTrue($result->isSafe());
        $this->assertFalse($result->isBomb());
        $this->assertFalse($result->isUnknown());
    }

    /**
     * Test that entries share one total expansion budget.
     */
    public function testEntriesShareTotalExpansionBudget(): void
    {
        $blob = (new Rar4ArchiveBuilder())
            ->addFile('part-a.bin', 100, 400000, 0, str_repeat('a', 100))
            ->addFile('part-b.bin', 100, 400000, 0, str_repeat('b', 100))
            ->build();

        $this->assertTrue($this->scanRar($blob)->isBomb());
    }

    /**
     * Test that an entry with zero packed size returns unknown.
     */
    public function testZeroPackedSizeReturnsUnknown(): void
    {
        $blob = (new Rar4ArchiveBuilder())
            ->addFile('zero.bin', 0, 1000, 0, '')
            ->build();

        $result = $this->scanRar($blob);

        $this->assertTrue($result->isUnknown());
        $this->assertSame('rar:entry#0:zero.bin', $result->getUnknownEvidence()[0]->getIdentity());
    }

    /**
     * Test that an encrypted entry returns unknown with evidence.
     */
    public function testEncryptedEntryReturnsUnknown(): void
    {
        $blob = (new Rar4ArchiveBuilder())
            ->addFile('secret.txt', 100, 1000, RarEntryMetadata::FLAG_ENCRYPTED, str_repeat('x', 100))
            ->build();

        $result = $this->scanRar($blob);

        $this->assertTrue($result->isUnknown());
        $this->assertSame('rar:entry#0:secret.txt', $result->getUnknownEvidence()[0]->getIdentity());
    }

    /**
     * Test that an entry crossing a volume boundary returns unknown.
     */
    public function testVolumeBoundaryEntryReturnsUnknown(): void
    {
        $flags = RarEntryMetadata::FLAG_CONTINUED_FROM_PREVIOUS_VOLUME
            | RarEntryMetadata::FLAG_CONTINUED_IN_NEXT_VOLUME;

        $blob = (new Rar4ArchiveBuilder())
            ->addFile('split.bin', 100, 5000, $flags, str_repeat('y', 100))
            ->build();

        $result = $this->scanRar($blob);

        $this->assertTrue($result->isUnknown());
        $this->assertSame('rar:entry#0:split.bin', $result->getUnknownEvidence()[0]->getIdentity());
    }

    /**
     * Test that a legitimate multi-volume archive with complete entries stays safe.
     */
    public function testLegitimateMultiVolumeArchiveIsSafe(): void
    {
        $blob = (new Rar4ArchiveBuilder())
            ->makeMultiVolume()
            ->addFile('volume/one.txt', 100, 120, 0, str_repeat('a', 100))
            ->addFile('volume/two.txt', 200, 240, 0, str_repeat('b', 200))
            ->build();

        $this->assertTrue($this->scanRar($blob)->isSafe());
    }

    /**
     * Test that damaged archives return unknown instead of crashing.
     */
    public function testDamagedArchiveReturnsUnknown(): void
    {
        $valid = (new Rar4ArchiveBuilder())
            ->addFile('damaged.bin', 100, 1000, 0, str_repeat('z', 100))
            ->build();

        $this->assertTrue($this->scanRar(substr($valid, 0, strlen($valid) - 30))->isUnknown());

        $corrupted = $valid;
        $corrupted[30] = chr(0x55);
        $this->assertTrue($this->scanRar($corrupted)->isUnknown());
    }

    /**
     * Test that a RAR5 archive cannot be misclassified as safe.
     */
    public function testRar5ReturnsUnknown(): void
    {
        $rar5 = "Rar!\x1a\x07\x01\x00" . str_repeat("\x00", 50);

        $this->assertTrue($this->scanRar($rar5)->isUnknown());
    }

    /**
     * Test that the multi-engine scanner keeps stable, engine namespaced identity.
     */
    public function testMultiEngineEvidenceIdentity(): void
    {
        $encrypted = (new Rar4ArchiveBuilder())
            ->addFile('secret.txt', 100, 1000, RarEntryMetadata::FLAG_ENCRYPTED, str_repeat('x', 100))
            ->build();

        $result = $this->scanAllEngines($encrypted);

        $this->assertTrue($result->isUnknown());
        $this->assertFalse($result->isBomb());
        $this->assertSame(
            'rar:entry#0:secret.txt',
            $result->getUnknownEvidence()[0]->getIdentity()
        );

        $firstIdentity = $this->scanRar($encrypted)->getEvidence()[0]->getIdentity();
        $secondIdentity = $this->scanRar($encrypted)->getEvidence()[0]->getIdentity();
        $this->assertSame($firstIdentity, $secondIdentity);
    }

    /**
     * Test that non RAR input is ignored by the engine.
     */
    public function testNonRarInputIsSafe(): void
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'not-rar');
        file_put_contents($path, 'plain text content');

        try {
            $result = (new RarBombEngine())->scanFile(new SplFileObject($path));
            $this->assertTrue($result->isSafe());
            $this->assertSame([], $result->getEvidence());
        } finally {
            unlink($path);
        }
    }
}
