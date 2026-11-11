<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\HomeSection;
use App\Models\Page;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShippingMethod;
use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

/** Phase 9: everything that makes a clean production install possible and safe. */
class DeploymentTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    /* ---------------- a clean production database ---------------- */

    public function test_the_production_seeder_adds_configuration_only_never_sample_data_or_accounts(): void
    {
        $this->seed(ProductionSeeder::class);

        $this->assertTrue(Role::query()->where('slug', 'admin')->exists());
        $this->assertTrue(Attribute::query()->where('slug', 'size')->exists());
        $this->assertGreaterThan(5, HomeSection::count());
        foreach ([Product::class, Category::class, Banner::class, Page::class, Coupon::class, ShippingMethod::class, User::class] as $model) {
            $this->assertSame(0, $model::count(), class_basename($model).' must start empty on a live site');
        }
        $this->assertSame(0, HomeSection::query()->whereNotNull('subtitle')->count(), 'no invented marketing copy');
        $this->assertFalse(HomeSection::query()->whereIn('type', ['brand', 'social'])->where('is_active', true)->exists(), 'blocks that need the owner\'s own words start switched off');
    }

    public function test_seeding_twice_changes_nothing_and_keeps_a_customised_home_layout(): void
    {
        $this->seed(ProductionSeeder::class);
        HomeSection::query()->where('type', 'hero')->update(['sort_order' => 99]);
        $count = HomeSection::count();

        $this->seed(ProductionSeeder::class);

        $this->assertSame($count, HomeSection::count());
        $this->assertSame(99, (int) HomeSection::query()->where('type', 'hero')->value('sort_order'), 'the owner\'s ordering survives a re-seed');
        $this->assertSame(1, Role::query()->where('slug', 'admin')->count());
    }

    public function test_an_empty_shop_renders_a_clean_home_and_shop_page(): void
    {
        $this->seed(ProductionSeeder::class);
        config(['abiha.show_sample_labels' => false]);          // the production default (see the next test)

        $home = $this->get('/')->assertOk();
        $home->assertDontSee('SAMPLE', false)->assertDontSee('to be added', false);
        $this->assertSame(1, preg_match_all('/<h1[\s>]/', $home->getContent()), 'one h1 even with no banner');
        $this->get('/shop')->assertOk()->assertSee('No products', false);
        $this->get('/cart')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/sitemap.xml')->assertOk();
    }

    public function test_development_placeholders_are_hidden_by_default_on_a_production_site(): void
    {
        $src = File::get(config_path('abiha.php'));
        $this->assertStringContainsString("env('APP_ENV', 'production') !== 'production'", $src, 'labels default to ON only outside production');
        $this->assertStringContainsString('ABIHA_SAMPLE_LABELS=false', File::get(base_path('.env.production.example')));

        $this->seed(ProductionSeeder::class);
        config(['abiha.show_sample_labels' => true]);                       // a dev machine still sees the flags
        $this->get('/')->assertSee('to be added', false);
    }

    public function test_checkout_cannot_be_completed_until_staff_have_set_up_shipping(): void
    {
        $this->seed(ProductionSeeder::class);
        $p = $this->product();
        $this->addToCart($p);

        $this->get('/checkout')->assertOk()->assertSee('disabled', false);
        $this->post(route('checkout.store'), $this->checkoutPayload())->assertSessionHasErrors();
        $this->assertSame(0, \App\Models\Order::count());
    }

    /* ---------------- storage ---------------- */

    public function test_prepare_storage_creates_folders_protects_uploads_and_is_repeatable(): void
    {
        $root = Storage::disk('public')->path('');
        $htaccess = rtrim($root, '/'.chr(92)).DIRECTORY_SEPARATOR.'.htaccess';
        $original = is_file($htaccess) ? File::get($htaccess) : null;
        File::delete($htaccess);

        $this->artisan('abiha:prepare-storage')->assertSuccessful();
        $this->assertFileExists($htaccess);
        $this->assertStringContainsString('php_flag engine off', File::get($htaccess));
        $this->assertSame(File::get(resource_path('stubs/uploads.htaccess')), File::get($htaccess));

        $this->artisan('abiha:prepare-storage')->assertSuccessful();          // running it on every deploy is fine
        $original !== null && File::put($htaccess, $original);
    }

    public function test_the_public_disk_can_write_straight_into_the_web_root_when_symlinks_are_not_allowed(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abiha-disk-'.uniqid();
        config(['filesystems.disks.public.root' => $dir, 'filesystems.disks.public.url' => 'https://shop.example.test/storage']);
        app('filesystem')->forgetDisk('public');

        $this->artisan('abiha:prepare-storage')->assertSuccessful();
        Storage::disk('public')->put('products/a.txt', 'x');

        $this->assertFileExists($dir.DIRECTORY_SEPARATOR.'products'.DIRECTORY_SEPARATOR.'a.txt');
        $this->assertFileExists($dir.DIRECTORY_SEPARATOR.'.htaccess', 'the uploads protection follows the folder');
        $this->assertSame('https://shop.example.test/storage/products/a.txt', Storage::disk('public')->url('products/a.txt'));
        File::deleteDirectory($dir);
    }

    public function test_filesystem_config_reads_the_public_disk_settings_from_the_environment(): void
    {
        $src = File::get(config_path('filesystems.php'));

        $this->assertStringContainsString("env('PUBLIC_DISK_ROOT')", $src);
        $this->assertStringContainsString("env('PUBLIC_DISK_URL')", $src);
        $this->assertStringContainsString("env('PUBLIC_PATH')", File::get(config_path('app.php')));
    }

    /* ---------------- preflight ---------------- */

    public function test_preflight_reports_problems_and_exits_non_zero_when_something_fails(): void
    {
        // The test database has no roles and no migrations table state issue, but there is no built asset check failure to rely on,
        // so provoke a hard failure: no APP_KEY.
        config(['app.key' => '']);

        $this->artisan('abiha:preflight')->expectsOutputToContain('APP_KEY is empty')->assertFailed();
    }

    public function test_preflight_passes_on_a_correctly_prepared_install(): void
    {
        $this->seed(ProductionSeeder::class);
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->artisan('abiha:preflight')
            ->expectsOutputToContain('All migrations are applied')
            ->expectsOutputToContain('Roles and permissions are seeded')
            ->expectsOutputToContain('Application code is outside the web root')
            ->assertSuccessful();
    }

    public function test_preflight_refuses_an_application_folder_that_sits_inside_the_web_root(): void
    {
        $this->app->usePublicPath(base_path());                  // the classic cPanel mistake: whole app uploaded into public_html

        $this->artisan('abiha:preflight')->expectsOutputToContain('INSIDE the public web root')->assertFailed();
    }

    public function test_preflight_flags_secrets_left_in_the_public_folder(): void
    {
        $public = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abiha-public-'.uniqid();
        File::ensureDirectoryExists($public);
        File::put($public.DIRECTORY_SEPARATOR.'index.php', '<?php');
        File::put($public.DIRECTORY_SEPARATOR.'.env', 'APP_KEY=oops');
        $this->app->usePublicPath($public);

        $this->artisan('abiha:preflight')->expectsOutputToContain("'.env' exists inside the public folder")->assertFailed();
        File::deleteDirectory($public);
    }

    public function test_preflight_warns_when_mail_would_only_be_logged(): void
    {
        config(['mail.default' => 'log']);

        $this->artisan('abiha:preflight')->expectsOutputToContain('password-reset emails are only written to the log');
    }

    /* ---------------- shipped files ---------------- */

    public function test_the_production_env_template_is_safe_and_complete(): void
    {
        $lines = collect(file(base_path('.env.production.example'), FILE_IGNORE_NEW_LINES));
        $get = fn (string $k) => trim(substr((string) $lines->first(fn ($l) => str_starts_with($l, $k.'=')), strlen($k) + 1), " \t\"'");

        $this->assertSame('production', $get('APP_ENV'));
        $this->assertSame('false', $get('APP_DEBUG'));
        $this->assertStringStartsWith('https://', $get('APP_URL'));
        $this->assertSame('Asia/Karachi', $get('APP_TIMEZONE'));
        $this->assertSame('true', $get('SESSION_SECURE_COOKIE'));
        $this->assertSame('true', $get('SESSION_ENCRYPT'));
        $this->assertSame('daily', $get('LOG_STACK'));
        $this->assertSame('error', $get('LOG_LEVEL'));
        $this->assertSame('file', $get('CACHE_STORE'));
        $this->assertSame('sync', $get('QUEUE_CONNECTION'));
        foreach (['APP_KEY', 'DB_PASSWORD', 'DB_USERNAME', 'DB_DATABASE', 'MAIL_PASSWORD', 'MAIL_USERNAME'] as $secret) {
            $this->assertSame('', $get($secret), "{$secret} must be blank in the template");
        }
        foreach (['PUBLIC_PATH', 'ADMIN_IDLE_MINUTES', 'MAIL_MAILER', 'DB_HOST', 'LOG_DAILY_DAYS'] as $key) {
            $this->assertNotSame('', trim((string) $lines->first(fn ($l) => str_starts_with($l, $key.'='))), "{$key} is documented in the template");
        }
        $this->assertStringNotContainsString('base64:', File::get(base_path('.env.production.example')));
    }

    public function test_the_cpanel_front_controller_points_the_framework_at_public_html(): void
    {
        $src = File::get(base_path('deploy/cpanel/index.php'));

        $this->assertStringContainsString("__DIR__.'/../abiha'", $src, 'the private app folder is a sibling of public_html');
        $this->assertStringContainsString('$app->usePublicPath(__DIR__)', $src, 'without this the Vite manifest and /storage are looked up in the wrong folder');
        $this->assertStringContainsString('maintenance.php', $src, '`php artisan down` must work in this layout');
        $this->assertDoesNotMatchRegularExpression('#/home/(?!CPANELUSER)[a-z0-9_]+/#i', $src, 'no hard-coded account paths (only the CPANELUSER placeholder in comments)');
    }

    public function test_public_htaccess_forces_https_on_real_domains_but_not_localhost_or_acme(): void
    {
        $ht = File::get(public_path('.htaccess'));

        $this->assertStringContainsString('RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]', $ht);
        $this->assertStringContainsString('localhost', $ht);
        $this->assertStringContainsString('.well-known', $ht);
        $this->assertStringContainsString('X-Forwarded-Proto', $ht);
        $this->assertStringContainsString('REDIRECT_STATUS', $ht, 'only on the first pass, so internal rewrites are not redirected');
    }

    public function test_the_backup_script_keeps_the_password_off_the_command_line_and_rotates(): void
    {
        $sh = File::get(base_path('deploy/backup.sh'));

        $this->assertStringContainsString('--defaults-extra-file', $sh);
        $this->assertStringNotContainsString('-p"$DB_PASS"', $sh);
        $this->assertStringNotContainsString('--password=', $sh);
        $this->assertStringContainsString('umask 077', $sh);
        $this->assertStringContainsString('KEEP_DAYS', $sh);
        $this->assertStringContainsString('gzip -t', $sh, 'every backup is verified after it is written');
        $this->assertStringNotContainsString("\r", $sh, 'shell scripts must use Unix line endings or they fail on Linux hosts');
    }

    public function test_the_release_packager_never_ships_secrets_or_development_files(): void
    {
        $src = File::get(base_path('deploy/package-release.php'));

        foreach (['(^|/)\\.env$', 'sqlite', 'storage/logs', 'storage/app/(public|private)'] as $needle) {
            $this->assertStringContainsString($needle, $src, "the packager excludes {$needle}");
        }
        $this->assertStringNotContainsString("'tests'", explode('$include', $src)[1] ?? '', 'tests are not shipped');
        $this->assertStringNotContainsString("'node_modules'", explode('$include', $src)[1] ?? '');
    }

    public function test_no_secret_or_development_file_is_inside_the_public_folder(): void
    {
        foreach (['.env', 'composer.json', 'artisan', 'vendor', 'database.sqlite', 'phpunit.xml'] as $name) {
            $this->assertFileDoesNotExist(public_path($name), "{$name} must never be in public/");
        }
        $this->assertFileDoesNotExist(public_path('hot'), 'the Vite dev-server marker must not be shipped');
        $this->assertFileDoesNotExist(public_path('robots.txt'), 'robots.txt is generated per environment');
    }

    public function test_logging_is_configured_for_daily_rotation_and_never_logs_secrets_by_default(): void
    {
        $this->assertSame('daily', config('logging.channels.daily.driver'));
        $this->assertGreaterThanOrEqual(7, (int) config('logging.channels.daily.days'));
        $this->assertContains(config('logging.default'), ['stack', 'daily']);
        $this->assertContains('password', config('app.dont_flash', ['password']) ?: ['password'], 'passwords are never flashed back into forms');
    }

    public function test_the_project_root_htaccess_helps_localhost_and_still_blocks_private_paths(): void
    {
        $ht = File::get(base_path('.htaccess'));

        $this->assertStringContainsString('localhost|127', $ht, 'the friendly redirect is for localhost only');
        $this->assertStringContainsString('R=302', $ht);
        $this->assertStringContainsString('ABIHA_BASE', $ht, 'the redirect target is built from the request, not from the filesystem path');
        foreach (['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'storage', 'tests', 'vendor', 'node_modules', 'docs'] as $dir) {
            $this->assertStringContainsString($dir, explode("RewriteRule \\.(env", $ht)[0], "{$dir}/ stays blocked");
        }
        $this->assertStringContainsString('[F,L]', $ht);
    }

    /* ---------------- the documentation itself ---------------- */

    /** GitHub-style heading anchor: lower-case, punctuation dropped, spaces to hyphens. */
    private function slug(string $heading): string
    {
        $h = mb_strtolower(trim(preg_replace('/[`*_]/', '', $heading)));
        $h = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $h);

        return preg_replace('/\s/', '-', $h);
    }

    private function anchors(string $file): array
    {
        preg_match_all('/^#{1,6}\s+(.+)$/m', File::get($file), $m);

        return array_map(fn ($h) => $this->slug($h), $m[1]);
    }

    public function test_every_link_and_anchor_in_the_readme_and_docs_resolves(): void
    {
        $docs = [base_path('README.md'), base_path('docs/DEPLOYMENT.md'), base_path('docs/ARCHITECTURE.md'), base_path('tests/browser/README.md')];
        $broken = [];
        foreach ($docs as $doc) {
            preg_match_all('/\[[^\]]*\]\(([^)\s]+)\)/', File::get($doc), $m);
            foreach ($m[1] as $href) {
                if (preg_match('#^(https?:|mailto:)#', $href)) {
                    continue;
                }
                [$path, $anchor] = array_pad(explode('#', $href, 2), 2, null);
                $target = $path === '' ? $doc : realpath(dirname($doc).DIRECTORY_SEPARATOR.$path);
                if (! $target || ! file_exists($target)) {
                    $broken[] = basename($doc)." → {$href} (no such file)";
                } elseif ($anchor !== null && str_ends_with($target, '.md') && ! in_array($anchor, $this->anchors($target), true)) {
                    $broken[] = basename($doc)." → {$href} (no such heading)";
                }
            }
        }

        $this->assertSame([], $broken);
    }

    public function test_every_artisan_command_the_docs_tell_you_to_run_exists(): void
    {
        $available = array_keys(\Illuminate\Support\Facades\Artisan::all());
        $missing = [];
        foreach ([base_path('README.md'), base_path('docs/DEPLOYMENT.md')] as $doc) {
            preg_match_all('/php(?:\s+-d\s+\S+)?\s+artisan\s+([a-z][\w:-]*)/i', File::get($doc), $m);
            foreach (array_unique($m[1]) as $cmd) {
                if (! in_array($cmd, $available, true)) {
                    $missing[] = basename($doc).": php artisan {$cmd}";
                }
            }
        }

        $this->assertSame([], $missing);
        foreach (['abiha:preflight', 'abiha:security-check', 'abiha:prepare-storage', 'abiha:create-admin', 'abiha:images-optimize'] as $cmd) {
            $this->assertStringContainsString($cmd, File::get(base_path('docs/DEPLOYMENT.md')).File::get(base_path('README.md')));
        }
    }

    public function test_the_deployment_guide_covers_every_topic_that_was_asked_for(): void
    {
        $guide = mb_strtolower(File::get(base_path('docs/DEPLOYMENT.md')));
        foreach (['production environment', '.env', 'migrate --force', 'productionseeder', 'storage configuration', 'cache configuration', 'cpanel', 'document root', 'public_html',
            'domain configuration', 'ssl configuration', 'autossl', 'backups', 'backup.sh', 'restore', 'error logging', 'storage/logs', 'checklist', 'rolling back'] as $topic) {
            $this->assertStringContainsString($topic, $guide, "the guide must cover: {$topic}");
        }
        $readme = File::get(base_path('README.md'));
        foreach (['## 2. Installation', '## 3. Local development', '## 4. Database setup', '## 5. Admin setup', '## 6. Deployment', '## 7. Troubleshooting'] as $h) {
            $this->assertStringContainsString($h, $readme);
        }
    }
}
