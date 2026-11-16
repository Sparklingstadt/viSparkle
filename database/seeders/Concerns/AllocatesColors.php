<?php

namespace Database\Seeders\Concerns;

/**
 * Hex colours for the GD pictures the demo seeders draw.
 */
trait AllocatesColors
{
    /**
     * @return array{int<0, 255>, int<0, 255>, int<0, 255>}
     */
    private function rgb(string $hex): array
    {
        return [
            $this->byte((int) hexdec(substr($hex, 1, 2))),
            $this->byte((int) hexdec(substr($hex, 3, 2))),
            $this->byte((int) hexdec(substr($hex, 5, 2))),
        ];
    }

    /**
     * @return int<0, 255>
     */
    private function byte(int $value): int
    {
        return max(0, min(255, $value));
    }

    /**
     * @param  int<0, 127>  $alpha  0 (opaque) to 127 (transparent)
     */
    private function color(\GdImage $image, string $hex, int $alpha = 0): int
    {
        [$r, $g, $b] = $this->rgb($hex);

        return (int) imagecolorallocatealpha($image, $r, $g, $b, $alpha);
    }
}
