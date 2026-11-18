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
     * Get the stable identifier of the engine.
     *
     * The identifier is part of every evidence identity and must not change
     * between runs or depend on other registered engines.
     *
     * @return string The engine identifier
     */
    public function getId(): string;

    /**
     * Detect.
     *
     * @param SplFileObject $file The file
     *
     * @return BombScannerResult The result
     */
    public function scanFile(SplFileObject $file): BombScannerResult;
}
