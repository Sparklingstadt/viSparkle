<?php

/**
 * Regenerates the viSparkle brand images in public/ from code (no design tool needed).
 *
 *   ./vendor/bin/sail php resources/brand/generate.php
 *
 * Writes favicon.svg, favicon.ico (16/32/48), apple-touch-icon.png (180), icon-512.png and images/og.png (1200x630).
 * The fonts come from the Sail image: Nimbus Sans Bold (logo text) and IPAGothic (Japanese tagline).
 */
const PUBLIC_DIR = __DIR__.'/../../public';
const FROM = [124, 58, 237];   // #7c3aed
const TO = [236, 72, 153];     // #ec4899
const LOGO_FONT = '/usr/share/fonts/opentype/urw-base35/NimbusSans-Bold.otf';
const JP_FONT = '/usr/share/fonts/opentype/ipafont-gothic/ipag.ttf';
const TAGLINE = 'クリエイターを、月額で応援しよう';

/** The sparkle as an SVG path in a 100x100 box: a four-pointed star with concave sides, plus a small one. */
function sparklePath(): string
{
    return 'M50 14 Q55 45 86 50 Q55 55 50 86 Q45 55 14 50 Q45 45 50 14 Z '
        .'M76 14 Q78 22 86 24 Q78 26 76 34 Q74 26 66 24 Q74 22 76 14 Z';
}

function iconSvg(): string
{
    $path = sparklePath();

    return <<<SVG
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
      <defs>
        <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stop-color="#7c3aed"/>
          <stop offset="1" stop-color="#ec4899"/>
        </linearGradient>
      </defs>
      <rect width="100" height="100" rx="22" fill="url(#g)"/>
      <path d="{$path}" fill="#fff"/>
    </svg>

    SVG;
}

/** Points of a quadratic Bézier from $a to $b with control $c. */
function quad(array $a, array $c, array $b, int $steps = 24): array
{
    $points = [];
    for ($i = 0; $i <= $steps; $i++) {
        $t = $i / $steps;
        $points[] = (1 - $t) ** 2 * $a[0] + 2 * (1 - $t) * $t * $c[0] + $t ** 2 * $b[0];
        $points[] = (1 - $t) ** 2 * $a[1] + 2 * (1 - $t) * $t * $c[1] + $t ** 2 * $b[1];
    }

    return $points;
}

/** The star polygon (center cx, cy; arm length r; waist w) as flat GD points. */
function star(float $cx, float $cy, float $r, float $w): array
{
    $top = [$cx, $cy - $r];
    $right = [$cx + $r, $cy];
    $bottom = [$cx, $cy + $r];
    $left = [$cx - $r, $cy];

    return array_map('intval', array_merge(
        quad($top, [$cx + $w, $cy - $w], $right),
        quad($right, [$cx + $w, $cy + $w], $bottom),
        quad($bottom, [$cx - $w, $cy + $w], $left),
        quad($left, [$cx - $w, $cy - $w], $top),
    ));
}

function gradient(GdImage $im, int $w, int $h): void
{
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x += 2) {
            $t = ($x / max(1, $w - 1) + $y / max(1, $h - 1)) / 2;
            $c = array_map(fn ($a, $b) => (int) round($a + ($b - $a) * $t), FROM, TO);
            imagefilledrectangle($im, $x, $y, $x + 1, $y, imagecolorallocate($im, ...$c));
        }
    }
}

/** Draws the icon at $size px, supersampled 4x for smooth edges. $rounded false gives a full square (iOS). */
function iconPng(int $size, bool $rounded = true): GdImage
{
    $s = $size * 4;
    $big = imagecreatetruecolor($s, $s);
    imagesavealpha($big, true);
    imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127));
    gradient($big, $s, $s);

    if ($rounded) {
        // Cut the corners back to transparent outside a radius of 22%.
        $r = (int) round($s * 0.22);
        $clear = imagecolorallocatealpha($big, 0, 0, 0, 127);
        imagealphablending($big, false);
        foreach ([[$r, $r], [$s - $r - 1, $r], [$r, $s - $r - 1], [$s - $r - 1, $s - $r - 1]] as [$cx, $cy]) {
            for ($y = 0; $y < $r; $y++) {
                for ($x = 0; $x < $r; $x++) {
                    $px = $cx < $s / 2 ? $x : $s - 1 - $x;
                    $py = $cy < $s / 2 ? $y : $s - 1 - $y;
                    if (($px - $cx) ** 2 + ($py - $cy) ** 2 > $r ** 2) {
                        imagesetpixel($big, $px, $py, $clear);
                    }
                }
            }
        }
        imagealphablending($big, true);
    }

    $white = imagecolorallocate($big, 255, 255, 255);
    imagefilledpolygon($big, star($s * 0.5, $s * 0.5, $s * 0.36, $s * 0.05), $white);
    imagefilledpolygon($big, star($s * 0.76, $s * 0.24, $s * 0.10, $s * 0.02), $white);

    $out = imagecreatetruecolor($size, $size);
    imagesavealpha($out, true);
    imagealphablending($out, false);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $big, 0, 0, 0, 0, $size, $size, $s, $s);

    return $out;
}

function pngBytes(GdImage $im): string
{
    ob_start();
    imagepng($im, null, 9);

    return (string) ob_get_clean();
}

/** An .ico that embeds PNG images (supported by every current browser). */
function ico(array $sizes): string
{
    $images = array_map(fn ($size) => pngBytes(iconPng($size)), $sizes);
    $header = pack('vvv', 0, 1, count($images));
    $offset = 6 + 16 * count($images);
    $dir = '';
    foreach ($images as $i => $png) {
        $size = $sizes[$i] >= 256 ? 0 : $sizes[$i];
        $dir .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($png), $offset);
        $offset += strlen($png);
    }

    return $header.$dir.implode('', $images);
}

function ogImage(): GdImage
{
    [$w, $h] = [1200, 630];
    $im = imagecreatetruecolor($w, $h);
    gradient($im, $w, $h);
    imagealphablending($im, true);

    // Soft decoration.
    foreach ([[1080, 90, 300], [1010, 560, 420], [120, 600, 260]] as [$cx, $cy, $d]) {
        imagefilledellipse($im, $cx, $cy, $d, $d, imagecolorallocatealpha($im, 255, 255, 255, 110));
    }

    $icon = iconPng(200);
    imagecopy($im, $icon, 100, 215, 0, 0, 200, 200);

    $white = imagecolorallocate($im, 255, 255, 255);
    imagettftext($im, 100, 0, 345, 335, $white, LOGO_FONT, 'viSparkle');
    imagettftext($im, 30, 0, 352, 405, imagecolorallocatealpha($im, 255, 255, 255, 15), JP_FONT, TAGLINE);

    return $im;
}

file_put_contents(PUBLIC_DIR.'/favicon.svg', iconSvg());
file_put_contents(PUBLIC_DIR.'/favicon.ico', ico([16, 32, 48]));
imagepng(iconPng(180, rounded: false), PUBLIC_DIR.'/apple-touch-icon.png', 9);
imagepng(iconPng(512), PUBLIC_DIR.'/icon-512.png', 9);
@mkdir(PUBLIC_DIR.'/images', 0755, true);
imagepng(ogImage(), PUBLIC_DIR.'/images/og.png', 9);

foreach (['favicon.svg', 'favicon.ico', 'apple-touch-icon.png', 'icon-512.png', 'images/og.png'] as $file) {
    echo $file, ' ', filesize(PUBLIC_DIR.'/'.$file), " bytes\n";
}
