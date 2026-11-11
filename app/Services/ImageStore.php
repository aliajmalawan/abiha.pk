<?php

namespace App\Services;

use App\Support\Img;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only way uploaded images reach disk. For every upload it:
 *  1. checks the real file signature (never the client's filename or Content-Type),
 *  2. refuses decompression bombs (pixel limit) before decoding,
 *  3. DECODES and RE-ENCODES the pixels, which discards EXIF/GPS metadata and any code smuggled after the image
 *     data (a "polyglot" JPEG that is also a PHP script comes out as a plain image),
 *  4. downsizes very large images, and
 *  5. saves under a random server-generated name with an extension chosen from the detected type.
 */
class ImageStore
{
    public const MAX_PIXELS = 40_000_000;          // ~ 6300 x 6300
    private const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    /** @return string path on the public disk, e.g. products/Ab3….jpg */
    public function store(UploadedFile $file, string $dir, string $field = 'image', int $maxEdge = 2400, bool $cutoutBlack = false, bool $cutoutWhite = false): string
    {
        $real = $file->getRealPath();
        $info = $real ? @getimagesize($real) : false;
        if (! $info || ! isset(self::TYPES[$info[2]])) {
            throw $this->fail($field, 'That file is not a valid JPG, PNG or WebP image.');
        }
        [$w, $h, $type] = $info;
        if ($w < 1 || $h < 1 || $w * $h > self::MAX_PIXELS) {
            throw $this->fail($field, 'That image is too large. Please upload one under 6000 × 6000 pixels.');
        }
        if ($type === IMAGETYPE_WEBP && ! function_exists('imagewebp')) {
            throw $this->fail($field, 'WebP is not supported on this server. Please upload a JPG or PNG.');
        }

        $img = @imagecreatefromstring((string) file_get_contents($real));
        if (! $img) {
            throw $this->fail($field, 'That image could not be read. It may be damaged.');
        }

        try {
            $img = $this->orient($img, $real, $type);
            if (max(imagesx($img), imagesy($img)) > $maxEdge) {
                $scaled = imagescale($img, imagesx($img) >= imagesy($img) ? $maxEdge : (int) round(imagesx($img) * $maxEdge / imagesy($img)), imagesx($img) >= imagesy($img) ? (int) round(imagesy($img) * $maxEdge / imagesx($img)) : $maxEdge);
                if ($scaled) {
                    $img = $scaled;
                }
            }

            if ($cutoutBlack) {                                        // logo exported on a solid black background: make that background transparent
                $small = max(imagesx($img), imagesy($img)) > 800 ? imagescale($img, imagesx($img) >= imagesy($img) ? 800 : (int) round(imagesx($img) * 800 / imagesy($img)), imagesx($img) >= imagesy($img) ? (int) round(imagesy($img) * 800 / imagesx($img)) : 800) : $img;
                $cut = \App\Support\LogoCutout::apply($small ?: $img);
                if ($cut !== ($small ?: $img)) {
                    $img = $cut;
                    $type = IMAGETYPE_PNG;
                }
            }

            if ($cutoutWhite) {                                        // icon on a plain white background: make that white transparent
                $cut = \App\Support\FaviconCutout::apply($img);
                if ($cut !== $img) {
                    $img = $cut;
                    $type = IMAGETYPE_PNG;
                }
            }

            ob_start();
            match ($type) {
                IMAGETYPE_PNG => (function () use ($img) { imagealphablending($img, false); imagesavealpha($img, true); imagepng($img, null, 6); })(),
                IMAGETYPE_WEBP => imagewebp($img, null, 85),
                default => imagejpeg($img, null, 85),
            };
            $bytes = (string) ob_get_clean();
        } finally {
            unset($img);
        }

        $path = trim($dir, '/').'/'.Str::random(40).'.'.self::TYPES[$type];
        Storage::disk('public')->put($path, $bytes);
        Img::generate($path);                                       // responsive WebP variants (never needed for the page to work)

        return $path;
    }

    /** Favicons may be .ico, which GD cannot re-encode; accept only a genuine ICO header and a small size. */
    public function storeIcon(UploadedFile $file, string $dir, string $field = 'favicon'): string
    {
        $real = $file->getRealPath();
        $head = $real ? (string) file_get_contents($real, false, null, 0, 4) : '';

        if ($head === "\x00\x00\x01\x00") {                                  // ICO signature
            if ($file->getSize() > 512 * 1024) {
                throw $this->fail($field, 'The icon file is too large (512 KB maximum).');
            }
            $path = trim($dir, '/').'/'.Str::random(40).'.ico';
            Storage::disk('public')->put($path, (string) file_get_contents($real));

            return $path;
        }

        return $this->store($file, $dir, $field, 512, false, true);                          // PNG favicons are re-encoded like any image
    }

    /** Honour JPEG EXIF orientation before the metadata is thrown away, so phone photos are not stored sideways. */
    private function orient(\GdImage $img, string $path, int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $rotate = match ((int) ($exif['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };

        return $rotate ? (imagerotate($img, $rotate, 0) ?: $img) : $img;
    }

    private function fail(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
