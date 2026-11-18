<?php

namespace Selective\ArchiveBomb\Test;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Selective\ArchiveBomb\Engine\PngBompEngine;
use Selective\ArchiveBomb\Engine\RarBombEngine;
use Selective\ArchiveBomb\Engine\ZipBombEngine;
use Selective\ArchiveBomb\Scanner\BombScanner;
use Selective\ArchiveBomb\Scanner\BombScannerResult;
use Selective\ArchiveBomb\Type\ArchiveType;
use Selective\ArchiveBomb\Type\ScanStatus;
use SplFileObject;
use SplTempFileObject;
use ZipArchive;

/**
 * Test.
 */
final class ArchiveBombScannerTest extends TestCase
{
    /**
     * Test.
     */
    public function testCreateInstance(): void
    {
        $this->assertInstanceOf(BombScanner::class, new BombScanner());
    }

    /**
     * Test.
     *
     * @dataProvider providerGetImageTypeFromFile
     *
     * @param string $filename The file
     * @param bool $expected The expected result
     */
    public function testScanFile(string $filename, bool $expected): void
    {
        $this->assertFileExists($filename);

        $scanner = new BombScanner();

        $scanner->addEngine(new ZipBombEngine());
        $scanner->addEngine(new PngBompEngine());
        $scanner->addEngine(new RarBombEngine());

        $file = new SplFileObject($filename);
        $actual = $scanner->scanFile($file);

        $this->assertSame($expected, $actual->isBomb());
        $this->assertTrue($actual->equals(new BombScannerResult($actual->isBomb())));

        // In memory scanning
        $tempFile = new SplTempFileObject();
        $tempFile->fwrite((string)file_get_contents($filename));
        $actual = $scanner->scanFile($file);

        $this->assertSame($expected, $actual->isBomb());
    }

    /**
     * Provider.
     *
     * @return array<mixed> The test data
     */
    public static function providerGetImageTypeFromFile(): array
    {
        $result = [];
        $zipBombFiles = [
            '10.zip',
            '20.zip',
            '30.zip',
            '34.zip',
        ];

        foreach (self::findFiles(__DIR__ . '/files/zip') as $file) {
            $result[] = [$file, in_array(basename($file), $zipBombFiles, true)];
        }

        foreach (self::findFiles(__DIR__ . '/files/zip-ok') as $file) {
            $result[] = [$file, false];
        }

        foreach (self::findFiles(__DIR__ . '/files/rar') as $file) {
            $result[] = [$file, true];
        }

        foreach (self::findFiles(__DIR__ . '/files/rar-ok') as $file) {
            $result[] = [$file, false];
        }

        foreach (self::findFiles(__DIR__ . '/files/png') as $file) {
            $result[] = [$file, true];
        }

        foreach (self::findFiles(__DIR__ . '/files/png-ok') as $file) {
            $result[] = [$file, false];
        }

        return $result;
    }

    /**
     * Find all files.
     *
     * @param string $path The path
     *
     * @return array<int, string> The files
     */
    private static function findFiles(string $path): array
    {
        $result = [];
        $files = glob(sprintf('%s/*', $path));

        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $result[] = (string)realpath($file);
        }

