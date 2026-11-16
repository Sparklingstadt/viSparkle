<?php

namespace Database\Seeders;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Draws the cover of a demo post with GD: the creator's motif in the creator's colours. The motif follows the profile:
 * cherry blossoms for the illustrator, a cat for the cat manga, a slime sprite for the pixel artist, and so on.
 *
 * Everything is drawn at twice the size and scaled down, which smooths the edges GD leaves jagged. The layout comes
 * from a random engine seeded by the slug and the post title, so a post gets the same picture on every run.
 */
class DemoCoverPainter
{
    /**
     * The motif of each demo creator, after their profile.
     */
    private const MOTIFS = [
        'demo-creator' => 'sparkle',
        'sakura-illust' => 'sakura',
        'kuroneko-manga' => 'cat',
        'pixel-forge' => 'pixel',
        'aoi-music' => 'note',
        'tanaka-photo' => 'camera',
        'moko-craft' => 'sheep',
        'night-novel' => 'moon',
        'hiro-3d' => 'cube',
        'yuki-cooking' => 'onigiri',
        'studio-lumen' => 'controller',
    ];

    private const INK = '#1f2937';

    private string $motif;

    /**
     * @param  array{string, string}  $palette  the creator's main and accent colours
     */
    public function __construct(private string $slug, private array $palette)
    {
        $this->motif = self::MOTIFS[$slug] ?? 'sparkle';
    }

    /**
     * A 16:9 PNG cover for a post: a gradient in the creator's colours with their motif large on one side and two small
     * ones tilted around it. The title seeds the layout, so every post of a creator looks a little different.
     */
    public function cover(string $title): string
    {
        [$width, $height] = [1280 * 2, 720 * 2];
        [$main, $accent] = $this->palette;
        $random = new Randomizer(new Mt19937(crc32("{$this->slug}/post/{$title}")));
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

        match ($this->motif) {
            'sakura' => $this->sakura($layer, $c, $r, $main, $accent),
            'cat' => $this->cat($layer, $c, $r, $main, $accent),
            'pixel' => $this->pixel($layer, $s, $main, $accent),
            'note' => $this->note($layer, $c, $r, $main, $accent),
            'camera' => $this->camera($layer, $c, $r, $main, $accent),
            'sheep' => $this->sheep($layer, $c, $r, $main),
            'moon' => $this->moon($layer, $c, $r, $main, $accent),
            'cube' => $this->cube($layer, $c, $r, $main, $accent),
            'onigiri' => $this->onigiri($layer, $c, $r, $main, $accent),
            'controller' => $this->controller($layer, $c, $r, $main, $accent),
            default => $this->sparkle($layer, $c, $r, $main, $accent),
        };

        return $layer;
    }

    private function sparkle(\GdImage $l, float $c, float $r, string $main, string $accent): void
    {
        $this->star($l, $c, $c, $r * 0.9, $r * 0.22, $main);
        $this->star($l, $c + $r * 0.6, $c - $r * 0.55, $r * 0.3, $r * 0.08, $accent);
        $this->star($l, $c - $r * 0.6, $c + $r * 0.6, $r * 0.22, $r * 0.06, $accent);
    }

    private function sakura(\GdImage $l, float $c, float $r, string $main, string $accent): void
    {
        $petal = $this->mix($main, '#ffffff', 0.2);

        for ($k = 0; $k < 5; $k++) {
            $a = deg2rad($k * 72 - 90);
            $this->oval($l, $c + cos($a) * $r * 0.47, $c + sin($a) * $r * 0.47, $r * 0.5, $r * 0.33, $k * 72 - 90, $petal);
            // The notch at the tip of the petal.
            $this->erase($l, [
                $c + cos($a - 0.13) * $r * 1.02, $c + sin($a - 0.13) * $r * 1.02,
                $c + cos($a) * $r * 0.82, $c + sin($a) * $r * 0.82,
                $c + cos($a + 0.13) * $r * 1.02, $c + sin($a + 0.13) * $r * 1.02,
            ]);
        }

        $this->disc($l, $c, $c, $r * 0.42, $accent);

        for ($k = 0; $k < 5; $k++) {
            $a = deg2rad($k * 72 - 54);
            $this->disc($l, $c + cos($a) * $r * 0.24, $c + sin($a) * $r * 0.24, $r * 0.09, $this->mix($main, '#000000', 0.2));
        }
    }

