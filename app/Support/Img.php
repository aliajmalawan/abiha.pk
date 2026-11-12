<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Files on the PUBLIC disk (uploads) and their responsive WebP variants, the port of the old App\Support\Img and
 * <x-picture>.
 *
 * The public disk is cfg('filesystems.public_root') = storage/app/public by default, served at
 * cfg('filesystems.public_url') = APP_URL/storage (public/storage is a symlink/junction to it; hosts without symlinks set
 * PUBLIC_DISK_ROOT to a folder inside the web root). Paths stored in the database are relative to it, e.g.
 * "products/Lq7S....jpg", "branding/9lEk....png".
 *
 * For "products/abc.jpg" we also keep "products/abc-w320.webp", -w480, -w640 ... (only widths smaller than the original).
 * The original stays as the <img> fallback, so a browser without WebP, or an image without variants, still works.
 *
 *   Img::url($path)                 original, absolute URL with ?v=<mtime> (changes when the file is regenerated)
 *   Img::src($path, 480)            the smallest WebP variant >= 480 px wide, else the original (helper media())
 *   Img::srcset($path)              "url 320w, url 480w" of the existing variants, or null (helper media_srcset())
 *   Img::dimensions($path)          [w, h] or null
 *   Img::picture($path, $alt, '(min-width: 1024px) 25vw, 50vw', $eager, $priority)   <picture> markup
 *   Img::generate($path) / Img::delete($path) / Img::exists($path) / Img::path($path) (absolute file path)
 */
final class Img
{
    public const WIDTHS = [320, 480, 640, 960, 1280, 1600];
    public const QUALITY = 80;

    /** @var array<string,array{w:list<int>,d:?array,v:string}> per-request facts about files */
    private static array $facts = [];

    public static function root(): string
    {
        return (string) Config::get('filesystems.public_root');
    }

