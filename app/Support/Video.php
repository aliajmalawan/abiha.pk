<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Product videos. A product has ONE optional video, stored in products.video as either
 *   - a link: YouTube (watch / youtu.be / shorts / embed), Vimeo, or a direct https link to an .mp4 / .webm file, or
 *   - the path of an uploaded file on the public disk ("videos/<random>.mp4").
 * Uploads are checked by their real file signature (not the name the browser sends), limited in size, and stored
 * under a random name. YouTube is played through youtube-nocookie.com (no tracking cookies until played).
 */
final class Video
{
    public const MAX_BYTES = 25 * 1024 * 1024;     // 25 MB: a short product clip
    public const DIR = 'videos';

    /** @return array{type:string,src:string,embed?:string}|null null when the value is empty or not a usable video */
    public static function parse(?string $value): ?array
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        if (str_starts_with($v, self::DIR . '/')) {
            return preg_match('#^videos/[A-Za-z0-9]{20,}\.(mp4|webm)$#', $v) ? ['type' => 'file', 'src' => Img::publicUrl($v)] : null;
        }
        if (!preg_match('#^https://#i', $v) || filter_var($v, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $host = strtolower((string) parse_url($v, PHP_URL_HOST));
        $host = preg_replace('/^(www|m)\./', '', $host) ?? $host;
        $path = (string) parse_url($v, PHP_URL_PATH);
        parse_str((string) parse_url($v, PHP_URL_QUERY), $q);

        if (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true) || $host === 'youtu.be') {
            $id = null;
            if ($host === 'youtu.be') {
                $id = ltrim($path, '/');
            } elseif (preg_match('#^/(embed|shorts|live)/([A-Za-z0-9_-]{11})#', $path, $m)) {
                $id = $m[2];
            } elseif (is_string($q['v'] ?? null)) {
                $id = $q['v'];
            }
            $id = is_string($id) ? substr($id, 0, 11) : null;
            if ($id !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
                return ['type' => 'youtube', 'src' => 'https://www.youtube.com/watch?v=' . $id,
                    'embed' => 'https://www.youtube-nocookie.com/embed/' . $id . '?rel=0&modestbranding=1&playsinline=1&autoplay=1',
                    'poster' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg'];
            }
            return null;
        }
        if ($host === 'vimeo.com' || $host === 'player.vimeo.com') {
            if (preg_match('#/(\d{5,12})(?:/|$)#', $path, $m)) {
                return ['type' => 'vimeo', 'src' => 'https://vimeo.com/' . $m[1], 'embed' => 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1&dnt=1'];
            }
            return null;
        }
        if (preg_match('#\.(mp4|webm)$#i', $path)) {
            return ['type' => 'file', 'src' => $v];
        }
        return null;
    }

    /** The error message for a bad upload or link, or null when fine. */
    public static function check(?array $file, ?string $url): ?array
    {
        $errors = [];
        if ($file !== null) {
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $errors['video_file'] = ($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE
                    ? 'The video is too large (25 MB at most).' : 'The video could not be uploaded. Please try again.';
            } elseif (($file['size'] ?? 0) > self::MAX_BYTES) {
                $errors['video_file'] = 'The video must be 25 MB or smaller. Shorten it, or paste a YouTube link instead.';
            } elseif (self::ext((string) $file['tmp_name']) === null) {
                $errors['video_file'] = 'The video must be an MP4 or WebM file.';
            }
        }
        $url = trim((string) $url);
        if ($url !== '' && self::parse($url) === null) {
            $errors['video_url'] = 'Paste a YouTube or Vimeo link, or a direct https link to an .mp4 or .webm file.';
        }
        return $errors ?: null;
    }

    /** "mp4" or "webm" judged by the file's first bytes, null for anything else. */
    public static function ext(string $tmp): ?string
    {
        $h = @file_get_contents($tmp, false, null, 0, 16);
        if ($h === false || strlen($h) < 12) {
            return null;
        }
        if (substr($h, 4, 4) === 'ftyp') {
            return 'mp4';
        }
        return str_starts_with($h, "\x1A\x45\xDF\xA3") ? 'webm' : null;
    }

    /** Stores an uploaded video on the public disk and returns its relative path. */
    public static function store(array $file): string
    {
        $ext = self::ext((string) $file['tmp_name']);
        if ($ext === null) {
            throw new \RuntimeException('Not a video file.');
        }
        $rel = self::DIR . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        $abs = Img::path($rel);
        if (!is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0775, true);
        }
        $ok = is_uploaded_file((string) $file['tmp_name']) ? move_uploaded_file((string) $file['tmp_name'], $abs) : copy((string) $file['tmp_name'], $abs);
        if (!$ok) {
            throw new \RuntimeException('The video could not be saved.');
        }
        @chmod($abs, 0644);
        return $rel;
    }

    /** Deletes a stored video file (a link has nothing to delete). */
    public static function delete(?string $value): void
    {
        $v = trim((string) $value);
        if ($v === '' || !str_starts_with($v, self::DIR . '/') || !preg_match('#^videos/[A-Za-z0-9]{20,}\.(mp4|webm)$#', $v)) {
            return;
        }
        try {
            $abs = Img::path($v);
        } catch (\InvalidArgumentException) {
            return;
        }
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    public static function isStored(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::DIR . '/');
    }
}
