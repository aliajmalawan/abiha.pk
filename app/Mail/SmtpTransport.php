<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Minimal SMTP client (AUTH LOGIN, STARTTLS or implicit TLS). Enough for
 * transactional providers such as Brevo, Mailgun, SES, Zoho or cPanel mail.
 */
final class SmtpTransport
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption, // tls | ssl | none
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout = 15,
    ) {
    }

    public function send(string $from, string $to, string $message): void
    {
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $this->socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->socket) {
            throw new \RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($this->socket, $this->timeout);
        try {
            $this->expect(220);
            $ehloHost = gethostname() ?: 'localhost';
            $this->command("EHLO {$ehloHost}", 250);
            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('SMTP STARTTLS negotiation failed');
                }
                $this->command("EHLO {$ehloHost}", 250);
            }
            if ($this->username !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($this->username), 334, true);
                $this->command(base64_encode($this->password), 235, true);
            }
            $this->command('MAIL FROM:<' . $this->addr($from) . '>', 250);
            $this->command('RCPT TO:<' . $this->addr($to) . '>', [250, 251]);
            $this->command('DATA', 354);
            // Dot-stuffing per RFC 5321 §4.5.2.
            $body = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $message));
            fwrite($this->socket, str_replace("\n", "\r\n", (string) $body) . "\r\n.\r\n");
            $this->expect(250);
            $this->command('QUIT', 221);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    private function addr(string $s): string
    {
        if (preg_match('/<([^>]+)>/', $s, $m)) {
            $s = $m[1];
        }
        if (!filter_var($s, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email address');
        }
        return $s;
    }

    private function command(string $cmd, int|array $expect, bool $secret = false): void
    {
        fwrite($this->socket, $cmd . "\r\n");
        $this->expect($expect, $secret ? '[credentials]' : $cmd);
    }

    private function expect(int|array $codes, string $context = 'connect'): void
    {
        $codes = (array) $codes;
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException('SMTP unexpected response to ' . $context . ': ' . trim(substr($response, 0, 200)));
        }
    }
}