    private function cat(\GdImage $l, float $c, float $r, string $main, string $accent): void
    {
        $pink = '#f9a8d4';

        foreach ([-1, 1] as $side) {
            $this->polygon($l, [$c + $side * $r * 0.78, $c - $r * 0.1, $c + $side * $r * 0.66, $c - $r * 0.9, $c + $side * $r * 0.16, $c - $r * 0.5], $main);
            $this->polygon($l, [$c + $side * $r * 0.66, $c - $r * 0.22, $c + $side * $r * 0.6, $c - $r * 0.7, $c + $side * $r * 0.32, $c - $r * 0.45], $pink);
        }

        $this->ellipse($l, $c, $c + $r * 0.12, $r * 0.8, $r * 0.68, $main);

        foreach ([-1, 1] as $side) {
            $x = $c + $side * $r * 0.32;
            $this->ellipse($l, $x, $c + $r * 0.02, $r * 0.17, $r * 0.2, $accent);
            $this->ellipse($l, $x, $c + $r * 0.02, $r * 0.045, $r * 0.16, '#111827');
            $this->disc($l, $x + $r * 0.05, $c - $r * 0.06, $r * 0.035, '#ffffff');
            imagesetthickness($l, max(2, (int) ($r * 0.025)));
            imageline($l, (int) ($c + $side * $r * 0.4), (int) ($c + $r * 0.32), (int) ($c + $side * $r * 0.98), (int) ($c + $r * 0.22), $this->color($l, $this->mix($main, '#ffffff', 0.65)));
            imageline($l, (int) ($c + $side * $r * 0.4), (int) ($c + $r * 0.4), (int) ($c + $side * $r * 0.98), (int) ($c + $r * 0.46), $this->color($l, $this->mix($main, '#ffffff', 0.65)));
            imagesetthickness($l, 1);
        }

        $this->polygon($l, [$c - $r * 0.08, $c + $r * 0.24, $c + $r * 0.08, $c + $r * 0.24, $c, $c + $r * 0.34], $pink);
    }

    /**
     * A slime, dot by dot.
     */
    private function pixel(\GdImage $l, int $s, string $main, string $accent): void
    {
        $rows = [
            '....XXX....',
            '..XXXXXXX..',
            '.XXHXXXXXX.',
            '.XHXXXXXXX.',
            'XXXWBXXWBXX',
            'XXXWBXXWBXX',
            'XXXXXXXXXXX',
            'XXXXAAAXXXX',
            '.XXXXXXXXX.',
            '..XXXXXXX..',
        ];
        $colors = ['X' => $main, 'H' => $this->mix($main, '#ffffff', 0.6), 'W' => '#ffffff', 'B' => self::INK, 'A' => $accent];
        $cell = intdiv($s, 11);
        $top = intdiv($s - $cell * count($rows), 2);
        $left = intdiv($s - $cell * 11, 2);

        foreach ($rows as $y => $row) {
            foreach (str_split($row) as $x => $char) {
                if (isset($colors[$char])) {
                    imagefilledrectangle($l, $left + $x * $cell, $top + $y * $cell, $left + ($x + 1) * $cell - 1, $top + ($y + 1) * $cell - 1, $this->color($l, $colors[$char]));
                }
            }
        }
    }

    private function note(\GdImage $l, float $c, float $r, string $main, string $accent): void
    {
        $this->disc($l, $c, $c, $r * 0.95, $this->mix($accent, '#ffffff', 0.4));

        // Piano keys along the bottom of the circle.
        for ($k = 0; $k < 7; $k++) {
            $x = $c - $r * 0.56 + $k * $r * 0.16;
            $this->rect($l, $x, $c + $r * 0.55, $x + $r * 0.14, $c + $r * 0.78, '#ffffff');
        }

        $stem = $r * 0.09;
        [$x1, $y1, $x2, $y2] = [$c - $r * 0.32, $c + $r * 0.3, $c + $r * 0.38, $c + $r * 0.16];
        $this->oval($l, $x1, $y1, $r * 0.24, $r * 0.17, -25, $main);
        $this->oval($l, $x2, $y2, $r * 0.24, $r * 0.17, -25, $main);
        $this->rect($l, $x1 + $r * 0.12, $c - $r * 0.62, $x1 + $r * 0.12 + $stem, $y1, $main);
        $this->rect($l, $x2 + $r * 0.12, $c - $r * 0.76, $x2 + $r * 0.12 + $stem, $y2, $main);
        $this->polygon($l, [$x1 + $r * 0.12, $c - $r * 0.62, $x2 + $r * 0.12 + $stem, $c - $r * 0.76, $x2 + $r * 0.12 + $stem, $c - $r * 0.58, $x1 + $r * 0.12, $c - $r * 0.44], $main);
    }

