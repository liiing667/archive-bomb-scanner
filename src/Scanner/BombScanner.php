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
        $evidence = [];
        $hasUnknown = false;

        foreach ($this->engines as $engine) {
            $result = $engine->scanFile($file);

            foreach ($result->getEvidence() as $entryEvidence) {
                $evidence[] = $entryEvidence;
            }

            if ($result->isBomb()) {
                // A confirmed bomb always wins, but keep the complete trail.
                return new BombScannerResult(BombStatus::Bomb, $evidence);
            }

            if ($result->isUnknown()) {
                $hasUnknown = true;
            }
        }

        if ($hasUnknown) {
            return new BombScannerResult(BombStatus::Unknown, $evidence);
        }

        return new BombScannerResult(BombStatus::Safe, $evidence);
    }
}
