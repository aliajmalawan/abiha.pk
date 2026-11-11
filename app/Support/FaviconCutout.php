<?php

namespace App\Support;

/**
 * Makes the plain white background of an icon transparent, so a favicon does not show a white square in a dark browser tab.
 *
 * Only white that is CONNECTED to the picture's edge is removed (flood fill), so white inside the artwork stays. The thin rim between
 * artwork and background becomes partly transparent and is un-mixed from white, so there is no pale halo. Does nothing when the picture
 * already has transparency or its corners are not white.
 */
final class FaviconCutout
{
    private const WHITE = 232;     // background pixels: every colour channel at or above this
    private const SOFT = 170;      // rim pixels with their darkest channel above this become partly transparent
    private const RIM = 2;         // how many pixels from the removed area count as rim

    public static function apply(\GdImage $src): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagecopy($img, $src, 0, 0, 0, 0, $w, $h);

        $px = static function (int $x, int $y) use ($img): array {
            $c = imagecolorat($img, $x, $y);

            return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255, ($c >> 24) & 127];
        };
        $isWhite = static fn (array $p): bool => $p[3] === 0 && min($p[0], $p[1], $p[2]) >= self::WHITE;

        foreach ([[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]] as [$x, $y]) {
            if (! $isWhite($px($x, $y))) {
                return $src;                                   // not an icon on a white background: leave it alone
            }
        }

        // Flood fill from every edge pixel over white pixels.
        $bg = new \SplFixedArray($w * $h);
        $stack = [];
        $push = function (int $x, int $y) use (&$stack, $bg, $w, $h, $px, $isWhite): void {
            if ($x < 0 || $y < 0 || $x >= $w || $y >= $h || $bg[$y * $w + $x] || ! $isWhite($px($x, $y))) {
                return;
            }
            $bg[$y * $w + $x] = 1;
            $stack[] = [$x, $y];
        };
        for ($x = 0; $x < $w; $x++) {
            $push($x, 0);
            $push($x, $h - 1);
        }
        for ($y = 0; $y < $h; $y++) {
            $push(0, $y);
            $push($w - 1, $y);
        }
        while ($stack) {
            [$x, $y] = array_pop($stack);
            $push($x + 1, $y);
            $push($x - 1, $y);
            $push($x, $y + 1);
            $push($x, $y - 1);
        }

        $clear = imagecolorallocatealpha($img, 0, 0, 0, 127);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($bg[$y * $w + $x]) {
                    imagesetpixel($img, $x, $y, $clear);
                    continue;
                }
                if (! self::nearBackground($bg, $x, $y, $w, $h)) {
                    continue;
                }
                [$r, $g, $b] = $px($x, $y);
                $light = min($r, $g, $b);
                if ($light <= self::SOFT) {
                    continue;
                }
                $alpha = max(0.0, min(1.0, 1 - ($light - self::SOFT) / (self::WHITE - self::SOFT)));
                if ($alpha <= 0.02) {
                    imagesetpixel($img, $x, $y, $clear);
                    continue;
                }
                $un = static fn (int $c): int => (int) max(0, min(255, round(($c - 255 * (1 - $alpha)) / $alpha)));
                imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $un($r), $un($g), $un($b), (int) round(127 * (1 - $alpha))));
            }
        }

        return $img;
    }

    private static function nearBackground(\SplFixedArray $bg, int $x, int $y, int $w, int $h): bool
    {
        for ($dy = -self::RIM; $dy <= self::RIM; $dy++) {
            for ($dx = -self::RIM; $dx <= self::RIM; $dx++) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if ($nx >= 0 && $ny >= 0 && $nx < $w && $ny < $h && $bg[$ny * $w + $nx]) {
                    return true;
                }
            }
        }

        return false;
    }
}