    private function camera(\GdImage $l, float $c, float $r, string $main, string $accent): void
    {
        $this->rect($l, $c - $r * 0.4, $c - $r * 0.66, $c + $r * 0.1, $c - $r * 0.36, $this->mix($main, '#000000', 0.25));
        $this->rect($l, $c + $r * 0.45, $c - $r * 0.58, $c + $r * 0.7, $c - $r * 0.4, $accent);
        $this->roundRect($l, (int) ($c - $r * 0.9), (int) ($c - $r * 0.45), (int) ($c + $r * 0.9), (int) ($c + $r * 0.62), (int) ($r * 0.16), $main);
        $this->rect($l, $c - $r * 0.9, $c - $r * 0.12, $c + $r * 0.9, $c + $r * 0.3, $this->mix($main, '#000000', 0.15));
        $this->disc($l, $c, $c + $r * 0.08, $r * 0.52, $accent);
        $this->disc($l, $c, $c + $r * 0.08, $r * 0.4, self::INK);
        $this->disc($l, $c, $c + $r * 0.08, $r * 0.26, $this->mix(self::INK, $main, 0.4));
        $this->disc($l, $c - $r * 0.1, $c - $r * 0.03, $r * 0.08, '#ffffff');
        $this->rect($l, $c + $r * 0.55, $c - $r * 0.32, $c + $r * 0.78, $c - $r * 0.18, '#ffffff');
    }

    private function sheep(\GdImage $l, float $c, float $r, string $main): void
    {
        $face = '#78716c';
        $wool = '#fffdf8';
        $edge = $this->mix($main, '#000000', 0.15);
        $puffs = [];

        for ($k = 0; $k < 10; $k++) {
            $a = deg2rad($k * 36);
            $puffs[] = [$c + $r * 0.12 + cos($a) * $r * 0.5, $c + sin($a) * $r * 0.32, $r * 0.3];
        }

        foreach ([[-0.2, 0.42], [0.1, 0.45], [0.36, 0.42], [0.55, 0.38]] as [$dx, $dy]) {
            $this->rect($l, $c + $r * $dx, $c + $r * 0.2, $c + $r * ($dx + 0.1), $c + $r * ($dy + 0.22), $face);
        }

        foreach ($puffs as [$x, $y, $d]) {
            $this->disc($l, $x, $y, $d + $r * 0.04, $edge);
        }

        foreach ($puffs as [$x, $y, $d]) {
            $this->disc($l, $x, $y, $d, $wool);
        }

        $this->ellipse($l, $c + $r * 0.12, $c, $r * 0.52, $r * 0.33, $wool);

        $hx = $c - $r * 0.55;
        $this->oval($l, $hx - $r * 0.22, $c - $r * 0.18, $r * 0.16, $r * 0.08, 25, $face);
        $this->oval($l, $hx + $r * 0.22, $c - $r * 0.18, $r * 0.16, $r * 0.08, -25, $face);
        $this->ellipse($l, $hx, $c + $r * 0.02, $r * 0.24, $r * 0.3, $face);
        $this->disc($l, $hx, $c - $r * 0.26, $r * 0.16, $wool);
        $this->disc($l, $hx - $r * 0.09, $c, $r * 0.04, '#ffffff');
        $this->disc($l, $hx + $r * 0.09, $c, $r * 0.04, '#ffffff');
        $this->ellipse($l, $hx - $r * 0.14, $c + $r * 0.14, $r * 0.06, $r * 0.04, $main);
        $this->ellipse($l, $hx + $r * 0.14, $c + $r * 0.14, $r * 0.06, $r * 0.04, $main);
    }