        return $result;
    }

    /**
     * Test.
     */
    public function testScanFileNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File not found: temp.zip');

        $scanner = new BombScanner();
        $scanner->addEngine(new ZipBombEngine());

        $filename = __DIR__ . '/temp.zip';
        touch($filename);

        $file = new SplFileObject($filename);

        unlink($filename);

        $scanner->scanFile($file);
    }

    /**
     * Test.
     */
    public function testScanFileWithMultipleZipEntries(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'zip-ok-');
        $this->assertIsString($filename);

        try {
            $zip = new ZipArchive();
            $result = $zip->open($filename, ZipArchive::OVERWRITE);
            $this->assertTrue($result === true);
            $this->assertTrue($zip->addFromString('one.txt', 'One'));
            $this->assertTrue($zip->addFromString('two.txt', 'Two'));
            $this->assertTrue($zip->close());

            $scanner = new BombScanner();
            $scanner->addEngine(new ZipBombEngine());

            $actual = $scanner->scanFile(new SplFileObject($filename));

            $this->assertFalse($actual->isBomb());
        } finally {
            if (file_exists($filename)) {
                unlink($filename);
            }
        }
    }

    /**
     * Test renamed and extension-less nested archives are detected by their
     * content magic numbers instead of their member names.
     */
    public function testRenamedNestedArchiveIsDetectedByMagic(): void
    {
        $fixture = __DIR__ . '/files/nested/disguised-nested.zip';
        $this->assertFileExists($fixture);

        $scanner = new BombScanner();
        $scanner->addEngine(new ZipBombEngine());

        $result = $scanner->scanFile(new SplFileObject($fixture), 'container-disguised');

        $this->assertTrue($result->isBomb());
        $this->assertSame(ArchiveType::ZIP, $result->getType()->getId());
        $this->assertSame(ScanStatus::ArchiveScanned, $result->getStatus());
        $this->assertSame('container-disguised', $result->getContainerId());

        $entries = $result->getNestedEntries();
        $names = array_map(fn($entry): string => $entry->getName(), $entries);

        $this->assertContains('bomb-disguised.txt', $names);
        $this->assertContains('bomb-noext', $names);
        $this->assertContains('clean-disguised.dat', $names);
        $this->assertNotContains('plain.txt', $names);

        foreach ($entries as $entry) {
            $this->assertSame(ArchiveType::ZIP, $entry->getType()->getId());
            $this->assertSame(ScanStatus::ArchiveScanned, $entry->getStatus());
            $this->assertSame('container-disguised', $entry->getContainerId());
        }
    }

    /**
     * Test a truncated nested member gets an explicit truncated status.
     */
    public function testTruncatedNestedMemberHasExplicitStatus(): void
    {
        $fixture = __DIR__ . '/files/nested/truncated-nested.zip';
        $this->assertFileExists($fixture);

        $scanner = new BombScanner();
        $scanner->addEngine(new ZipBombEngine());

        $result = $scanner->scanFile(new SplFileObject($fixture), 'container-truncated');

        $this->assertFalse($result->isBomb());

        $entry = $this->findNestedEntry($result->getNestedEntries(), 'cut.bin');

        $this->assertNotNull($entry);
        $this->assertSame(ArchiveType::ZIP, $entry->getType()->getId());
        $this->assertSame(ScanStatus::Truncated, $entry->getStatus());
        $this->assertSame('container-truncated', $entry->getContainerId());
    }

    /**
     * Test an unknown nested member with a faked name gets an explicit status.
     */
    public function testUnknownNestedMemberHasExplicitStatus(): void
    {
        $fixture = __DIR__ . '/files/nested/unknown-nested.zip';
        $this->assertFileExists($fixture);

        $scanner = new BombScanner();
        $scanner->addEngine(new ZipBombEngine());

        $result = $scanner->scanFile(new SplFileObject($fixture), 'container-unknown');

        $this->assertFalse($result->isBomb());

        $entry = $this->findNestedEntry($result->getNestedEntries(), 'fake.zip');

        $this->assertNotNull($entry);
        $this->assertSame(ArchiveType::UNKNOWN, $entry->getType()->getId());
        $this->assertSame(ScanStatus::UnknownFormat, $entry->getStatus());
    }

    /**
     * Test ordinary files are not treated as archives and keep their id.
     */
    public function testPlainFileIsUnaffected(): void
    {
        $fixture = __DIR__ . '/files/nested/plain-file.txt';
        $this->assertFileExists($fixture);

        $scanner = new BombScanner();
        $scanner->addEngine(new ZipBombEngine());

        $result = $scanner->scanFile(new SplFileObject($fixture), 'container-plain');

        $this->assertFalse($result->isBomb());
        $this->assertSame(ArchiveType::UNKNOWN, $result->getType()->getId());
        $this->assertSame(ScanStatus::RegularFile, $result->getStatus());
        $this->assertSame('container-plain', $result->getContainerId());
        $this->assertSame([], $result->getNestedEntries());
    }

    /**
     * Find a nested entry by its member name.
     *
     * @param array<int, \Selective\ArchiveBomb\Scanner\NestedArchiveEntry> $entries The entries
     * @param string $name The member name
     *
     * @return \Selective\ArchiveBomb\Scanner\NestedArchiveEntry|null The entry
     */
    private function findNestedEntry(array $entries, string $name): ?\Selective\ArchiveBomb\Scanner\NestedArchiveEntry
    {
        foreach ($entries as $entry) {
            if ($entry->getName() === $name) {
                return $entry;
            }
        }

        return null;
    }
}
