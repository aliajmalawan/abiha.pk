<?php

declare(strict_types=1);

/*
 * High-quality media: product videos (links and uploads: parsing, validation by file signature, replace / remove, the
 * player on the product page), the photo viewer hooks on the product gallery, and the security policy that allows
 * exactly the video sources the shop needs and nothing more.
 */

use App\Auth\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Session;
use App\Support\Img;
use App\Support\Video;

T::$current = 'Media';
reset_db();
$db = Database::get();

/** Admin request with uploaded files: $files = ['images' => [file...], 'video_file' => file]. */
function md_http(string $method, string $path, array $input, array $user, array $files = []): App\Core\Response
{
    Auth::actingAs($user);
    Auth::forget();
    Session::ageFlash();
    $input['_token'] ??= Csrf::token();
    $php = [];
    foreach ($files as $field => $f) {
        if (isset($f['tmp_name'])) {
            $php[$field] = $f;
        } else {
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) {
                $php[$field][$k] = array_column($f, $k);
            }
        }
    }
    $req = Request::create($method, $path, $input, ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'text/html'], $php);
    return Kernel::handle($req);
}

function md_video(string $kind = 'mp4', int $extra = 200): array
{
    $head = $kind === 'webm' ? "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01" : "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";
    $file = BASE_PATH . '/tests/.tmp/clip-' . bin2hex(random_bytes(4)) . '.' . $kind;
    file_put_contents($file, $head . str_repeat("\x00", $extra));
    return ['name' => 'clip.' . $kind, 'type' => 'video/' . $kind, 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)];
}