    private function moon(\GdImage $l, float $c, float $r, string $main, string $accent): void
    {
        $this->disc($l, $c - $r * 0.1, $c + $r * 0.05, $r * 0.78, $accent);
        imagealphablending($l, false);
        $this->disc($l, $c + $r * 0.28, $c - $r * 0.2, $r * 0.62, '#000000', 127);
        imagealphablending($l, true);
        $this->star($l, $c + $r * 0.55, $c + $r * 0.5, $r * 0.24, $r * 0.06, $main);
        $this->star($l, $c + $r * 0.62, $c - $r * 0.7, $r * 0.16, $r * 0.04, $main);
        $this->star($l, $c - $r * 0.78, $c - $r * 0.72, $r * 0.12, $r * 0.03, $accent);
    }

    private function cube(\GdImage $l, float $c, float $r, string $main, string $accent): void
    {
        [$w, $h] = [$r * 0.78, $r * 0.45];
        $this->polygon($l, [$c, $c - 2 * $h, $c + $w, $c - $h, $c, $c, $c - $w, $c - $h], $accent);
        $this->polygon($l, [$c - $w, $c - $h, $c, $c, $c, $c + 2 * $h, $c - $w, $c + $h], $main);
        $this->polygon($l, [$c, $c, $c + $w, $c - $h, $c + $w, $c + $h, $c, $c + 2 * $h], $this->mix($main, '#000000', 0.3));
        imagesetthickness($l, max(2, (int) ($r * 0.02)));
        imagepolygon($l, array_map('intval', [$c, $c - 2 * $h, $c + $w, $c - $h, $c + $w, $c + $h, $c, $c + 2 * $h, $c - $w, $c + $h, $c - $w, $c - $h]), $this->color($l, '#ffffff', 60));
        imagesetthickness($l, 1);

        foreach ([[$c, $c - 2 * $h], [$c + $w, $c - $h], [$c - $w, $c - $h], [$c, $c]] as [$x, $y]) {
            $this->disc($l, $x, $y, $r * 0.045, '#ffffff');
        }
    }

    private function onigiri(\GdImage $l, float $c, float $r, string $main, string $accent): void
    {
        $corners = [[$c, $c - $r * 0.42], [$c - $r * 0.52, $c + $r * 0.44], [$c + $r * 0.52, $c + $r * 0.44]];
        $this->roundedTriangle($l, $corners, $r * 0.4, $this->mix($accent, '#000000', 0.15));
        $this->roundedTriangle($l, $corners, $r * 0.35, '#ffffff');
        $this->rect($l, $c - $r * 0.34, $c + $r * 0.3, $c + $r * 0.34, $c + $r * 0.79, self::INK);
        $this->disc($l, $c, $c - $r * 0.08, $r * 0.15, $main);

        foreach ([[-0.3, -0.2], [0.32, -0.05], [-0.12, 0.12], [0.2, -0.36]] as [$dx, $dy]) {
            $this->oval($l, $c + $r * $dx, $c + $r * $dy, $r * 0.03, $r * 0.015, 30, '#e5e7eb');
        }
    }

