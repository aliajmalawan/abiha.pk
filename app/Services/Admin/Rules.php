<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\ValidationException;
use App\Core\Validator;

/**
 * Validation helpers for the admin forms, on top of App\Core\Validator (which has no wildcard or file rules):
 *
 *   $rules = Rules::expand($input, ['variants.*.sku' => 'required|string|max:80']);   // -> variants.0.sku, variants.1.sku...
 *   $errors = Rules::run($input, $rules);                      // field => message (Laravel's texts), [] when valid
 *   Rules::file($upload, 'images.0', image: true, mimes: ['jpg','jpeg','png','webp'], maxKb: 4096, maxDim: 8000)
 *   Rules::throwIf($errors);                                   // ValidationException when there are any
 *
 * File rules reproduce Laravel's image / mimes / max (kilobytes) / dimensions / file, judged on the real file content
 * (finfo), never the client's name or Content-Type, with Laravel 12's English messages.
 */
final class Rules
{
    /** MIME (from the file content) => extensions Laravel's mimes rule accepts for it. */
    private const EXT = [
        'image/jpeg' => ['jpg', 'jpeg', 'jpe'],
        'image/pjpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
        'image/bmp' => ['bmp'],
        'image/x-ms-bmp' => ['bmp'],
        'image/svg+xml' => ['svg'],
        'image/vnd.microsoft.icon' => ['ico'],
        'image/x-icon' => ['ico'],
        'image/avif' => ['avif'],
    ];

    /** Laravel's `image` rule (without allow_svg). */
    private const IMAGE_MIMES = ['image/jpeg', 'image/pjpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/x-ms-bmp', 'image/avif'];

    /**
     * Replaces "a.*.b" rule keys with one rule per element present in $input (like Laravel's wildcard rules).
     *
     * @param array<string,mixed> $rules
     * @return array<string,mixed>
     */
    public static function expand(array $input, array $rules): array
    {
        $out = [];
        foreach ($rules as $key => $rule) {
            if (!str_contains($key, '*')) {
                $out[$key] = $rule;
                continue;
            }
            foreach (self::paths($input, explode('.', $key)) as $path) {
                $out[$path] = $rule;
            }
        }
        return $out;
    }

    /** @return list<string> concrete dotted paths for a pattern split into segments */
    private static function paths(mixed $data, array $segments, string $prefix = ''): array
    {
        if ($segments === []) {
            return [rtrim($prefix, '.')];
        }
        $seg = array_shift($segments);
        if ($seg === '*') {
            if (!is_array($data)) {
                return [];
            }
            $out = [];
            foreach ($data as $k => $v) {
                $out = array_merge($out, self::paths($v, $segments, $prefix . $k . '.'));
            }
            return $out;
        }
        return self::paths(is_array($data) ? ($data[$seg] ?? null) : null, $segments, $prefix . $seg . '.');
    }

    /** @return array<string,string> field => first message */
    public static function run(array $input, array $rules, array $messages = [], array $attributes = []): array
    {
        return Validator::make($input, self::expand($input, $rules), $messages, $attributes)->errors();
    }

    /** @param array<string,string> $errors */
    public static function throwIf(array $errors): void
    {
        if ($errors) {
            throw new ValidationException($errors);
        }
    }

    /**
     * One uploaded file against Laravel's file rules. Returns the error message, or null when the file passes.
     *
     * @param list<string>|null $mimes allowed extensions (Laravel "mimes:"), judged from the content type
     */
    public static function file(array $file, string $field, bool $image = false, ?array $mimes = null, ?int $maxKb = null, ?int $maxDim = null): ?string
    {
        $l = str_replace('_', ' ', $field);
        $err = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
            return "The {$l} failed to upload.";
        }
        $mime = self::mime($tmp);
        if ($image && !in_array($mime, self::IMAGE_MIMES, true)) {
            return "The {$l} field must be an image.";
        }
        if ($mimes !== null && !array_intersect(self::EXT[$mime] ?? [], $mimes)) {
            return "The {$l} field must be a file of type: " . implode(', ', $mimes) . '.';
        }
        $size = (int) (filesize($tmp) ?: 0);
        if ($maxKb !== null && $size / 1024 > $maxKb) {
            return "The {$l} field must not be greater than {$maxKb} kilobytes.";
        }
        if ($maxDim !== null) {
            $info = @getimagesize($tmp);
            if (!$info || $info[0] > $maxDim || $info[1] > $maxDim) {
                return "The {$l} field has invalid image dimensions.";
            }
        }
        return null;
    }

    /** The product/category/banner photo rule: image, mimes:jpg,jpeg,png,webp, max:4096, dimensions 8000×8000. */
    public static function photo(array $file, string $field): ?string
    {
        return self::file($file, $field, true, ['jpg', 'jpeg', 'png', 'webp'], 4096, 8000);
    }

    private static function mime(string $path): string
    {
        $f = new \finfo(FILEINFO_MIME_TYPE);
        return (string) $f->file($path);
    }
}