// ---- links are understood, and only safe ones
$yt = Video::parse('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
T::eq('youtube', $yt['type'] ?? null, 'YouTube watch link');
T::eq('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&modestbranding=1&playsinline=1&autoplay=1', $yt['embed'] ?? null, 'YouTube plays through the no-cookie domain');
T::contains('/embed/dQw4w9WgXcQ?', (string) (Video::parse('https://youtu.be/dQw4w9WgXcQ?t=5')['embed'] ?? ''), 'youtu.be short link');
T::eq('youtube', Video::parse('https://www.youtube.com/shorts/dQw4w9WgXcQ')['type'] ?? null, 'YouTube Shorts link');
T::eq('youtube', Video::parse('https://m.youtube.com/watch?v=dQw4w9WgXcQ&list=x')['type'] ?? null, 'mobile YouTube link');
$vi = Video::parse('https://vimeo.com/123456789');
T::eq('https://player.vimeo.com/video/123456789?autoplay=1&dnt=1', $vi['embed'] ?? null, 'Vimeo link plays with "do not track"');
T::eq('file', Video::parse('https://cdn.example.test/clips/shirt.mp4')['type'] ?? null, 'a direct .mp4 link');
T::eq('file', Video::parse('https://cdn.example.test/clips/shirt.webm?x=1')['type'] ?? null, 'a direct .webm link');
T::eq(null, Video::parse('http://www.youtube.com/watch?v=dQw4w9WgXcQ'), 'a plain http link is refused');
T::eq(null, Video::parse('javascript:alert(1)'), 'a script link is refused');
T::eq(null, Video::parse('https://evil.example.test/page'), 'any other page is refused');
T::eq(null, Video::parse('https://www.youtube.com/watch?v=short'), 'a bad YouTube id is refused');
T::eq(null, Video::parse('https://vimeo.com/channels/staffpicks'), 'a Vimeo page without a video id is refused');
T::eq(null, Video::parse(''), 'empty means no video');
T::eq(null, Video::parse('videos/../../.env'), 'a stored path must look exactly like one of ours');
T::eq(null, Video::parse('videos/x.mp4'), 'a short stored name is refused');

// ---- uploads are judged by their real content
T::eq('mp4', Video::ext(md_video('mp4')['tmp_name']), 'MP4 signature');
T::eq('webm', Video::ext(md_video('webm')['tmp_name']), 'WebM signature');
$fake = BASE_PATH . '/tests/.tmp/fake-' . bin2hex(random_bytes(3)) . '.mp4';
file_put_contents($fake, '<?php echo 1; ' . str_repeat('x', 100));
T::eq(null, Video::ext($fake), 'a script renamed to .mp4 is not a video');
T::ok(isset(Video::check(['tmp_name' => $fake, 'size' => 120, 'error' => 0, 'name' => 'a.mp4'], '')['video_file']), 'check refuses the fake video');
T::ok(isset(Video::check(['tmp_name' => md_video()['tmp_name'], 'size' => Video::MAX_BYTES + 1, 'error' => 0, 'name' => 'a.mp4'], '')['video_file']), 'check refuses a video over 25 MB');
T::ok(isset(Video::check(null, 'https://evil.example.test/x')['video_url']), 'check refuses a bad link');
T::eq(null, Video::check(md_video(), 'https://youtu.be/dQw4w9WgXcQ'), 'a good file and a good link pass');

// ---- admin: add, replace and remove a video
$admin = Fx::user('admin');
$p = Fx::product(['name' => 'Clip Kurta', 'sku' => 'VID-1', 'slug' => 'clip-kurta']);
$base = ['name' => 'Clip Kurta', 'sku' => 'VID-1', 'price' => 1800, 'stock' => 5, 'is_active' => 1];
$url = '/admin/products/' . $p['id'];
$video = static fn () => $db->value('SELECT video FROM products WHERE id = ?', [$p['id']]);

md_http('PUT', $url, $base, $admin, ['images' => [Fx::image(800, 1000), Fx::image(800, 1000)]]);
T::eq(null, $video(), 'no video yet');
$page = http('GET', '/products/clip-kurta', [], false)->body;
T::ok(!str_contains($page, 'pdp-video'), 'no video block without a video');
T::contains('data-lightbox="', $page, 'the gallery carries the photo viewer data');
T::contains('data-lightbox-open', $page, 'the enlarge button is on the page');
T::contains('assets/abiha/media.js', $page, 'the media script is loaded on the product page');
$lb = [];
if (preg_match('/data-lightbox="([^"]*)"/', $page, $m)) {
    $lb = json_decode(html_entity_decode($m[1]), true) ?: [];
}
T::eq(2, count($lb), 'the viewer has both photos');
T::ok(isset($lb[0]['src'], $lb[0]['thumb'], $lb[0]['alt']), 'each photo has a large file, a thumbnail and alt text');

md_http('PUT', $url, $base + ['video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'], $admin);
T::eq('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $video(), 'a YouTube link is saved');
$page = http('GET', '/products/clip-kurta', [], false)->body;
T::contains('data-video-embed="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $page, 'the page has a click-to-load YouTube player');
T::contains('data-video-play', $page, 'with a play button');
T::ok(!str_contains($page, '<iframe'), 'no player is loaded until the visitor clicks');

md_http('PUT', $url, $base + ['video_url' => 'https://evil.example.test/x'], $admin);
T::eq('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $video(), 'a bad link is rejected and the old video stays');

md_http('PUT', $url, $base + ['video_url' => ''], $admin);
T::eq(null, $video(), 'emptying the link box removes a link video');

$r = md_http('PUT', $url, $base + ['video_url' => ''], $admin, ['video_file' => md_video('mp4')]);
$stored = (string) $video();
T::ok(preg_match('#^videos/[a-z0-9]{24}\.mp4$#', $stored) === 1, 'an uploaded MP4 is stored under a random name');
T::ok(Img::exists($stored), 'the file is on the public disk');
$page = http('GET', '/products/clip-kurta', [], false)->body;
T::contains('<video class="pdp-video__el" controls playsinline preload="none"', $page, 'an uploaded video uses the browser player');
T::contains('type="video/mp4"', $page, 'with its type');
T::ok(!str_contains($page, 'autoplay'), 'and never plays by itself');

md_http('PUT', $url, $base + ['video_url' => ''], $admin);
T::eq($stored, $video(), 'an unchanged form keeps an uploaded video');

md_http('PUT', $url, $base, $admin, ['video_file' => md_video('webm')]);
$second = (string) $video();
T::ok($second !== $stored && str_ends_with($second, '.webm'), 'a new upload replaces the old one');
T::ok(!Img::exists($stored) && Img::exists($second), 'the old file is deleted, the new one kept');

$bad = ['name' => 'evil.mp4', 'type' => 'video/mp4', 'tmp_name' => $fake, 'error' => UPLOAD_ERR_OK, 'size' => filesize($fake)];
md_http('PUT', $url, $base, $admin, ['video_file' => $bad]);
T::eq($second, $video(), 'a fake video is refused and the current one stays');
T::eq(0, count(glob(Img::root() . '/videos/*.php') ?: []), 'nothing executable was stored');

md_http('PUT', $url, $base + ['remove_video' => '1'], $admin);
T::eq(null, $video(), 'ticking "remove" clears the video');
T::ok(!Img::exists($second), 'and deletes the uploaded file');
T::ok(!str_contains(http('GET', '/products/clip-kurta', [], false)->body, 'pdp-video'), 'the video block is gone');

// ---- the admin form shows the video fields
$form = http('GET', $url . '/edit', [], $admin)->body;
foreach (['Product video', 'name="video_url"', 'name="video_file"', 'video/mp4,video/webm'] as $needle) {
    T::contains($needle, $form, "product form: {$needle}");
}

// ---- the security policy allows exactly the video sources we use
$csp = (string) (http('GET', '/', [], false)->headers['Content-Security-Policy'] ?? '');
T::contains('frame-src https://www.youtube-nocookie.com https://player.vimeo.com', $csp, 'only YouTube (no-cookie) and Vimeo may be framed');
T::contains("media-src 'self' https:", $csp, 'videos load from the shop or https links');
T::contains("object-src 'none'", $csp, 'plugins stay blocked');
T::ok(!str_contains($csp, "'unsafe-inline'") || preg_match("/script-src[^;]*'unsafe-inline'/", $csp) === 0, 'scripts still cannot be inline');

Auth::actingAs(null);
