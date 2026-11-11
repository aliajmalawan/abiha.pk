<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\Setting;
use App\Services\ImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsUsers;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use BuildsCatalog, BuildsUsers, RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->admin = $this->userWithRole('admin');
        Storage::fake('public');
    }

    /* ---- helpers: real image bytes ---- */
    private function jpeg(int $w = 80, int $h = 60): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 120, 40));
        ob_start(); imagejpeg($im, null, 90); $b = ob_get_clean();

        return $b;
    }

    private function png(int $w = 80, int $h = 60, bool $alpha = false): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, $alpha ? imagecolorallocatealpha($im, 10, 20, 30, 64) : imagecolorallocate($im, 10, 20, 30));
        ob_start(); imagepng($im); $b = ob_get_clean();

        return $b;
    }

    private function upload(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function createProductWith(array $files)
    {
        return $this->actingAs($this->admin)->post(route('admin.products.store'), ['name' => 'Upload Test', 'sku' => 'UP-'.uniqid(), 'price' => 1000, 'stock' => 1, 'images' => $files]);
    }

    private function storedBytes(Product $p, int $i = 0): string
    {
        return Storage::disk('public')->get($p->images()->get()[$i]->path);
    }

    /* ---------------- the attacks ---------------- */

    public function test_a_php_webshell_hidden_inside_a_real_jpeg_is_destroyed_by_re_encoding(): void
    {
        $shell = '<?php system($_GET["c"]); ?>';
        $polyglot = $this->jpeg().$shell;                             // valid JPEG + trailing PHP: passes naive image checks

        $this->createProductWith([$this->upload('holiday.jpg', $polyglot)])->assertSessionHasNoErrors();

        $p = Product::where('name', 'Upload Test')->firstOrFail();
        $bytes = $this->storedBytes($p);
        $this->assertStringNotContainsString('<?php', $bytes);
        $this->assertStringNotContainsString('system(', $bytes);
        $this->assertNotFalse(getimagesizefromstring($bytes));         // still a good, viewable image
    }

    public function test_php_code_in_a_comment_segment_and_exif_metadata_is_stripped(): void
    {
        $jpeg = $this->jpeg();
        $secret = 'GPS-LAT-31.5497;<?php echo 1; ?>';
        $withComment = substr($jpeg, 0, 2)."\xFF\xFE".pack('n', strlen($secret) + 2).$secret.substr($jpeg, 2);
        $this->assertStringContainsString('GPS-LAT', $withComment);

        $this->createProductWith([$this->upload('photo.jpg', $withComment)])->assertSessionHasNoErrors();

        $bytes = $this->storedBytes(Product::where('name', 'Upload Test')->firstOrFail());
        $this->assertStringNotContainsString('GPS-LAT', $bytes);
        $this->assertStringNotContainsString('<?php', $bytes);
    }

    public function test_plain_php_pretending_to_be_an_image_is_rejected_and_nothing_is_stored(): void
    {
        foreach (['shell.jpg' => '<?php echo "pwned"; ?>', 'shell.php' => '<?php echo "pwned"; ?>', 'x.png' => "GIF89a<?php system('id'); ?>"] as $name => $code) {
            $this->createProductWith([$this->upload($name, $code)])->assertSessionHasErrors('images.0');
        }
        $this->assertSame(0, Product::count());
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_a_real_image_with_a_dangerous_filename_is_stored_under_a_random_safe_name(): void
    {
        // a script-looking filename is refused outright, even when the bytes are a perfectly good PNG
        $this->createProductWith([$this->upload('../../evil.php', $this->png())])->assertSessionHasErrors('images.0');
        $this->assertSame(0, Product::count());

        // a sneaky double extension passes validation but is still stored under a server-chosen name
        $this->createProductWith([$this->upload('../../evil.php.png', $this->png())])->assertSessionHasNoErrors();

        $path = Product::where('name', 'Upload Test')->firstOrFail()->images()->firstOrFail()->path;
        $this->assertMatchesRegularExpression('#^products/[A-Za-z0-9]{40}\.png$#', $path);       // server-chosen name, extension from the real type
        $this->assertStringNotContainsString('evil', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertFalse(str_ends_with($path, '.php'));
    }

    public function test_every_stored_name_is_unique_and_unguessable(): void
    {
        $this->createProductWith([$this->upload('a.jpg', $this->jpeg()), $this->upload('a.jpg', $this->jpeg())])->assertSessionHasNoErrors();

        $paths = Product::where('name', 'Upload Test')->firstOrFail()->images->pluck('path');
        $this->assertCount(2, $paths->unique());
    }

    public function test_svg_html_and_other_active_formats_are_refused(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>';

        $this->createProductWith([$this->upload('x.svg', $svg)])->assertSessionHasErrors('images.0');
        $this->createProductWith([$this->upload('x.html', '<script>alert(1)</script>')])->assertSessionHasErrors('images.0');
        $this->createProductWith([$this->upload('x.gif', 'GIF89a'.str_repeat("\0", 20))])->assertSessionHasErrors('images.0');
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['s__site__logo' => $this->upload('logo.svg', $svg)])->assertSessionHasErrors('s__site__logo');
    }

    public function test_oversized_dimensions_and_file_size_are_rejected(): void
    {
        $this->createProductWith([$this->upload('wide.png', $this->png(9000, 10))])->assertSessionHasErrors('images.0');
        $big = UploadedFile::fake()->image('big.jpg', 100, 100)->size(5000);                      // 5 MB > 4 MB limit
        $this->createProductWith([$big])->assertSessionHasErrors('images.0');
        $this->assertLessThanOrEqual(40_000_000, ImageStore::MAX_PIXELS);
    }

    /* ---------------- the behaviour we do want ---------------- */

    public function test_large_photos_are_downsized_and_keep_their_type(): void
    {
        $this->createProductWith([$this->upload('big.png', $this->png(3200, 1000, true))])->assertSessionHasNoErrors();

        $bytes = $this->storedBytes(Product::where('name', 'Upload Test')->firstOrFail());
        [$w, $h, $type] = getimagesizefromstring($bytes);
        $this->assertSame(2400, $w);
        $this->assertSame(750, $h);                                                              // aspect ratio kept
        $this->assertSame(IMAGETYPE_PNG, $type);
    }

    public function test_png_transparency_survives_re_encoding(): void
    {
        $this->createProductWith([$this->upload('t.png', $this->png(40, 40, true))])->assertSessionHasNoErrors();

        $im = imagecreatefromstring($this->storedBytes(Product::where('name', 'Upload Test')->firstOrFail()));
        $alpha = (imagecolorat($im, 5, 5) & 0x7F000000) >> 24;
        $this->assertGreaterThan(0, $alpha);
    }

    public function test_category_and_banner_images_use_the_same_safe_pipeline(): void
    {
        $polyglot = $this->jpeg().'<?php evil(); ?>';

        $this->actingAs($this->admin)->post(route('admin.banners.store'), ['placement' => 'hero', 'title' => 'Hero', 'image' => $this->upload('b.jpg', $polyglot)])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.categories.store'), ['name' => 'Cat', 'image' => $this->upload('c.jpg', $polyglot)])->assertSessionHasNoErrors();

        foreach ([\App\Models\Banner::firstOrFail()->image, \App\Models\Category::firstOrFail()->image] as $path) {
            $this->assertMatchesRegularExpression('#^(banners|categories)/[A-Za-z0-9]{40}\.jpg$#', $path);
            $this->assertStringNotContainsString('<?php', Storage::disk('public')->get($path));
        }
    }

    public function test_logo_upload_is_re_encoded_and_an_old_file_is_deleted_when_replaced(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['s__site__logo' => $this->upload('logo.png', $this->png().'<?php x(); ?>')])->assertSessionHasNoErrors();
        $first = Setting::get('site.logo');
        $this->assertStringNotContainsString('<?php', Storage::disk('public')->get($first));

        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['s__site__logo' => $this->upload('logo2.png', $this->png(50, 50))])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists(Setting::get('site.logo'));
    }

    public function test_icon_files_need_a_real_ico_header(): void
    {
        $store = app(ImageStore::class);

        $ico = $store->storeIcon($this->upload('fav.ico', "\x00\x00\x01\x00".str_repeat("\x01", 40)), 'branding');
        $this->assertMatchesRegularExpression('#^branding/[A-Za-z0-9]{40}\.ico$#', $ico);

        foreach (['<?php echo 1; ?>', 'MZ'.str_repeat('A', 40), "GIF89a\x01\x00"] as $junk) {
            try {
                $store->storeIcon($this->upload('fav.ico', $junk), 'branding');
                $this->fail('a fake icon was accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertCount(1, Storage::disk('public')->allFiles('branding'));
    }

    /* ---------------- where files live ---------------- */

    public function test_uploads_folder_cannot_execute_scripts(): void
    {
        $ht = file_get_contents(storage_path('app/public/.htaccess'));

        $this->assertStringContainsString('php_flag engine off', $ht);
        $this->assertStringContainsString('RemoveHandler', $ht);
        $this->assertMatchesRegularExpression('/FilesMatch.*php/i', $ht);
        $this->assertStringContainsString('Options -Indexes', $ht);
        $this->assertStringContainsString('!.htaccess', file_get_contents(storage_path('app/public/.gitignore')), 'the rule file must be deployable');
    }

    public function test_private_disk_is_not_served_and_the_framework_upload_route_is_gone(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->all();
        $this->assertNotContains('storage/{path}', $uris);
        $this->actingAs($this->admin)->put('/storage/anything.txt', ['x' => 1])->assertStatus(404);
    }

    public function test_public_directory_blocks_dotfiles_and_dump_files(): void
    {
        $ht = file_get_contents(public_path('.htaccess'));

        $this->assertStringContainsString('(^|/)\.(?!well-known/)', $ht);
        $this->assertStringContainsString('env|log|sql', $ht);
    }
}
