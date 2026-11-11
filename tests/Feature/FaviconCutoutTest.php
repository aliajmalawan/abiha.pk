<?php

namespace Tests\Feature;

use App\Services\ImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** An uploaded favicon drawn on a white square must not show that white square in the browser tab. */
class FaviconCutoutTest extends TestCase
{
    private function png(bool $whiteBackground): UploadedFile
    {
        $img = imagecreatetruecolor(64, 64);
        imagefill($img, 0, 0, $whiteBackground ? imagecolorallocate($img, 255, 255, 255) : imagecolorallocate($img, 200, 30, 30));
        imagefilledellipse($img, 32, 32, 40, 40, imagecolorallocate($img, 20, 20, 20));
        imagefilledellipse($img, 32, 32, 14, 14, imagecolorallocate($img, 255, 255, 255));   // white INSIDE the artwork must stay
        $path = tempnam(sys_get_temp_dir(), 'fav').'.png';
        imagepng($img, $path);

        return new UploadedFile($path, 'icon.png', 'image/png', null, true);
    }

    private function alphaAt(string $stored, int $x, int $y): int
    {
        $im = imagecreatefromstring(Storage::disk('public')->get($stored));

        return (imagecolorat($im, $x, $y) >> 24) & 127;
    }

    public function test_the_white_background_becomes_transparent_but_white_inside_the_artwork_stays(): void
    {
        Storage::fake('public');
        $stored = app(ImageStore::class)->storeIcon($this->png(true), 'branding', 'site.favicon');

        $this->assertSame(127, $this->alphaAt($stored, 1, 1), 'the corner is fully transparent');
        $this->assertSame(0, $this->alphaAt($stored, 32, 32), 'the white centre inside the artwork is kept');
        $this->assertSame(0, $this->alphaAt($stored, 32, 16), 'the dark ring is kept');
    }

    public function test_an_icon_that_is_not_on_white_is_left_alone(): void
    {
        Storage::fake('public');
        $stored = app(ImageStore::class)->storeIcon($this->png(false), 'branding', 'site.favicon');

        $this->assertSame(0, $this->alphaAt($stored, 1, 1), 'a red background is not removed');
    }
}
