<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SecurityCheck extends Command
{
    protected $signature = 'abiha:security-check {--probe : Also request sensitive URLs on APP_URL and confirm they are not served}';

    protected $description = 'Check this installation against the production security checklist. Exit code 1 if anything FAILS.';

    private int $fails = 0;
    private int $warns = 0;

    public function handle(): int
    {
        $prod = app()->isProduction();

        $this->section('Environment');
        $this->check($prod, 'APP_ENV is "production"', 'APP_ENV is "'.config('app.env').'" (fine for development, wrong for the live site)', warnOnly: ! $prod);
        $this->check(! config('app.debug'), 'APP_DEBUG is off', 'APP_DEBUG is ON: error pages would show code, paths and credentials', warnOnly: false);
        $this->check(strlen((string) config('app.key')) > 20, 'APP_KEY is set', 'APP_KEY is missing: run `php artisan key:generate`');
        $this->check(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL uses https', 'APP_URL is not https: '.config('app.url'), warnOnly: ! $prod);

        $this->section('Sessions & passwords');
        $this->check((bool) config('session.secure') || $prod, 'Session cookie is Secure', 'SESSION_SECURE_COOKIE is off', warnOnly: ! $prod);
        $this->check((bool) config('session.http_only'), 'Session cookie is HttpOnly', 'Session cookie is readable by JavaScript');
        $this->check(in_array(strtolower((string) config('session.same_site')), ['lax', 'strict'], true), 'SameSite is Lax/Strict', 'SameSite is not set');
        $this->check((bool) config('session.encrypt'), 'Session data is encrypted', 'SESSION_ENCRYPT is off', warnOnly: true);
        $this->check((int) config('hashing.bcrypt.rounds', 12) >= 12, 'Password hashing cost >= 12', 'BCRYPT_ROUNDS is below 12');

        $this->section('Database & secrets');
        $dbUser = (string) config('database.connections.'.config('database.default').'.username');
        $dbPass = (string) config('database.connections.'.config('database.default').'.password');
        if (config('database.default') !== 'sqlite') {
            $this->check(! ($prod && ($dbPass === '' || $dbUser === 'root')), 'Database user is not root with an empty password', 'Production database uses root or an empty password: create a dedicated user');
        }
        $this->check(! file_exists(public_path('hot')), 'Vite dev server is not running (public/hot absent)', 'public/hot exists: remove it, the CSP is disabled while it exists');
        $this->check(! file_exists(public_path('.env')) && ! file_exists(public_path('storage/.env')), 'No .env inside public/', '.env is inside public/!');

        $this->section('Payments');
        $enabled = (array) (\App\Models\Setting::get('payments.enabled') ?? config('payments.enabled'));
        $unknown = array_diff($enabled, array_keys(config('payments.gateways')));
        $this->check($unknown === [], 'Only registered payment methods are enabled', 'Unknown payment methods enabled: '.implode(', ', $unknown));
        $this->check(! in_array('bank_transfer', $enabled, true) || trim((string) \App\Models\Setting::get('bank.details')) !== '', 'Bank transfer has account details', 'Bank transfer is switched on but has no account details, so it is hidden at checkout until you add them (admin → Payments)', warnOnly: true);

        $this->section('Accounts');
        $admins = User::query()->where('is_active', true)->whereHas('role', fn ($r) => $r->where('slug', 'admin'))->count();
        $this->check($admins >= 1, "{$admins} active administrator(s)", 'No active administrator: run `php artisan abiha:create-admin you@example.com`');
        $this->check($admins <= 3, 'Administrator count is small', "{$admins} administrators: keep full access to as few people as possible", warnOnly: true);

        $this->section('Files');
        $this->check(is_link(public_path('storage')) || file_exists(public_path('storage')), 'public/storage link exists', 'Run `php artisan storage:link`', warnOnly: true);
        $uploads = \Illuminate\Support\Facades\Storage::disk('public')->path('.htaccess');
        $this->check(is_file($uploads), 'Uploads folder blocks script execution (.htaccess)', "{$uploads} is missing: run `php artisan abiha:prepare-storage`");

        if ($this->option('probe')) {
            $this->section('HTTP probe of '.config('app.url'));
            foreach (['/.env', '/.git/config', '/composer.json', '/storage/logs/laravel.log', '/vendor/autoload.php', '/artisan'] as $path) {
                try {
                    $status = Http::timeout(8)->withoutRedirecting()->get(rtrim((string) config('app.url'), '/').$path)->status();
                    $this->check($status !== 200, "{$path} is not served ({$status})", "{$path} IS PUBLICLY READABLE (HTTP 200): fix the document root immediately");
                } catch (\Throwable $e) {
                    $this->line("  <fg=yellow>SKIP</>  {$path} (could not connect: ".class_basename($e).')');
                }
            }
        }

        $this->newLine();
        $this->line($this->fails ? "<fg=red>{$this->fails} failed</>, {$this->warns} warning(s)." : "<fg=green>No failures</>, {$this->warns} warning(s).");

        return $this->fails ? self::FAILURE : self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
    }

    private function check(bool $ok, string $pass, string $fail, bool $warnOnly = false): void
    {
        if ($ok) {
            $this->line("  <fg=green>PASS</>  {$pass}");
        } elseif ($warnOnly) {
            $this->warns++;
            $this->line("  <fg=yellow>WARN</>  {$fail}");
        } else {
            $this->fails++;
            $this->line("  <fg=red>FAIL</>  {$fail}");
        }
    }
}
