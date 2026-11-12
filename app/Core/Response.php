<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
        private ?string $file = null,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store'],
        );
    }

    public static function redirect(string $to, int $status = 303): self
    {
        // Only allow same-site redirects for relative targets.
        if (!preg_match('#^https?://#i', $to)) {
            $to = url($to);
        }
        return new self('', $status, ['Location' => $to]);
    }

    public static function text(string $body, int $status = 200, string $type = 'text/plain'): self
    {
        return new self($body, $status, ['Content-Type' => $type . '; charset=UTF-8']);
    }

    public static function download(string $body, string $filename, string $type = 'text/csv'): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        return new self($body, 200, [
            'Content-Type' => $type . '; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function file(string $path, string $type, bool $inline = true, ?string $filename = null): self
    {
        $r = new self('', 200, ['Content-Type' => $type, 'Cache-Control' => 'private, no-store'], $path);
        $r->headers['Content-Disposition'] = ($inline ? 'inline' : 'attachment') . '; filename="' . ($filename ?? basename($path)) . '"';
        $r->headers['Content-Length'] = (string) filesize($path);
        return $r;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v);
            }
        }
        if ($this->file !== null) {
            readfile($this->file);
            return;
        }
        echo $this->body;
    }
}