    /** Absolute file path on the public disk (rejects "..", backslashes and absolute paths). */
    public static function path(string $rel): string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            throw new \InvalidArgumentException('Invalid storage path.');
        }
        return self::root() . '/' . $rel;
    }

    public static function exists(?string $rel): bool
    {
        if (!$rel) {
            return false;
        }
        try {
            return is_file(self::path($rel));
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public static function variantPath(string $path, int $width): string
    {
        return preg_replace('/\.[A-Za-z0-9]+$/', '', $path) . "-w{$width}.webp";
    }

    /** Public URL of a file on the disk, without version. */
    public static function publicUrl(string $rel): string
    {
        return rtrim((string) Config::get('filesystems.public_url'), '/') . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($rel, '/'))));
    }

    /** Original image URL with a version in the query string (browsers cache images for a long time). */
    public static function url(string $path, ?string $file = null): string
    {
        return self::publicUrl($file ?? $path) . '?v=' . self::facts($path)['v'];
    }

    /** Best URL for about $width CSS pixels: the smallest WebP variant that is at least that wide, else the original. */
    public static function src(?string $path, ?int $width = null): string
    {
        if (!$path) {
            return '';
        }
        if (preg_match('#^(https?:)?//#i', $path)) {
            return $path;
        }
        if ($width !== null) {
            foreach (self::widths($path) as $w) {
                if ($w >= $width) {
                    return self::url($path, self::variantPath($path, $w));
                }
            }
        }
        return self::url($path);
    }

    /** @return list<int> widths whose WebP variant exists */
    public static function widths(string $path): array
    {
        return self::facts($path)['w'];
    }

    public static function srcset(string $path): ?string
    {
        $widths = self::widths($path);
        if ($widths === []) {
            return null;
        }
        // The original stays out of the WebP srcset: it is the <img> fallback for browsers without WebP.
        return implode(', ', array_map(static fn ($w) => self::url($path, self::variantPath($path, $w)) . ' ' . $w . 'w', $widths));
    }

    /** @return array{0:int,1:int}|null natural width and height, so the browser can reserve space (no layout shift) */
    public static function dimensions(string $path): ?array
    {
        return self::facts($path)['d'];
    }

    /**
     * <picture> with the WebP variants and the original as fallback (port of <x-picture>).
     *
     * @param array<string,string> $attrs extra <img> attributes, e.g. ['class' => 'size-full object-cover']
     */
    public static function picture(string $path, string $alt = '', string $sizes = '100vw', bool $eager = false, bool $priority = false, array $attrs = []): string
    {
        $srcset = self::srcset($path);
        $dim = self::dimensions($path);
        $extra = '';
        foreach ($attrs as $k => $v) {
            $extra .= ' ' . preg_replace('/[^A-Za-z0-9_:.@-]/', '', (string) $k) . '="' . e($v) . '"';
        }
        return '<picture>'
            . ($srcset ? '<source type="image/webp" srcset="' . e($srcset) . '" sizes="' . e($sizes) . '">' : '')
            . '<img src="' . e(self::url($path)) . '" alt="' . e($alt) . '"' . ($dim ? ' width="' . $dim[0] . '" height="' . $dim[1] . '"' : '')
            . ' loading="' . ($eager || $priority ? 'eager' : 'lazy') . '" decoding="async"' . ($priority ? ' fetchpriority="high"' : '') . $extra . '>'
            . '</picture>';
    }

    /** Creates the WebP variants of an already-stored original. @return list<int> widths written */
    public static function generate(string $path): array
    {
        self::forget($path);
        if (!function_exists('imagewebp') || !self::exists($path)) {
            return [];
        }
        $src = @imagecreatefromstring((string) file_get_contents(self::path($path)));
        if (!$src) {
            return [];
        }
        imagepalettetotruecolor($src);
        imagealphablending($src, false);
        imagesavealpha($src, true);
        $transparent = self::hasTransparency($src);
        $w = imagesx($src);
        $h = imagesy($src);
        $made = [];
        foreach (self::WIDTHS as $width) {
            if ($width >= $w) {
                break;                                          // never upscale; the original covers this size
            }
            $scaled = self::resample($src, $width, (int) round($h * $width / $w));
            if (!$scaled) {
                continue;
            }
            if ($transparent) {
                self::clearHaze($scaled);
            }
            if (imagewebp($scaled, self::path(self::variantPath($path, $width)), self::QUALITY)) {
                $made[] = $width;
            }
        }
        // New mtime of the original => new ?v= so browsers drop old (cached) variant files.
        @touch(self::path($path));
        clearstatcache();
        self::forget($path);
        return $made;
    }

    /** Removes an image and every variant of it. */
    public static function delete(?string $path): void
    {
        if (!$path) {
            return;
        }
        foreach (array_merge([$path], array_map(static fn ($w) => self::variantPath($path, $w), self::WIDTHS)) as $rel) {
            try {
                $abs = self::path($rel);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        self::forget($path);
    }

    public static function forget(?string $path = null): void
    {
        if ($path === null) {
            self::$facts = [];
        } else {
            unset(self::$facts[$path]);
        }
    }

    /** @return array{w:list<int>,d:?array,v:string} */
    private static function facts(string $path): array
    {
        if (isset(self::$facts[$path])) {
            return self::$facts[$path];
        }
        $widths = [];
        $dim = null;
        $v = '0';
        if (self::exists($path)) {
            $abs = self::path($path);
            $v = (string) (filemtime($abs) ?: 0);
            $info = @getimagesize($abs);
            $dim = $info ? [(int) $info[0], (int) $info[1]] : null;
            foreach (self::WIDTHS as $w) {
                if (is_file(self::path(self::variantPath($path, $w)))) {
                    $widths[] = $w;
                }
            }
        }
        return self::$facts[$path] = ['w' => $widths, 'd' => $dim, 'v' => $v];
    }

    /** Smaller copy; imagecopyresampled averages pixels (no bicubic halos around hair, text or a logo outline). */
    private static function resample(\GdImage $src, int $width, int $height): \GdImage|false
    {
        $dst = imagecreatetruecolor($width, $height);
        if (!$dst) {
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

    /** Resizing turns fully transparent areas into a faint haze; pixels more than ~80% see-through become fully transparent. */
    private static function clearHaze(\GdImage $img): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $clear = imagecolorallocatealpha($img, 0, 0, 0, 127);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (((imagecolorat($img, $x, $y) >> 24) & 127) >= 100) {
                    imagesetpixel($img, $x, $y, $clear);
                }
            }
        }
    }
}
