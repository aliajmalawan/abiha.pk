<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string,string> */
    public array $params = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $server,
        public readonly array $cookies,
        private readonly string $rawBody = '',
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = (string) Config::get('base_path', '');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        // Requests are rewritten into /public by the root .htaccess.
        if (str_starts_with($uri, '/public/')) {
            $uri = substr($uri, 7);
        }
        $path = '/' . trim(rawurldecode($uri), '/');

        $raw = '';
        $post = $_POST;
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !str_starts_with($type, 'multipart/')) {
            $raw = (string) file_get_contents('php://input');
            if (str_contains($type, 'application/json') && $raw !== '') {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $post = $json + $post;                       // the front-end sends JSON for cart / wishlist calls
                }
            } elseif ($method !== 'POST' && $raw !== '' && !$post) {
                parse_str($raw, $post);                          // PUT/PATCH/DELETE with a normal form body
            }
        }
        // HTML forms can only send GET/POST: a hidden _method field turns a POST into PUT/PATCH/DELETE.
        if ($method === 'POST' && isset($post['_method']) && in_array(strtoupper((string) $post['_method']), ['PUT', 'PATCH', 'DELETE'], true)) {
            $method = strtoupper((string) $post['_method']);
        }

        return new self($method, $path, $_GET, $post, $_FILES, $_SERVER, $_COOKIE, $raw);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key): bool
    {
        return in_array($this->input($key), ['1', 'on', 'true', 'yes', 1, true], true);
    }

    /** @return array<mixed> */
    public function arr(string $key): array
    {
        $v = $this->input($key, []);
        return is_array($v) ? $v : [];
    }

    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        return is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $f : null;
    }

    /** Normalises a multi-file input (name="images[]") into a list of file arrays. */
    public function fileList(string $key): array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !is_array($f['name'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($f['name'] as $i => $name) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'type' => $f['type'][$i] ?? '',
                'tmp_name' => $f['tmp_name'][$i] ?? '',
                'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $f['size'][$i] ?? 0,
            ];
        }
        return $out;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if ($name === 'Content-Type') {
            $key = 'CONTENT_TYPE';
        }
        $v = $this->server[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    public function body(): string
    {
        return $this->rawBody;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** Accept: application/json (the front-end's request() helper sends it for every JSON call). */
    public function wantsJson(): bool
    {
        return str_contains((string) $this->header('Accept'), 'application/json')
            || $this->header('X-Requested-With') === 'fetch';
    }

    /** X-Requested-With: XMLHttpRequest (the cart drawer and quick view fetch HTML fragments with it). */
    public function ajax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    /** Request path matches a pattern like "admin/*" or "account" (Laravel's $request->is()). */
    public function is(string ...$patterns): bool
    {
        $path = trim($this->path, '/');
        foreach ($patterns as $p) {
            $re = '#^' . str_replace('\*', '.*', preg_quote(trim($p, '/'), '#')) . '$#';
            if (preg_match($re, $path)) {
                return true;
            }
        }
        return false;
    }

    /** Builds a request in tests and the CLI. */
    public static function create(string $method, string $path, array $input = [], array $server = [], array $files = []): self
    {
        $method = strtoupper($method);
        $query = $method === 'GET' ? $input : [];
        $post = $method === 'GET' ? [] : $input;
        if ($method === 'POST' && isset($post['_method']) && in_array(strtoupper((string) $post['_method']), ['PUT', 'PATCH', 'DELETE'], true)) {
            $method = strtoupper((string) $post['_method']);
        }
        return new self($method, '/' . trim($path, '/'), $query, $post, $files, $server + ['REMOTE_ADDR' => '127.0.0.1'], []);
    }

    public function isHttps(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && $this->server['HTTPS'] !== 'off') {
            return true;
        }
        return Config::get('trusted_proxy_hops') > 0 && $this->header('X-Forwarded-Proto') === 'https';
    }

    /** Client IP honouring only the configured number of trusted proxy hops. */
    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        $hops = (int) Config::get('trusted_proxy_hops', 0);
        if ($hops > 0) {
            $chain = array_values(array_filter(array_map('trim', explode(',', (string) $this->header('X-Forwarded-For')))));
            $idx = count($chain) - $hops;
            if ($idx >= 0 && isset($chain[$idx]) && filter_var($chain[$idx], FILTER_VALIDATE_IP)) {
                return $chain[$idx];
            }
        }
        return $remote;
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function fullUrl(): string
    {
        $qs = $this->query ? '?' . http_build_query($this->query) : '';
        return url($this->path) . $qs;
    }
}
