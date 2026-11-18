<?php

namespace Selective\ArchiveBomb\Scanner;

use Selective\ArchiveBomb\Engine\EngineInterface;
use SplFileObject;

/**
 * Bomb scanner.
 */
final class BombScanner
{
    /**
     * @var EngineInterface[]
     */
    private $engines = [];

    /**
     * Add scanner engine.
     *
     * @param EngineInterface $engine The scanner engine
     */
    public function addEngine(EngineInterface $engine): void
    {
        $this->engines[] = $engine;
    }

    /**
     * Scan archive file.
     *
     * @param SplFileObject $file The archive file
     *
     * @return BombScannerResult The scanning result
     */
    public function scanFile(SplFileObject $file): BombScannerResult
    {
        $results = [];

        foreach ($this->engines as $engines) {
            $result = $engines->scanFile($file);

            if ($result->isBomb()) {
                return $result;
            }

            $results[] = $result;
        }

        // An inconclusive (unknown) result must not be silently reported as safe.
        foreach ($results as $result) {
            if ($result->isUnknown()) {
                return $result;
            }
        }

        return $results[0] ?? new BombScannerResult(false);
    }
}
