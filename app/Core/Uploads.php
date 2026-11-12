<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Generic upload checks and PRIVATE file storage.
 *
 *  - Images for the website (products, banners, branding, home sections) go through App\Services\ImageStore, never here.
 *  - Private files (bank-transfer payment proofs) are stored on the "local" disk, storage/app/private/<dir>/<random>.<ext>,
 *    exactly like the old Laravel `storeAs(..., 'local')`, and are only ever streamed to authorised staff.
 *
 * A file array is one entry of $_FILES (Request::file('x')).
 */
final class Uploads
{
    /**
     * Basic upload checks: PHP upload error, a real uploaded temp file, size limit.
     * Throws ValidationException on $field.
     */
    public static function check(array $file, int $maxBytes, string $field = 'file'): void
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw ValidationException::one($field, match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The ' . str_replace('_', ' ', $field) . ' field must not be greater than ' . intdiv($maxBytes, 1024) . ' kilobytes.',
                UPLOAD_ERR_PARTIAL => 'The upload did not finish. Please try again.',
                UPLOAD_ERR_NO_FILE => 'The ' . str_replace('_', ' ', $field) . ' field is required.',
                default => 'The ' . str_replace('_', ' ', $field) . ' failed to upload.',
            });
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp))) {
            throw ValidationException::one($field, 'The ' . str_replace('_', ' ', $field) . ' failed to upload.');
        }
        $size = filesize($tmp) ?: 0;
        if ($size <= 0 || $size > $maxBytes) {
            throw ValidationException::one($field, 'The ' . str_replace('_', ' ', $field) . ' field must not be greater than ' . intdiv($maxBytes, 1024) . ' kilobytes.');
        }
    }

    /** Real content type from the file's bytes (never the client's name or Content-Type). */
    public static function mime(string $path): string
    {
        return (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
    }

    /**
     * Stores a private file under storage/app/private/<dir>/ with a random name. Returns the relative path
     * (e.g. "payment-proofs/12/ab12....pdf"), which is what the old site saved in payments.proof_path.
     *
     * @param array<string,string> $allowed mime => extension, e.g. ['image/jpeg' => 'jpg', 'application/pdf' => 'pdf']
     */
    public static function storePrivate(array $file, string $dir, array $allowed, int $maxBytes, string $field = 'file'): string
    {
        self::check($file, $maxBytes, $field);
        $tmp = (string) $file['tmp_name'];
        $mime = self::mime($tmp);
        if (!isset($allowed[$mime])) {
            throw ValidationException::one($field, 'The ' . str_replace('_', ' ', $field) . ' field must be a file of type: ' . implode(', ', array_unique($allowed)) . '.');
        }
        $dir = trim(preg_replace('#[^A-Za-z0-9/_-]#', '', $dir) ?? '', '/');
        $rel = ($dir !== '' ? $dir . '/' : '') . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        $abs = self::privatePath($rel);
        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0750, true) && !is_dir(dirname($abs))) {
            throw new \RuntimeException('Cannot create the private upload folder.');
        }
        if (!copy($tmp, $abs)) {
            throw new \RuntimeException('Cannot store the uploaded file.');
        }
        return $rel;
    }

    /** Absolute path of a file on the private disk (no "..", no absolute paths). */
    public static function privatePath(string $rel): string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if (str_contains($rel, '..')) {
            throw new \InvalidArgumentException('Invalid path.');
        }
        return rtrim((string) Config::get('filesystems.private_root'), '/\\') . '/' . $rel;
    }

    public static function deletePrivate(?string $rel): void
    {
        if ($rel) {
            $abs = self::privatePath($rel);
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
    }
}
