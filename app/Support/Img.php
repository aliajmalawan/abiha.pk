<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Responsive image variants for files on the public disk.
 *
 * For `products/abc.jpg` we also keep `products/abc-w320.webp`, `-w480`, `-w640` … (only widths smaller than the
 * original). The original stays as the fallback <img>, so a browser without WebP, or an image with no variants yet
 * (uploaded before this existed), still works: nothing here is ever required for a page to render.
 */
class Img
{
    public const WIDTHS = [320, 480, 640, 960, 1280, 1600];
    public const QUALITY = 80;

    public static function variantPath(string $path, int $width): string
    {
        return preg_replace('/\.[A-Za-z0-9]+$/', '', $path)."-w{$width}.webp";
    }

    /** Create the WebP variants for an already-stored original. @return int[] widths written */
    public static function generate(string $path): array
    {
        $disk = Storage::disk('public');
        self::forget($path);
        if (! function_exists('imagewebp') || ! $disk->exists($path)) {
            return [];
        }
        $src = @imagecreatefromstring((string) $disk->get($path));
        if (! $src) {
            return [];
        }
        imagepalettetotruecolor($src);
        imagealphablending($src, false);
        imagesavealpha($src, true);
        $transparent = self::hasTransparency($src);
        $w = imagesx($src);
        $h = imagesy($src);
        $made = [];

        try {
            foreach (self::WIDTHS as $width) {
                if ($width >= $w) {
                    break;                                          // never upscale; the original covers this size
                }
                $scaled = self::resample($src, $width, (int) round($h * $width / $w));
                if (! $scaled) {
                    continue;
                }
                if ($transparent) {
                    self::clearHaze($scaled);
                }
                ob_start();
                imagewebp($scaled, null, self::QUALITY);
                $disk->put(self::variantPath($path, $width), (string) ob_get_clean());
                $made[] = $width;
            }
        } finally {
            unset($src);
        }
        self::forget($path);
        Cache::forever('img.v.'.md5($path), (string) time());   // new URLs, so browsers drop the old (possibly cached) files

        return $made;
    }

    /**
     * Smaller copy of a picture. GD's bicubic imagescale() overshoots on sharp edges (hair, text, a logo outline) and leaves
     * coloured specks around them in photographs; imagecopyresampled() averages the source pixels and does not. Transparency is kept.
     */
    private static function resample(\GdImage $src, int $width, int $height): \GdImage|false
    {
        $dst = imagecreatetruecolor($width, $height);
        if (! $dst) {
            return false;
        }
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $width, $height, imagesx($src), imagesy($src));

        return $dst;
    }

    /** True when the picture has see-through pixels (a logo or cut-out). Photos have none. */
    private static function hasTransparency(\GdImage $img): bool
    {
        for ($y = 0; $y < imagesy($img); $y += 16) {
            for ($x = 0; $x < imagesx($img); $x += 16) {
                if (((imagecolorat($img, $x, $y) >> 24) & 127) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * GD's bicubic resize turns fully transparent areas into a faint haze (alpha 123 instead of 127), and lossy WebP keeps it, which
     * shows as a grey box behind a logo on a white page. After resizing, pixels that are more than ~80% see-through are made fully
     * transparent again. Only used for pictures that really have transparency.
     */
    private static function clearHaze(\GdImage $img): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $clear = imagecolorallocatealpha($img, 0, 0, 0, 127);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (((imagecolorat($img, $x, $y) >> 24) & 127) >= 100) {      // GD alpha: 0 = opaque, 127 = transparent
                    imagesetpixel($img, $x, $y, $clear);
                }
            }
        }
    }

    /** Remove an image and every variant of it. */
    public static function delete(?string $path): void
    {
        if (! $path) {
            return;
        }
        $disk = Storage::disk('public');
        $disk->delete(array_merge([$path], array_map(fn ($w) => self::variantPath($path, $w), self::WIDTHS)));
        self::forget($path);
    }

    /** Widths that exist on disk (cached: one filesystem check per image, not per request). @return int[] */
    public static function widths(string $path): array
    {
        return Cache::rememberForever('img.w.'.md5($path), function () use ($path) {
            $disk = Storage::disk('public');

            return array_values(array_filter(self::WIDTHS, fn ($w) => $disk->exists(self::variantPath($path, $w))));
        });
    }

    public static function srcset(string $path): ?string
    {
        $widths = self::widths($path);
        if ($widths === []) {
            return null;
        }
        // The original stays out of the WebP srcset: it is the <img> fallback for browsers without WebP.
        return implode(', ', array_map(fn ($w) => self::url($path, self::variantPath($path, $w)).' '.$w.'w', $widths));
    }

    /**
     * Public URL of an image (or one of its variants) with a version in the query string. Browsers keep image files for a long time,
     * so when a picture is regenerated in place the URL must change or visitors keep seeing the old one.
     */
    public static function url(string $path, ?string $file = null): string
    {
        return Storage::disk('public')->url($file ?? $path).'?v='.self::version($path);
    }

    private static function version(string $path): string
    {
        return Cache::rememberForever('img.v.'.md5($path), function () use ($path) {
            $disk = Storage::disk('public');

            return (string) ($disk->exists($path) ? $disk->lastModified($path) : 0);
        });
    }

    /** @return array{0:int,1:int}|null natural width and height, so the browser can reserve space (no layout shift) */
    public static function dimensions(string $path): ?array
    {
        return Cache::rememberForever('img.d.'.md5($path), function () use ($path) {
            $disk = Storage::disk('public');
            if (! $disk->exists($path)) {
                return null;
            }
            $info = @getimagesize($disk->path($path));

            return $info ? [(int) $info[0], (int) $info[1]] : null;
        });
    }

    private static function forget(string $path): void
    {
        Cache::forget('img.w.'.md5($path));
        Cache::forget('img.d.'.md5($path));
        Cache::forget('img.v.'.md5($path));
    }
}
