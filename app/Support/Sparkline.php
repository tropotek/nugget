<?php

namespace App\Support;

/**
 * A row of amounts as the points of a small line chart.
 */
class Sparkline
{
    /**
     * The `points` of an SVG polyline that runs the amounts from left to
     * right across a box of this size, the lowest at the bottom and the
     * highest at the top. Empty when there are fewer than two amounts.
     *
     * The amounts become floats here, and only here, to place the line: no
     * figure is worked out from them.
     *
     * @param  list<Decimal>  $amounts  oldest first
     */
    public static function points(array $amounts, int $width, int $height): string
    {
        if (count($amounts) < 2) {
            return '';
        }

        $values = array_map(fn (Decimal $amount) => (float) (string) $amount, $amounts);
        $low = min($values);
        $spread = max($values) - $low;
        $step = $width / (count($values) - 1);

        $points = [];

        foreach ($values as $index => $value) {
            // A flat line sits in the middle. A pixel is kept clear above and below for the stroke.
            $y = $spread > 0.0 ? 1 + ($height - 2) * (1 - ($value - $low) / $spread) : $height / 2;

            $points[] = round($index * $step, 1).','.round($y, 1);
        }

        return implode(' ', $points);
    }
}
