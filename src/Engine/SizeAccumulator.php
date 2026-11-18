<?php

namespace Selective\ArchiveBomb\Engine;

/**
 * Mutable running total of verified expansion across all recursion levels.
 */
final class SizeAccumulator
{
    /**
     * @var float The accumulated expanded size
     */
    private float $total = 0.0;

    /**
     * Add a measured entry size.
     *
     * @param float $size The measured size
     *
     * @return void
     */
    public function add(float $size): void
    {
        $this->total += $size;
    }

    /**
     * Get the accumulated total.
     *
     * @return float The total
     */
    public function getTotal(): float
    {
        return $this->total;
    }
}