    private function controller(\GdImage $l, float $c, float $r, string $main, string $accent): void
    {
        $this->star($l, $c, $c - $r * 0.72, $r * 0.2, $r * 0.05, $accent);
        $this->disc($l, $c - $r * 0.55, $c + $r * 0.12, $r * 0.36, $main);
        $this->disc($l, $c + $r * 0.55, $c + $r * 0.12, $r * 0.36, $main);
        $this->roundRect($l, (int) ($c - $r * 0.62), (int) ($c - $r * 0.36), (int) ($c + $r * 0.62), (int) ($c + $r * 0.3), (int) ($r * 0.2), $main);

        $t = $r * 0.06;
        [$px, $arm] = [$c - $r * 0.5, $r * 0.17];
        $this->rect($l, $px - $arm, $c - $t, $px + $arm, $c + $t, '#ffffff');
        $this->rect($l, $px - $t, $c - $arm, $px + $t, $c + $arm, '#ffffff');

        foreach ([[0.5, -0.13], [0.66, 0.0], [0.5, 0.13], [0.34, 0.0]] as [$dx, $dy]) {
            $this->disc($l, $c + $r * $dx, $c + $r * $dy, $r * 0.08, $accent);
        }

        $this->rect($l, $c - $r * 0.12, $c - $r * 0.04, $c - $r * 0.02, $c + $r * 0.02, $this->mix($main, '#ffffff', 0.5));
        $this->rect($l, $c + $r * 0.02, $c - $r * 0.04, $c + $r * 0.12, $c + $r * 0.02, $this->mix($main, '#ffffff', 0.5));
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
     * A filled ellipse with radii $rx and $ry, turned by $degrees.
     */
    private function oval(\GdImage $l, float $x, float $y, float $rx, float $ry, float $degrees, string $hex): void
    {
        $a = deg2rad($degrees);
        $points = [];

        for ($k = 0; $k < 36; $k++) {
            $t = deg2rad($k * 10);
            [$px, $py] = [cos($t) * $rx, sin($t) * $ry];
            $points[] = $x + $px * cos($a) - $py * sin($a);
            $points[] = $y + $px * sin($a) + $py * cos($a);
        }

        $this->polygon($l, $points, $hex);
    }

    /**
     * A triangle with corners rounded to $radius, around the centres of the three corner circles.
     *
     * @param  list<array{float, float}>  $corners
     */
    private function roundedTriangle(\GdImage $l, array $corners, float $radius, string $hex): void
    {
        $points = [];

        foreach ($corners as $i => [$x1, $y1]) {
            [$x2, $y2] = $corners[($i + 1) % 3];
            $length = hypot($x2 - $x1, $y2 - $y1);
            // (dy, -dx) points into the triangle for these corners, so step the other way.
            [$nx, $ny] = [($y2 - $y1) / $length * $radius, -($x2 - $x1) / $length * $radius];
            array_push($points, $x1 - $nx, $y1 - $ny, $x2 - $nx, $y2 - $ny);
        }

        $this->polygon($l, $points, $hex);

        foreach ($corners as [$x, $y]) {
            $this->disc($l, $x, $y, $radius, $hex);
        }
    }

    /**
     * Cuts a polygon out of a layer, leaving it transparent.
     *
     * @param  list<float>  $points
     */
    private function erase(\GdImage $l, array $points): void
    {
        imagealphablending($l, false);
        $this->polygon($l, $points, '#000000', 127);
        imagealphablending($l, true);
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
     * @param  int<0, 127>  $alpha
     */
    private function disc(\GdImage $l, float $x, float $y, float $radius, string $hex, int $alpha = 0): void
    {
        $d = (int) round($radius * 2);
        imagefilledellipse($l, (int) round($x), (int) round($y), $d, $d, $this->color($l, $hex, $alpha));
    }

    private function ellipse(\GdImage $l, float $x, float $y, float $rx, float $ry, string $hex): void
    {
        imagefilledellipse($l, (int) round($x), (int) round($y), (int) round($rx * 2), (int) round($ry * 2), $this->color($l, $hex));
    }

    private function rect(\GdImage $l, float $x1, float $y1, float $x2, float $y2, string $hex): void
    {
        imagefilledrectangle($l, (int) round($x1), (int) round($y1), (int) round($x2), (int) round($y2), $this->color($l, $hex));
    }

    /**
     * @param  int<0, 127>  $alpha
     */
    private function roundRect(\GdImage $l, int $x1, int $y1, int $x2, int $y2, int $radius, string $hex, int $alpha = 0): void
    {
        $color = $this->color($l, $hex, $alpha);
        $d = $radius * 2;
        imagefilledrectangle($l, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($l, $x1, $y1 + $radius, $x1 + $radius - 1, $y2 - $radius, $color);
        imagefilledrectangle($l, $x2 - $radius + 1, $y1 + $radius, $x2, $y2 - $radius, $color);

        // Quarter arcs, so translucent corners are not painted twice.
        imagefilledarc($l, $x1 + $radius, $y1 + $radius, $d, $d, 180, 270, $color, IMG_ARC_PIE);
        imagefilledarc($l, $x2 - $radius, $y1 + $radius, $d, $d, 270, 360, $color, IMG_ARC_PIE);
        imagefilledarc($l, $x2 - $radius, $y2 - $radius, $d, $d, 0, 90, $color, IMG_ARC_PIE);
        imagefilledarc($l, $x1 + $radius, $y2 - $radius, $d, $d, 90, 180, $color, IMG_ARC_PIE);
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
