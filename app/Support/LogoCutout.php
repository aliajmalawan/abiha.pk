<?php

namespace App\Support;

/**
 * Removes a solid black background from a logo that was exported without transparency (a JPG, or a PNG with a black fill).
 *
 * Only black that is CONNECTED to the picture's edge is removed (flood fill), so the dark parts inside the artwork (the black letters)
 * stay. The soft rim between artwork and background is made partly transparent and un-mixed from black, so there is no dark outline
 * when the logo sits on white. Does nothing when the picture already has transparency or its corners are not black.
 */
final class LogoCutout
{
    private const BLACK = 30;     // a pixel is background when its brightest channel is at or below this
    private const SOFT = 70;      // rim pixels up to this brightness become partly transparent
    private const CLOSE = 3;      // artwork is thickened by this many quarter-size cells before searching
    private const RIM = 3;        // how many pixels from the removed area count as rim

    public static function apply(\GdImage $img): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 16 || $h < 16 || ! imageistruecolor($img)) {
            $tc = imagecreatetruecolor($w, $h);
            imagecopy($tc, $img, 0, 0, 0, 0, $w, $h);
            $img = $tc;
        }
        if (! self::cornersAreBlackAndOpaque($img, $w, $h)) {
            return $img;
        }

        $bg = self::background($img, $w, $h);
        // Rim: distance (in steps) from the removed area, up to RIM.
        $dist = new \SplFixedArray($w * $h);
        $frontier = [];
        for ($i = 0; $i < $w * $h; $i++) {
            if ($bg[$i] === 1) {
                $dist[$i] = 0;
                $frontier[] = $i;
            }
        }
        for ($d = 1; $d <= self::RIM && $frontier; $d++) {
            $next = [];
            foreach ($frontier as $i) {
                $x = $i % $w;
                $y = intdiv($i, $w);
                foreach ([[$x - 1, $y], [$x + 1, $y], [$x, $y - 1], [$x, $y + 1]] as [$nx, $ny]) {
                    if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                        continue;
                    }
                    $j = $ny * $w + $nx;
                    if ($dist[$j] === null) {
                        $dist[$j] = $d;
                        $next[] = $j;
                    }
                }
            }
            $frontier = $next;
        }

        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $clear = imagecolorallocatealpha($out, 0, 0, 0, 127);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $i = $y * $w + $x;
                $c = imagecolorat($img, $x, $y);
                $r = ($c >> 16) & 255;
                $g = ($c >> 8) & 255;
                $b = $c & 255;
                if ($bg[$i] === 1) {
                    imagesetpixel($out, $x, $y, $clear);
                    continue;
                }
                if ($dist[$i] !== null) {                       // rim: un-mix from black
                    $p = max($r, $g, $b);
                    if ($p < self::SOFT) {
                        $a = max(0.0, min(1.0, $p / self::SOFT));
                        if ($a < 0.04) {
                            imagesetpixel($out, $x, $y, $clear);
                            continue;
                        }
                        $r = (int) min(255, round($r / $a));
                        $g = (int) min(255, round($g / $a));
                        $b = (int) min(255, round($b / $a));
                        imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, (int) round(127 * (1 - $a))));
                        continue;
                    }
                }
                imagesetpixel($out, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }

        return $out;
    }

    /**
     * Which pixels are background: black pixels connected to the edge. The search runs on a quarter-size map in which the artwork is
     * thickened a little, so a thin gap in the artwork's rim cannot let the search wander into its black letters; the result is then
     * brought back to full size and pulled up to the real artwork edge.
     */
    private static function background(\GdImage $img, int $w, int $h): \SplFixedArray
    {
        $k = 4;                                   // map is 1/k of the size
        $gw = intdiv($w + $k - 1, $k);
        $gh = intdiv($h + $k - 1, $k);
        $dark = new \SplFixedArray($gw * $gh);    // 1 = every pixel of the cell is black
        for ($gy = 0; $gy < $gh; $gy++) {
            for ($gx = 0; $gx < $gw; $gx++) {
                $ok = 1;
                for ($y = $gy * $k; $y < min($h, ($gy + 1) * $k) && $ok; $y++) {
                    for ($x = $gx * $k; $x < min($w, ($gx + 1) * $k); $x++) {
                        if (self::peak(imagecolorat($img, $x, $y)) > self::BLACK) {
                            $ok = 0;
                            break;
                        }
                    }
                }
                $dark[$gy * $gw + $gx] = $ok;
            }
        }
        // Thicken the artwork (cells that are not all black) by CLOSE cells.
        $open = new \SplFixedArray($gw * $gh);
        for ($gy = 0; $gy < $gh; $gy++) {
            for ($gx = 0; $gx < $gw; $gx++) {
                $open[$gy * $gw + $gx] = $dark[$gy * $gw + $gx];
            }
        }
        for ($gy = 0; $gy < $gh; $gy++) {
            for ($gx = 0; $gx < $gw; $gx++) {
                if ($dark[$gy * $gw + $gx] === 0) {
                    for ($dy = -self::CLOSE; $dy <= self::CLOSE; $dy++) {
                        for ($dx = -self::CLOSE; $dx <= self::CLOSE; $dx++) {
                            $nx = $gx + $dx;
                            $ny = $gy + $dy;
                            if ($nx >= 0 && $ny >= 0 && $nx < $gw && $ny < $gh) {
                                $open[$ny * $gw + $nx] = 0;
                            }
                        }
                    }
                }
            }
        }
        // Flood from the edge cells.
        $reach = new \SplFixedArray($gw * $gh);
        $stack = [];
        $seed = function (int $gx, int $gy) use ($open, &$reach, &$stack, $gw): void {
            $i = $gy * $gw + $gx;
            if ($open[$i] === 1 && $reach[$i] !== 1) {
                $reach[$i] = 1;
                $stack[] = $i;
            }
        };
        for ($gx = 0; $gx < $gw; $gx++) { $seed($gx, 0); $seed($gx, $gh - 1); }
        for ($gy = 0; $gy < $gh; $gy++) { $seed(0, $gy); $seed($gw - 1, $gy); }
        while ($stack) {
            $i = array_pop($stack);
            $gx = $i % $gw;
            $gy = intdiv($i, $gw);
            if ($gx > 0) { $seed($gx - 1, $gy); }
            if ($gx < $gw - 1) { $seed($gx + 1, $gy); }
            if ($gy > 0) { $seed($gx, $gy - 1); }
            if ($gy < $gh - 1) { $seed($gx, $gy + 1); }
        }
        // Full size: a black pixel is background when its cell was reached, or it is black and connected, within CLOSE cells, to one that was.
        $bg = new \SplFixedArray($w * $h);
        $limit = (self::CLOSE + 1) * $k;
        $queue = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($reach[intdiv($y, $k) * $gw + intdiv($x, $k)] === 1 && self::peak(imagecolorat($img, $x, $y)) <= self::BLACK) {
                    $bg[$y * $w + $x] = 1;
                    $queue[] = $y * $w + $x;
                }
            }
        }
        for ($step = 0; $step < $limit && $queue; $step++) {      // grow through black pixels up to the real artwork edge
            $next = [];
            foreach ($queue as $i) {
                $x = $i % $w;
                $y = intdiv($i, $w);
                foreach ([[$x - 1, $y], [$x + 1, $y], [$x, $y - 1], [$x, $y + 1]] as [$nx, $ny]) {
                    if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                        continue;
                    }
                    $j = $ny * $w + $nx;
                    if ($bg[$j] !== 1 && self::peak(imagecolorat($img, $nx, $ny)) <= self::BLACK) {
                        $bg[$j] = 1;
                        $next[] = $j;
                    }
                }
            }
            $queue = $next;
        }

        return $bg;
    }

    private static function peak(int $c): int
    {
        return max(($c >> 16) & 255, ($c >> 8) & 255, $c & 255);
    }

    private static function cornersAreBlackAndOpaque(\GdImage $img, int $w, int $h): bool
    {
        foreach ([[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1], [intdiv($w, 2), 0], [intdiv($w, 2), $h - 1]] as [$x, $y]) {
            $c = imagecolorat($img, $x, $y);
            if ((($c >> 24) & 127) !== 0 || self::peak($c) > self::BLACK) {
                return false;
            }
        }

        return true;
    }
}
