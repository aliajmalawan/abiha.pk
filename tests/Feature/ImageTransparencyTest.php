<?php

namespace Tests\Feature;

use App\Support\Img;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A logo with a see-through background must stay see-through in its resized WebP versions (no grey box on a white page). */
class ImageTransparencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function alphaAt(string $webpPath, int $x, int $y): int
    {
        $im = imagecreatefromwebp(Storage::disk('public')->path($webpPath));

        return (imagecolorat($im, $x, $y) >> 24) & 127;       // GD alpha: 0 = opaque, 127 = fully transparent
    }

    public function test_a_transparent_logo_keeps_a_fully_transparent_background_in_every_webp_size(): void
    {
        $im = imagecreatetruecolor(1400, 900);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledellipse($im, 700, 450, 700, 400, imagecolorallocatealpha($im, 201, 154, 53, 0));     // the "logo"
        ob_start();
        imagepng($im);
        Storage::disk('public')->put('branding/logo.png', ob_get_clean());

        $widths = Img::generate('branding/logo.png');
        $this->assertNotEmpty($widths);

        foreach ($widths as $w) {
            $variant = Img::variantPath('branding/logo.png', $w);
            $this->assertSame(127, $this->alphaAt($variant, 1, 1), "{$w}px: the corner must be fully transparent, not a faint haze");
            $this->assertSame(127, $this->alphaAt($variant, $w - 2, 1), "{$w}px: opposite corner");
            $this->assertSame(0, $this->alphaAt($variant, intdiv($w, 2), (int) round($w * 900 / 1400 / 2)), "{$w}px: the logo itself stays opaque");
        }
    }

    public function test_image_urls_carry_a_version_that_changes_when_the_picture_is_regenerated(): void
    {
        $im = imagecreatetruecolor(900, 600);
        imagefill($im, 0, 0, imagecolorallocate($im, 90, 60, 40));
        ob_start();
        imagejpeg($im, null, 90);
        Storage::disk('public')->put('branding/l.jpg', ob_get_clean());
        Img::generate('branding/l.jpg');

        $first = Img::url('branding/l.jpg');
        $this->assertMatchesRegularExpression('#branding/l\.jpg\?v=\d+$#', $first);
        $this->assertStringContainsString('?v='.explode('?v=', $first)[1].' 320w', Img::srcset('branding/l.jpg'), 'the variants carry the same version');

        // Regenerating in place (for example after a fix to the resizing) gives browsers a new address, so a cached old file is not reused.
        \Illuminate\Support\Facades\Cache::forever('img.v.'.md5('branding/l.jpg'), '1');
        $this->assertStringEndsWith('?v=1', Img::url('branding/l.jpg'));
        Img::generate('branding/l.jpg');
        $this->assertNotSame('?v=1', substr(Img::url('branding/l.jpg'), -4));
    }
    public function test_photos_without_transparency_are_not_touched(): void
    {
        $im = imagecreatetruecolor(1400, 900);
        imagefill($im, 0, 0, imagecolorallocate($im, 120, 80, 60));
        ob_start();
        imagejpeg($im, null, 90);
        Storage::disk('public')->put('products/photo.jpg', ob_get_clean());

        foreach (Img::generate('products/photo.jpg') as $w) {
            $this->assertSame(0, $this->alphaAt(Img::variantPath('products/photo.jpg', $w), 5, 5));
        }
    }
}
