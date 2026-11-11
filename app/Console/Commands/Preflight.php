<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * "Can this server run ABIHA correctly?" Read-only. Run it on the server after uploading and again after every deploy.
 * (abiha:security-check answers the different question "is it configured safely?"; run both.)
 */
class Preflight extends Command
{
    protected $signature = 'abiha:preflight';

    protected $description = 'Check PHP, folders, web-root layout, database and caches for a production deployment. Exit code 1 if anything FAILS.';

    private int $fails = 0;
    private int $warns = 0;

    public function handle(): int
    {
        $this->section('PHP');
        $this->check(version_compare(PHP_VERSION, '8.2.0', '>='), 'PHP '.PHP_VERSION, 'PHP '.PHP_VERSION.' is too old: this application needs PHP 8.2 or newer (cPanel → Select PHP Version)');
        $driver = config('database.default');
        $required = ['ctype', 'curl', 'dom', 'fileinfo', 'gd', 'json', 'mbstring', 'openssl', 'tokenizer', 'xml', $driver === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql'];
        $missing = array_values(array_filter($required, fn ($e) => ! extension_loaded($e)));
        $this->check($missing === [], 'Required extensions are loaded', 'Missing PHP extensions: '.implode(', ', $missing).' (enable them in cPanel → Select PHP Version → Extensions)');
        $this->check(function_exists('imagewebp'), 'GD can write WebP images (responsive product photos)', 'GD has no WebP support: uploads still work, but pages will serve larger JPEG/PNG files', warnOnly: true);
        $this->check(extension_loaded('exif'), 'exif is loaded (phone photos keep their orientation)', 'The exif extension is missing: photos taken on phones may appear rotated', warnOnly: true);
        $this->check($this->bytes((string) ini_get('memory_limit')) >= 128 * 1024 * 1024 || (int) ini_get('memory_limit') === -1, 'memory_limit is at least 128M', 'memory_limit is '.ini_get('memory_limit').': image processing may fail; raise it to 256M', warnOnly: true);
        $this->check($this->bytes((string) ini_get('upload_max_filesize')) >= 8 * 1024 * 1024, 'upload_max_filesize allows product photos ('.ini_get('upload_max_filesize').')', 'upload_max_filesize is '.ini_get('upload_max_filesize').': raise it to at least 8M (product images may be 4 MB each)', warnOnly: true);
        $this->check($this->bytes((string) ini_get('post_max_size')) >= 24 * 1024 * 1024, 'post_max_size allows several photos at once ('.ini_get('post_max_size').')', 'post_max_size is '.ini_get('post_max_size').': raise it to at least 24M or multi-image uploads will silently fail', warnOnly: true);

        $this->section('Web-root layout (keeps secrets out of public view)');
        $public = realpath(public_path()) ?: public_path();
        $base = realpath(base_path()) ?: base_path();
        $inside = $base === $public || str_starts_with($base.DIRECTORY_SEPARATOR, $public.DIRECTORY_SEPARATOR);
        $this->check(! $inside, 'Application code is outside the web root', "The application folder ({$base}) is INSIDE the public web root ({$public}): .env, vendor/ and the source code could be downloaded. Move it above public_html (see docs/DEPLOYMENT.md)");
        foreach (['.env', 'vendor', 'composer.json', 'artisan', 'storage/logs', 'database', '.git', 'app'] as $secret) {
            if (file_exists($public.DIRECTORY_SEPARATOR.$secret)) {
                $this->check(false, '', "'{$secret}' exists inside the public folder ({$public}): remove it at once");
            }
        }
        $this->check(is_file($public.DIRECTORY_SEPARATOR.'index.php'), 'index.php is in the public folder', "No index.php in {$public}: the site document root is wrong");
        $this->check(is_file(public_path('build/manifest.json')), 'Built CSS/JS assets are present (public/build)', 'public/build/manifest.json is missing: run `npm run build` locally and upload public/build (the server does not need Node)');

        $this->section('Environment');
        $this->check(is_file(base_path('.env')), '.env exists', '.env is missing: copy .env.production.example to .env and fill it in');
        $this->check(filled(config('app.key')), 'APP_KEY is set', 'APP_KEY is empty: run `php artisan key:generate --force`');
        $this->check(app()->isProduction(), 'APP_ENV is production', 'APP_ENV is "'.config('app.env').'": set APP_ENV=production on the live site', warnOnly: true);
        $this->check(config('app.timezone') === 'Asia/Karachi', 'Timezone is Asia/Karachi (business-day reports)', 'Timezone is '.config('app.timezone').': daily sales reports are cut at midnight in that zone', warnOnly: true);
        $this->check(app()->configurationIsCached(), 'Configuration is cached', 'Configuration is not cached: run `php artisan optimize` after every deploy (faster, and edits to .env are not half-applied)', warnOnly: true);
        $this->check(app()->routesAreCached(), 'Routes are cached', 'Routes are not cached: `php artisan optimize` caches them', warnOnly: true);
        $this->check(! in_array(config('mail.default'), ['log', 'array'], true), 'A real mail transport is configured ('.config('mail.default').')', 'MAIL_MAILER is "'.config('mail.default').'": password-reset emails are only written to the log, never delivered. Configure SMTP in .env', warnOnly: true);
        $this->check(config('cache.default') !== 'database', 'Cache store is '.config('cache.default'), 'CACHE_STORE=database costs a query per cache read: use file (or redis if you have it)', warnOnly: true);

        $this->section('Database');
        try {
            DB::connection()->getPdo();
            $this->check(true, 'Connected to '.config('database.default').' database "'.DB::connection()->getDatabaseName().'"', '');
            $pending = $this->pendingMigrations();
            $this->check($pending === 0, 'All migrations are applied', "{$pending} migration(s) are pending: run `php artisan migrate --force`");
            if ($pending === 0) {
                $this->check(Role::query()->where('slug', 'admin')->exists(), 'Roles and permissions are seeded', 'No roles yet: run `php artisan db:seed --class=RolesAndPermissionsSeeder --force`');
                $this->check(User::query()->where('is_active', true)->whereHas('role', fn ($r) => $r->where('slug', 'admin'))->exists(), 'An active administrator exists', 'No administrator yet: run `php artisan abiha:create-admin you@yourdomain.com`', warnOnly: true);
            }
        } catch (\Throwable $e) {
            $this->check(false, '', 'Cannot connect to the database ('.class_basename($e).'): check DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD in .env');
        }

        $this->section('Storage');
        foreach ([storage_path('framework/cache'), storage_path('framework/sessions'), storage_path('framework/views'), storage_path('logs'), base_path('bootstrap/cache')] as $dir) {
            $this->check(is_dir($dir) && is_writable($dir), str_replace($base.DIRECTORY_SEPARATOR, '', $dir).' is writable', "{$dir} is missing or not writable: set permissions to 775 and run `php artisan abiha:prepare-storage`");
        }
        $disk = Storage::disk('public')->path('');
        $this->check(is_dir($disk) && is_writable($disk), 'Uploads folder is writable', "{$disk} is not writable");
        $this->check(is_file(rtrim($disk, '/'.chr(92)).DIRECTORY_SEPARATOR.'.htaccess'), 'Uploads folder blocks script execution', 'Uploads folder has no .htaccess: run `php artisan abiha:prepare-storage`');
        $reachable = file_exists(public_path('storage')) || str_starts_with(realpath($disk) ?: $disk, $public);
        $this->check($reachable, 'Uploaded images are reachable at /storage', 'public/storage is missing, so uploaded images will not display: run `php artisan abiha:prepare-storage` (or set PUBLIC_DISK_ROOT if symlinks are disabled)');
        $free = @disk_free_space(base_path());
        $this->check($free === false || $free > 200 * 1024 * 1024, 'Disk space is fine', 'Less than 200 MB of disk space is free', warnOnly: true);

        $this->newLine();
        $this->line($this->fails ? "<fg=red>{$this->fails} failed</>, {$this->warns} warning(s). Fix the FAIL lines before going live." : "<fg=green>Ready</>, {$this->warns} warning(s). Now run `php artisan abiha:security-check --probe`.");

        return $this->fails ? self::FAILURE : self::SUCCESS;
    }

    private function pendingMigrations(): int
    {
        $migrator = app('migrator');
        $repo = $migrator->getRepository();
        if (! $repo->repositoryExists()) {
            return count($migrator->getMigrationFiles($migrator->paths() + [database_path('migrations')]));
        }
        $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));

        return count(array_diff($files, $repo->getRan()));
    }

    private function bytes(string $ini): int
    {
        $ini = trim($ini);
        $n = (int) $ini;

        return match (strtolower(substr($ini, -1))) { 'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n };
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
    }

    private function check(bool $ok, string $pass, string $fail, bool $warnOnly = false): void
    {
        if ($ok) {
            if ($pass !== '') {
                $this->line("  <fg=green>PASS</>  {$pass}");
            }
        } elseif ($warnOnly) {
            $this->warns++;
            $this->line("  <fg=yellow>WARN</>  {$fail}");
        } else {
            $this->fails++;
            $this->line("  <fg=red>FAIL</>  {$fail}");
        }
    }
}
