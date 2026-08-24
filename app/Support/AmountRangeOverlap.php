<?php

namespace App\Support;

final class AmountRangeOverlap
{
    /**
     * Determine whether two [min, max] amount ranges overlap. A null max
     * means the range extends to infinity. Two ranges overlap unless one
     * entirely ends before the other begins.
     */
    public static function overlaps(float $minA, ?float $maxA, float $minB, ?float $maxB): bool
    {
        $aEndsBeforeB = $maxA !== null && $maxA < $minB;
        $bEndsBeforeA = $maxB !== null && $maxB < $minA;

        return ! ($aEndsBeforeB || $bEndsBeforeA);
    }
}
