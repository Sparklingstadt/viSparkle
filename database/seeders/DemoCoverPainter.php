<?php

namespace Database\Seeders;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Draws the cover of a demo post with GD: a sparkle motif in two colours.
 *
 * Everything is drawn at twice the size and scaled down, which smooths the edges GD leaves jagged. The layout comes
 * from a random engine seeded by the post title, so a post gets the same picture on every run.
 */
class DemoCoverPainter
{
    /**
     * @param  array{string, string}  $palette  the main and accent colours
     */
    public function __construct(private array $palette) {}

    /**
     * A 16:9 PNG cover for a post: a gradient in the two colours with the motif large on one side and two small
     * ones tilted around it. The title seeds the layout, so every post looks a little different.
     */
    public function cover(string $title): string
    {
        [$width, $height] = [1280 * 2, 720 * 2];
        [$main, $accent] = $this->palette;
        $random = new Randomizer(new Mt19937(crc32("post/{$title}")));
        $flipped = $random->getInt(0, 1) === 1;

        $image = $this->layer($width, $height);
        [$from, $to] = [$this->mix($main, '#ffffff', 0.55), $this->mix($accent, '#ffffff', 0.35)];

        for ($x = 0; $x < $width; $x += 2) {
            imagefilledrectangle($image, $x, 0, $x + 1, $height, $this->color($image, $this->mix($from, $to, $x / $width)));
        }

        $this->scatterOver($image, $random, $width, $height);

        $side = $flipped ? 0.3 : 0.7;
        $this->place($image, $this->rotate($this->motif([$main, $accent], 1000), $random->getInt(-12, 12)), (int) ($width * $side), (int) ($height * 0.52));

        foreach ([[0.12, 0.25], [0.88, 0.78]] as [$x, $y]) {
            $small = $this->rotate($this->motif([$accent, $main], 300), $random->getInt(-30, 30));
            $this->place($image, $small, (int) ($width * ($flipped ? 1 - $x : $x)), (int) ($height * $y));
        }

        $out = imagecreatetruecolor(1280, 720);
        imagecopyresampled($out, $image, 0, 0, 0, 0, 1280, 720, $width, $height);

        ob_start();
        imagepng($out);

        return (string) ob_get_clean();
    }

    private function scatterOver(\GdImage $image, Randomizer $random, int $width, int $height): void
    {
        for ($i = 0; $i < 26; $i++) {
            $d = $random->getInt(24, 120);
            imagefilledellipse($image, $random->getInt(0, $width), $random->getInt(0, $height), $d, $d, $this->color($image, '#ffffff', 85));
        }
    }

    // Goods

    // Motifs, each drawn on a transparent square of $s pixels

    /**
     * @param  array{string, string}  $palette
     */
    private function motif(array $palette, int $s): \GdImage
    {
        $layer = $this->layer($s, $s);
        $c = $s / 2;
        $r = $s / 2;
        [$main, $accent] = $palette;

        $this->sparkle($layer, $c, $r, $main, $accent);

        return $layer;
    }

    private function sparkle(\GdImage $l, float $c, float $r, string $main, string $accent): void
    {
        $this->star($l, $c, $c, $r * 0.9, $r * 0.22, $main);
        $this->star($l, $c + $r * 0.6, $c - $r * 0.55, $r * 0.3, $r * 0.08, $accent);
        $this->star($l, $c - $r * 0.6, $c + $r * 0.6, $r * 0.22, $r * 0.06, $accent);
    }

    // Drawing helpers

    private function layer(int $width, int $height): \GdImage
    {
        $layer = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagealphablending($layer, false);
        imagefilledrectangle($layer, 0, 0, $width, $height, (int) imagecolorallocatealpha($layer, 0, 0, 0, 127));
        imagealphablending($layer, true);
        imagesavealpha($layer, true);

        return $layer;
    }

    /**
     * Copies a layer onto the image, centred on ($x, $y).
     */
    private function place(\GdImage $image, \GdImage $layer, int $x, int $y): void
    {
        imagecopy($image, $layer, $x - intdiv(imagesx($layer), 2), $y - intdiv(imagesy($layer), 2), 0, 0, imagesx($layer), imagesy($layer));
    }

    private function rotate(\GdImage $layer, float $angle): \GdImage
    {
        // Nothing to do, and GD's imagerotate() can crash on a zero angle with nearest-neighbour interpolation.
        if ($angle == 0.0) {
            return $layer;
        }

        // The picture is scaled down at the end anyway, so the quickest interpolation is enough.
        imagesetinterpolation($layer, IMG_NEAREST_NEIGHBOUR);
        $rotated = imagerotate($layer, $angle, (int) imagecolorallocatealpha($layer, 0, 0, 0, 127));

        if ($rotated === false) {
            return $layer;
        }

        imagesavealpha($rotated, true);
        imagealphablending($rotated, true);

        return $rotated;
    }

    private function star(\GdImage $l, float $x, float $y, float $outer, float $inner, string $hex): void
    {
        $points = [];

        for ($k = 0; $k < 8; $k++) {
            $a = deg2rad($k * 45 - 90);
            $d = $k % 2 === 0 ? $outer : $inner;
            $points[] = $x + cos($a) * $d;
            $points[] = $y + sin($a) * $d;
        }

        $this->polygon($l, $points, $hex);
    }

    /**
     * @param  list<float|int>  $points
     * @param  int<0, 127>  $alpha
     */
    private function polygon(\GdImage $l, array $points, string $hex, int $alpha = 0): void
    {
        imagefilledpolygon($l, array_map(fn (float|int $v) => (int) round($v), $points), $this->color($l, $hex, $alpha));
    }

    /**
     * Blends two colours: $t = 0 gives $a, $t = 1 gives $b.
     */
    private function mix(string $a, string $b, float $t): string
    {
        $channels = array_map(fn (int $x, int $y) => (int) round($x + ($y - $x) * $t), $this->rgb($a), $this->rgb($b));

        return vsprintf('#%02x%02x%02x', $channels);
    }

    /**
     * @return array{int<0, 255>, int<0, 255>, int<0, 255>}
     */
    private function rgb(string $hex): array
    {
        return [
            max(0, min(255, (int) hexdec(substr($hex, 1, 2)))),
            max(0, min(255, (int) hexdec(substr($hex, 3, 2)))),
            max(0, min(255, (int) hexdec(substr($hex, 5, 2)))),
        ];
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
