<?php

namespace Database\Seeders;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Draws the picture of a demo product with GD: the shape of the goods (an acrylic stand, cheki, can badges, a T-shirt…)
 * carrying the creator's motif in the creator's colours, so the shop looks like theirs. The motif follows the profile:
 * cherry blossoms for the illustrator, a cat for the cat manga, a slime sprite for the pixel artist, and so on.
 *
 * Everything is drawn at twice the size and scaled down, which smooths the edges GD leaves jagged. The scattered dots
 * in the background come from a random engine seeded by the slug and the product name, so a product gets the same
 * picture on every run.
 */
class DemoProductPainter
{
    /**
     * The size of the PNG, in pixels (square, like the product cards).
     */
    public const SIZE = 800;

    private const CANVAS = self::SIZE * 2;

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
     * The background colour of the picture being painted.
     */
    private string $ground = '#ffffff';

    /**
     * @param  array{string, string}  $palette  the creator's main and accent colours
     */
    public function __construct(private string $slug, private array $palette)
    {
        // The crowd creators (DemoCrowdSeeder) are named "<name>-<genre>"; their genre picks the motif.
        $this->motif = self::MOTIFS[$slug] ?? match (true) {
            str_ends_with($slug, '-photo') => 'camera',
            str_ends_with($slug, '-craft') => 'sheep',
            default => 'sparkle',
        };
    }

