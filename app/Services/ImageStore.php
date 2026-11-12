<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\ValidationException;
use App\Support\FaviconCutout;
use App\Support\Img;
use App\Support\LogoCutout;

/**
 * The only way uploaded images reach the public disk (port of the old App\Services\ImageStore). For every upload it:
 *  1. checks the real file signature (never the client's filename or Content-Type),
 *  2. refuses decompression bombs (pixel limit) before decoding,
 *  3. DECODES and RE-ENCODES the pixels, which discards EXIF/GPS metadata and anything smuggled after the image data,
 *  4. honours the JPEG EXIF orientation and downsizes very large images ($maxEdge),
 *  5. saves under a random 40-character name with an extension chosen from the detected type, then writes the
 *     responsive WebP variants (Img::generate).
 *
 *   ImageStore::store($request->file('image'), 'products', 'image');            // "products/AbC...40.jpg"
 *   ImageStore::store($file, 'branding', 'logo', 1600, cutoutBlack: true);      // site logo exported on black
 *   ImageStore::storeIcon($file, 'branding', 'favicon');                        // .ico kept as is, PNG: white cut out
 *
 * $file is one $_FILES entry (Request::file()). In the CLI/tests a plain temp file is accepted.
 */
final class ImageStore
{
    public const MAX_PIXELS = 40_000_000;          // ~ 6300 x 6300
    private const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    /** @return string path on the public disk, e.g. products/Ab3....jpg */
    public static function store(array $file, string $dir, string $field = 'image', int $maxEdge = 2400, bool $cutoutBlack = false, bool $cutoutWhite = false): string
    {
        $real = self::realPath($file, $field);
        $info = @getimagesize($real);
        if (!$info || !isset(self::TYPES[$info[2]])) {
            throw self::fail($field, 'That file is not a valid JPG, PNG or WebP image.');
        }
        [$w, $h, $type] = $info;
        if ($w < 1 || $h < 1 || $w * $h > self::MAX_PIXELS) {
            throw self::fail($field, 'That image is too large. Please upload one under 6000 × 6000 pixels.');
        }
        if ($type === IMAGETYPE_WEBP && !function_exists('imagewebp')) {
            throw self::fail($field, 'WebP is not supported on this server. Please upload a JPG or PNG.');
        }

        $img = @imagecreatefromstring((string) file_get_contents($real));
        if (!$img) {
            throw self::fail($field, 'That image could not be read. It may be damaged.');
        }

        $img = self::orient($img, $real, $type);
        if (max(imagesx($img), imagesy($img)) > $maxEdge) {
            $img = self::fit($img, $maxEdge) ?: $img;
        }

        if ($cutoutBlack) {                                        // logo exported on a solid black background
            $small = max(imagesx($img), imagesy($img)) > 800 ? (self::fit($img, 800) ?: $img) : $img;
            $cut = LogoCutout::apply($small);
            if ($cut !== $small) {
                $img = $cut;
                $type = IMAGETYPE_PNG;
            }
        }
        if ($cutoutWhite) {                                        // icon on a plain white background
            $cut = FaviconCutout::apply($img);
            if ($cut !== $img) {
                $img = $cut;
                $type = IMAGETYPE_PNG;
            }
        }

        ob_start();
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            imagepng($img, null, 6);
        } elseif ($type === IMAGETYPE_WEBP) {
            imagewebp($img, null, 85);
        } else {
            imagejpeg($img, null, 85);
        }
        $bytes = (string) ob_get_clean();

        $path = trim(self::cleanDir($dir), '/') . '/' . self::random(40) . '.' . self::TYPES[$type];
        self::put($path, $bytes);
        Img::generate($path);                                       // responsive WebP variants (never needed for the page to work)
        return $path;
    }

    /** Favicons may be .ico, which GD cannot re-encode: accept only a genuine ICO header and a small size. */
    public static function storeIcon(array $file, string $dir, string $field = 'favicon'): string
    {
        $real = self::realPath($file, $field);
        $head = (string) file_get_contents($real, false, null, 0, 4);
        if ($head === "\x00\x00\x01\x00") {                         // ICO signature
            if ((filesize($real) ?: 0) > 512 * 1024) {
                throw self::fail($field, 'The icon file is too large (512 KB maximum).');
            }
            $path = trim(self::cleanDir($dir), '/') . '/' . self::random(40) . '.ico';
            self::put($path, (string) file_get_contents($real));
            return $path;
        }
        return self::store($file, $dir, $field, 512, false, true);  // PNG favicons are re-encoded like any image
    }

    private static function realPath(array $file, string $field): string
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($err !== UPLOAD_ERR_OK) {
            throw self::fail($field, $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
                ? 'The ' . str_replace('_', ' ', $field) . ' failed to upload.'
                : 'The ' . str_replace('_', ' ', $field) . ' failed to upload.');
        }
        $real = (string) ($file['tmp_name'] ?? '');
        if ($real === '' || !is_file($real) || (PHP_SAPI !== 'cli' && !is_uploaded_file($real))) {
            throw self::fail($field, 'The ' . str_replace('_', ' ', $field) . ' failed to upload.');
        }
        $max = (int) Config::get('uploads.max_image_bytes', 8 * 1024 * 1024);
        if ((filesize($real) ?: 0) > $max) {
            throw self::fail($field, 'The ' . str_replace('_', ' ', $field) . ' field must not be greater than ' . intdiv($max, 1024) . ' kilobytes.');
        }
        return $real;
    }

    /** Honour JPEG EXIF orientation before the metadata is thrown away, so phone photos are not stored sideways. */
    private static function orient(\GdImage $img, string $path, int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $rotate = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        return $rotate ? (imagerotate($img, $rotate, 0) ?: $img) : $img;
    }

    private static function fit(\GdImage $img, int $edge): \GdImage|false
    {
        $w = imagesx($img);
        $h = imagesy($img);
        return $w >= $h ? imagescale($img, $edge, (int) round($h * $edge / $w)) : imagescale($img, (int) round($w * $edge / $h), $edge);
    }

    private static function put(string $rel, string $bytes): void
    {
        $abs = Img::path($rel);
        $dir = dirname($abs);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the upload folder.');
        }
        if (file_put_contents($abs, $bytes) === false) {
            throw new \RuntimeException('Cannot store the image.');
        }
    }

    private static function cleanDir(string $dir): string
    {
        return (string) preg_replace('#[^A-Za-z0-9/_-]#', '', $dir) ?: 'uploads';
    }

    /** Laravel's Str::random: letters and digits. */
    private static function random(int $length): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, 61)];
        }
        return $out;
    }

    private static function fail(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
