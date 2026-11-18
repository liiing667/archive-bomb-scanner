<?php

namespace Selective\ArchiveBomb\Engine;

use Selective\ArchiveBomb\Scanner\BombScannerResult;
use SplFileObject;

/**
 * Engine.
 */
interface EngineInterface
{
    /**
     * Detect.
     *
     * @param SplFileObject $file The file
     * @param string $containerId The id of the uploaded container
     *
     * @return BombScannerResult The result
     */
    public function scanFile(SplFileObject $file, string $containerId = ''): BombScannerResult;
}