    /**
     * A PNG of the product, picked by its name. $kinds is the number of kinds (the designs of the can badges).
     */
    public function paint(string $name, int $kinds): string
    {
        [$main, $accent] = $this->palette;
        $flipped = str_ends_with($name, 'B');
        $palette = $flipped ? [$accent, $main] : $this->palette;

        $image = $this->layer(self::CANVAS, self::CANVAS);
        $this->ground = $this->mix($flipped ? $main : $accent, '#ffffff', $flipped ? 0.82 : 0.7);
        imagefilledrectangle($image, 0, 0, self::CANVAS, self::CANVAS, $this->color($image, $this->ground));
        $this->scatter($image, new Randomizer(new Mt19937(crc32("{$this->slug}/{$name}"))));

        match (true) {
            str_starts_with($name, 'アクリルスタンド') => $this->stand($image, $palette),
            str_starts_with($name, 'ランダムチェキ') => $this->cheki($image, $palette),
            $name === '缶バッジ' => $this->badges($image, $palette, max(1, min(5, $kinds))),
            $name === 'アクリルチャーム' => $this->charm($image, $palette),
            $name === 'パスケース' => $this->passCase($image, $palette),
            $name === 'タペストリー' => $this->tapestry($image, $palette),
            $name === 'Tシャツ' => $this->shirt($image, $palette),
            $name === 'ラフ画集' => $this->book($image, $palette),
            default => $this->place($image, $this->motif($palette, 900), 800, 800),
        };

        $out = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagecopyresampled($out, $image, 0, 0, 0, 0, self::SIZE, self::SIZE, self::CANVAS, self::CANVAS);

        ob_start();
        imagepng($out);

        return (string) ob_get_clean();
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

        $this->ground = $from;
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

    private function scatter(\GdImage $image, Randomizer $random): void
    {
        $dot = $this->mix($this->palette[0], '#ffffff', 0.45);

        for ($i = 0; $i < 18; $i++) {
            $d = $random->getInt(16, 64);
            imagefilledellipse($image, $random->getInt(0, self::CANVAS), $random->getInt(0, self::CANVAS), $d, $d, $this->color($image, $dot, 80));
        }
    }

    // Goods

    /**
     * @param  array{string, string}  $palette
     */
    private function stand(\GdImage $image, array $palette): void
    {
        [$main] = $palette;
        imagefilledellipse($image, 800, 1395, 840, 210, $this->color($image, $this->mix($main, '#000000', 0.25)));
        imagefilledellipse($image, 800, 1365, 840, 180, $this->color($image, $this->mix($main, '#ffffff', 0.35)));
        $this->roundRect($image, 412, 122, 1188, 1368, 104, '#ffffff', 30);
        $this->roundRect($image, 430, 140, 1170, 1350, 90, $this->mix($palette[1], '#ffffff', 0.85));
        $this->place($image, $this->motif($palette, 640), 800, 690);
        imagefilledpolygon($image, [470, 520, 640, 180, 720, 180, 470, 680], $this->color($image, '#ffffff', 85));
    }

    /**
     * Three cheki fanned out, the front one on top.
     *
     * @param  array{string, string}  $palette
     */
    private function cheki(\GdImage $image, array $palette): void
    {
        $cards = [[-11, 600, 770, 0.65], [8, 1000, 790, 0.45], [-2, 800, 840, 0.75]];

        foreach ($cards as [$angle, $x, $y, $tint]) {
            // The shadow is drawn on the same layer, so each card is turned once.
            $card = $this->layer(654, 780);
            $this->roundRect($card, 14, 20, 654, 780, 18, '#000000', 105);
            $this->roundRect($card, 0, 0, 640, 760, 18, '#ffffff');
            imagefilledrectangle($card, 44, 44, 596, 596, $this->color($card, $this->mix($palette[1], '#ffffff', $tint)));
            $this->place($card, $this->motif($palette, 440), 320, 320);
            imagefilledrectangle($card, 200, 664, 440, 676, $this->color($card, $this->mix($palette[0], '#ffffff', 0.5)));

            $this->place($image, $this->rotate($card, $angle), $x + 7, $y + 10);
        }
    }

    /**
     * One badge per design, each in its own colour.
     *
     * @param  array{string, string}  $palette
     */
    private function badges(\GdImage $image, array $palette, int $count): void
    {
        [$main, $accent] = $palette;
        $layouts = [
            1 => [[[800, 800]], 760],
            2 => [[[540, 800], [1060, 800]], 500],
            3 => [[[480, 600], [1120, 600], [800, 1080]], 470],
            4 => [[[560, 560], [1040, 560], [560, 1040], [1040, 1040]], 430],
            5 => [[[420, 580], [800, 580], [1180, 580], [610, 1020], [990, 1020]], 360],
        ];
        $faces = [$this->mix($accent, '#ffffff', 0.3), $this->mix($main, '#ffffff', 0.8), '#ffffff', $this->mix($accent, '#ffffff', 0.65), $this->mix($main, '#ffffff', 0.6)];
        [$centres, $d] = $layouts[$count];

        foreach ($centres as $i => [$x, $y]) {
            imagefilledellipse($image, $x + 14, $y + 20, $d, $d, $this->color($image, '#000000', 100));
            imagefilledellipse($image, $x, $y, $d, $d, $this->color($image, $this->mix($faces[$i], '#000000', 0.15)));
            imagefilledellipse($image, $x, $y, (int) ($d * 0.93), (int) ($d * 0.93), $this->color($image, $faces[$i]));
            $this->place($image, $this->motif($palette, (int) ($d * 0.62)), $x, $y);
            imagefilledellipse($image, (int) ($x - $d * 0.2), (int) ($y - $d * 0.24), (int) ($d * 0.3), (int) ($d * 0.16), $this->color($image, '#ffffff', 80));
        }
    }

    /**
     * @param  array{string, string}  $palette
     */
    private function charm(\GdImage $image, array $palette): void
    {
        $metal = '#94a3b8';

        for ($y = 100; $y <= 330; $y += 40) {
            imagefilledellipse($image, 800, $y, 36, 36, $this->color($image, $this->mix($metal, '#000000', 0.3)));
            imagefilledellipse($image, 800, $y, 28, 28, $this->color($image, $metal));
            imagefilledellipse($image, 794, $y - 6, 10, 10, $this->color($image, '#ffffff', 40));
        }

        // GD draws outlines one pixel thick whatever the thickness, so the ring is a disc with its middle filled back in.
        imagefilledellipse($image, 800, 420, 150, 150, $this->color($image, $this->mix($metal, '#000000', 0.3)));
        imagefilledellipse($image, 800, 420, 140, 140, $this->color($image, $metal));
        imagefilledellipse($image, 800, 420, 104, 104, $this->color($image, $this->ground));

        imagefilledellipse($image, 800, 900, 840, 840, $this->color($image, '#ffffff', 25));
        imagefilledellipse($image, 800, 900, 790, 790, $this->color($image, $this->mix($palette[1], '#ffffff', 0.6)));
        imagefilledellipse($image, 800, 545, 50, 50, $this->color($image, '#ffffff'));
        $this->place($image, $this->motif($palette, 560), 800, 930);
        imagefilledellipse($image, 640, 720, 200, 110, $this->color($image, '#ffffff', 90));
    }

    /**
     * @param  array{string, string}  $palette
     */
    private function passCase(\GdImage $image, array $palette): void
    {
        [$main, $accent] = $palette;
        imagesetthickness($image, 8);
        imageline($image, 800, 300, 800, 420, $this->color($image, '#94a3b8'));
        imagesetthickness($image, 1);
        imagefilledellipse($image, 800, 230, 170, 170, $this->color($image, $this->mix($main, '#000000', 0.25)));
        imagefilledellipse($image, 800, 230, 96, 96, $this->color($image, $accent));
        $this->roundRect($image, 760, 392, 840, 440, 12, '#94a3b8');

        $this->roundRect($image, 454, 434, 1174, 1454, 60, '#000000', 105);
        $this->roundRect($image, 440, 420, 1160, 1440, 60, $main);
        $this->roundRect($image, 500, 490, 1100, 1270, 36, $this->mix($accent, '#ffffff', 0.8));
        $this->place($image, $this->motif($palette, 500), 800, 880);
        $this->roundRect($image, 560, 1330, 1040, 1350, 10, '#ffffff', 80);
    }

    /**
     * @param  array{string, string}  $palette
     */
    private function tapestry(\GdImage $image, array $palette): void
    {
        [$main, $accent] = $palette;
        $wood = '#92400e';
        imagesetthickness($image, 8);
        imageline($image, 800, 150, 440, 300, $this->color($image, '#78716c'));
        imageline($image, 800, 150, 1160, 300, $this->color($image, '#78716c'));
        imagesetthickness($image, 1);
        imagefilledellipse($image, 800, 140, 44, 44, $this->color($image, '#78716c'));

        [$top, $bottom] = [$this->mix($accent, '#ffffff', 0.35), $this->mix($main, '#ffffff', 0.6)];

        for ($y = 320; $y < 1460; $y++) {
            imageline($image, 420, $y, 1180, $y, $this->color($image, $this->mix($top, $bottom, ($y - 320) / 1140)));
        }

        imagefilledrectangle($image, 420, 1390, 1180, 1460, $this->color($image, $main));
        $this->roundRect($image, 520, 1240, 1080, 1270, 15, $main, 60);
        $this->roundRect($image, 620, 1300, 980, 1325, 12, $main, 80);
        $this->place($image, $this->motif($palette, 640), 800, 780);

        $this->roundRect($image, 360, 290, 1240, 334, 22, $wood);
        imagefilledellipse($image, 360, 312, 70, 70, $this->color($image, $this->mix($wood, '#000000', 0.2)));
        imagefilledellipse($image, 1240, 312, 70, 70, $this->color($image, $this->mix($wood, '#000000', 0.2)));
    }

    /**
     * @param  array{string, string}  $palette
     */
    private function shirt(\GdImage $image, array $palette): void
    {
        $outline = [620, 240, 440, 300, 210, 570, 400, 700, 480, 610, 480, 1400, 1120, 1400, 1120, 610, 1200, 700, 1390, 570, 1160, 300, 980, 240, 930, 330, 800, 370, 670, 330];
        $shadow = array_map(fn (int $v, int $i) => $v + ($i % 2 === 0 ? 14 : 20), $outline, array_keys($outline));

        imagefilledpolygon($image, $shadow, $this->color($image, '#000000', 105));
        imagefilledpolygon($image, $outline, $this->color($image, '#ffffff'));
        imagesetthickness($image, 12);
        imagepolygon($image, $outline, $this->color($image, $this->mix($palette[0], '#000000', 0.1)));
        imagesetthickness($image, 1);
        $this->place($image, $this->motif($palette, 520), 800, 780);
    }

    /**
     * A sketchbook with a pencil lying on it.
     *
     * @param  array{string, string}  $palette
     */
    private function book(\GdImage $image, array $palette): void
    {
        [$main] = $palette;
        $this->roundRect($image, 494, 264, 1234, 1344, 20, '#000000', 105);
        $this->roundRect($image, 480, 250, 1220, 1330, 20, '#ffffff');
        $this->roundRect($image, 400, 220, 1160, 1310, 24, $main);
        imagefilledrectangle($image, 400, 220, 470, 1310, $this->color($image, $this->mix($main, '#000000', 0.25)));
        $this->roundRect($image, 530, 330, 1070, 870, 24, '#ffffff');
        $this->place($image, $this->motif($palette, 440), 800, 600);

        // Pencil strokes over the picture, as in a rough sketch.
        imagesetthickness($image, 5);

        foreach ([[570, 810, 760, 770], [610, 840, 900, 790], [880, 380, 1030, 430]] as [$x1, $y1, $x2, $y2]) {
            imageline($image, $x1, $y1, $x2, $y2, $this->color($image, '#64748b', 60));
        }

        imagesetthickness($image, 1);
        $this->roundRect($image, 540, 960, 1040, 1000, 20, '#ffffff', 30);
        $this->roundRect($image, 540, 1040, 880, 1072, 16, '#ffffff', 50);

        // The pencil runs from the tip (bottom left) to the eraser (top right).
        [$ax, $ay, $bx, $by, $n] = [980, 1420, 1380, 1020, 35];
        imagefilledpolygon($image, [$bx + $n, $by + $n, $bx - $n, $by - $n, $bx + 45 - $n, $by - 45 - $n, $bx + 45 + $n, $by - 45 + $n], $this->color($image, '#f9a8d4'));
        imagefilledpolygon($image, [$ax + $n, $ay + $n, $bx + $n, $by + $n, $bx - $n, $by - $n, $ax - $n, $ay - $n], $this->color($image, '#fbbf24'));
        imagefilledpolygon($image, [$ax + $n, $ay + $n, $ax - $n, $ay - $n, $ax - 80, $ay + 80], $this->color($image, '#fde68a'));
        imagefilledpolygon($image, [$ax - 56, $ay + 44, $ax - 44, $ay + 56, $ax - 80, $ay + 80], $this->color($image, '#374151'));
    }

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
