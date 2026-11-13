<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\View;

/**
 * Transactional email.
 *
 * Drivers (MAIL_DRIVER):
 *  - outbox: development fallback; writes each message to storage/mail/*.html
 *            and *.eml so links can be opened locally. Refused in production.
 *  - smtp:   any SMTP provider (SMTP_* in .env).
 *  - array:  tests; messages are collected in Mailer::$sent.
 *
 *   Mailer::sendNow($to, 'reset-password', ['url' => $link]);   // immediately (password reset)
 *   Mailer::queue($to, 'order', ['order' => $order, ...]);       // email_outbox, sent by flush()/cron
 *
 * A template app/Views/emails/<name>.php echoes table rows and sets $subject (optionally $text, $footer); it is
 * wrapped in emails/layout.php. Every template also gets $brand, $logo, $contactEmail, $contactPhone.
 * Queued emails are delivered by flush() (after commit and by `php bin/console.php cron`), so a provider outage
 * never blocks checkout.
 */
final class Mailer
{
    /** @var list<array{to:string,subject:string}> Captured messages (tests). */
    public static array $sent = [];

    /** Renders and sends immediately. Returns false (and logs) on failure. */
    public static function sendNow(string $to, string $template, array $data): bool
    {
        [$subject, $html, $text] = self::render($template, $data);
        try {
            self::deliver($to, $subject, $html, $text);
            return true;
        } catch (\Throwable $e) {
            Logger::error('Email send failed', ['template' => $template, 'email' => $to, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** Sends the test email immediately and LETS THE ERROR THROUGH (used by `mail:test`, so the real SMTP message is visible). */
    public static function sendTest(string $to): void
    {
        [$subject, $html, $text] = self::render('test', []);
        self::deliver($to, $subject, $html, $text);
    }

    /** Queues a rendered email (safe to call inside a DB transaction). */
    public static function queue(string $to, string $template, array $data): void
    {
        [$subject, $html, $text] = self::render($template, $data);
        Database::get()->insert(
            'INSERT INTO email_outbox (to_email, template, subject, html, text_body, send_after, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$to, $template, $subject, $html, $text, now(), now()]
        );
    }

    /** Delivers pending queued emails with retry/backoff. Returns count sent. */
    public static function flush(int $limit = 20): int
    {
        $db = Database::get();
        $rows = $db->all(
            "SELECT * FROM email_outbox WHERE status = 'pending' AND send_after <= ? ORDER BY id LIMIT " . max(1, min(100, $limit)),
            [now()]
        );
        $sent = 0;
        foreach ($rows as $row) {
            // Claim the row so concurrent flushers do not double-send.
            $claimed = $db->exec(
                "UPDATE email_outbox SET attempts = attempts + 1, send_after = ? WHERE id = ? AND status = 'pending' AND attempts = ?",
                [date('Y-m-d H:i:s', time() + 600), $row['id'], $row['attempts']]
            );
            if ($claimed !== 1) {
                continue;
            }
            try {
                self::deliver($row['to_email'], $row['subject'], $row['html'], $row['text_body']);
                $db->exec("UPDATE email_outbox SET status = 'sent', sent_at = ?, last_error = NULL WHERE id = ?", [now(), $row['id']]);
                $sent++;
            } catch (\Throwable $e) {
                $attempts = (int) $row['attempts'] + 1;
                $status = $attempts >= 6 ? 'failed' : 'pending';
                $backoff = min(360, 2 ** $attempts);
                $db->exec(
                    'UPDATE email_outbox SET status = ?, last_error = ?, send_after = ? WHERE id = ?',
                    [$status, mb_substr($e->getMessage(), 0, 500), date('Y-m-d H:i:s', time() + 60 * $backoff), $row['id']]
                );
                Logger::warning('Queued email failed', ['id' => $row['id'], 'error' => $e->getMessage()]);
            }
        }
        return $sent;
    }

    /** @return array{0:string,1:string,2:string} subject, html, text */
    public static function render(string $template, array $data): array
    {
        // Same values the old OrderMail passed to its view.
        $data += [
            'brand' => Config::get('abiha.name') . '®',
            'logo' => \App\Support\SiteSettings::asset('site.logo') ?? asset('assets/brand/abiha-logo-full-300.png', true),
            'contactEmail' => trim((string) \App\Support\Settings::get('contact.email', Config::get('abiha.contact.email'))),
            'contactPhone' => trim((string) \App\Support\Settings::get('contact.phone', Config::get('abiha.contact.phone'))),
        ];
        $file = APP_PATH . '/Views/emails/' . basename($template) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Email template not found: {$template}");
        }
        // A template echoes its HTML body and sets $subject (and optionally $text).
        [$subject, $body, $text, $footer] = (static function (string $__file, array $__data): array {
            extract($__data, EXTR_SKIP);
            $subject = '';
            $text = null;
            $footer = null;
            ob_start();
            include $__file;
            return [$subject, (string) ob_get_clean(), $text, $footer];
        })($file, $data);
        $html = View::partial('emails/layout', ['body' => $body, 'subject' => $subject, 'footer' => $footer] + $data);
        $text ??= self::toText($body);
        return [trim($subject), $html, trim($text) . "\n"];
    }

    /** Plain-text alternative derived from the HTML body. */
    public static function toText(string $html): string
    {
        $html = preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/tr|/h[1-6]|/li|/div)[^>]*>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</t[dh]>#i', "\t", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text) ?? $text);
    }

    private static function deliver(string $to, string $subject, string $html, string $text): void
    {
        $driver = (string) Config::get('mail.driver');
        if ($driver === 'array') {
            self::$sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text];
            return; // tests
        }
        $fromAddr = (string) Config::get('mail.from_address');
        $fromName = (string) Config::get('mail.from_name');
        $message = self::mime($fromName, $fromAddr, $to, $subject, $html, $text);

        if ($driver === 'smtp') {
            (new SmtpTransport(
                (string) Config::get('mail.smtp_host'),
                (int) Config::get('mail.smtp_port'),
                (string) Config::get('mail.smtp_encryption'),
                (string) Config::get('mail.smtp_user'),
                (string) Config::get('mail.smtp_pass'),
            ))->send($fromAddr, $to, $message);
            return;
        }
        if (Config::isProduction()) {
            throw new \RuntimeException('Mail driver not configured for production');
        }
        $dir = STORAGE_PATH . '/mail';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $name = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($subject));
        file_put_contents($dir . '/' . substr($name, 0, 120) . '.html', "<!-- To: " . htmlspecialchars($to) . " -->\n" . $html);
        file_put_contents($dir . '/' . substr($name, 0, 120) . '.eml', $message);
    }

    private static function mime(string $fromName, string $fromAddr, string $to, string $subject, string $html, string $text): string
    {
        $boundary = 'b' . bin2hex(random_bytes(12));
        $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $domain = substr(strrchr($fromAddr, '@') ?: '@localhost', 1);
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            "From: {$encName} <{$fromAddr}>",
            "To: <{$to}>",
            "Subject: {$encSubject}",
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        ];
        return implode("\r\n", $headers) . "\r\n\r\n"
            . "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text)) . "\r\n"
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "\r\n"
            . "--{$boundary}--\r\n";
    }
}
