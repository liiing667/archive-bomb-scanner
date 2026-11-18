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
     * @param string $containerId The id of the uploaded container
     *
     * @return BombScannerResult The scanning result
     */
    public function scanFile(SplFileObject $file, string $containerId = ''): BombScannerResult
    {
        $fallback = new BombScannerResult(false, $containerId);

        foreach ($this->engines as $engines) {
            $result = $engines->scanFile($file, $containerId);

            if ($result->isBomb()) {
                return $result;
            }

            if (!$result->getType()->isUnknown()) {
                $fallback = $result;
            }
        }

        return $fallback;
    }
}
