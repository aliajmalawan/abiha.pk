<?php
// ABIHA photo check. Upload to  public_html/abiha.pk/public/storage-check.php  and open  https://abiha.pk/storage-check.php?k=abiha-check
// It prints only folder paths and permissions (no passwords, no keys). DELETE THIS FILE as soon as you have copied the result.
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['k'] ?? '') !== 'abiha-check') { http_response_code(404); exit('not found'); }

$public = __DIR__;
$app = dirname($public);
$perm = fn ($p) => file_exists($p) ? substr(sprintf('%o', fileperms($p)), -4) : '(missing)';
$line = fn ($k, $v) => print(str_pad($k, 34).$v."\n");

$line('PHP', PHP_VERSION);
$line('public folder', $public);
$line('app folder', $app);
$line('web server user id', function_exists('posix_geteuid') ? posix_geteuid() : '?');

$env = @file_get_contents($app.'/.env') ?: '';
preg_match('/^APP_URL=(.*)$/m', $env, $m1);
preg_match('/^PUBLIC_DISK_ROOT=(.*)$/m', $env, $m2);
$line('APP_URL', trim($m1[1] ?? '(not set)'));
$line('PUBLIC_DISK_ROOT', trim($m2[1] ?? '(not set)'));

echo "\n-- public/storage --\n";
$ps = $public.'/storage';
$line('exists', file_exists($ps) || is_link($ps) ? 'yes' : 'NO');
$line('is a symlink', is_link($ps) ? 'yes -> '.readlink($ps) : 'no');
$line('is a folder', is_dir($ps) ? 'yes' : 'no');
$line('permissions', $perm($ps));
$line('public/storage/.htaccess', file_exists($ps.'/.htaccess') ? 'yes' : 'no');

echo "\n-- storage/app/public --\n";
$real = $app.'/storage/app/public';
foreach (['storage' => $app.'/storage', 'storage/app' => $app.'/storage/app', 'storage/app/public' => $real, 'storage/app/public/products' => $real.'/products'] as $k => $p) {
    $line($k, $perm($p).(is_dir($p) ? '  readable:'.(is_readable($p) ? 'yes' : 'NO') : ''));
}
$files = is_dir($real.'/products') ? array_values(array_filter(scandir($real.'/products'), fn ($f) => $f[0] !== '.')) : [];
$line('photos in products folder', count($files));
$sample = $files[0] ?? null;
if ($sample) {
    $line('sample photo', $sample);
    $line('sample photo permissions', $perm($real.'/products/'.$sample).'  readable:'.(is_readable($real.'/products/'.$sample) ? 'yes' : 'NO'));
    $line('reachable through public/storage', is_file($ps.'/products/'.$sample) ? 'yes' : 'NO');
}
$wrong = array_filter($files, fn ($f) => str_contains($f, '\\'));
$line('files with a backslash in the name', count($wrong).(count($wrong) ? '  (zip was made on Windows: files must be moved into products/)' : ''));

echo "\n-- what the browser really opens: public/storage/products --\n";
$pp = $ps.'/products';
$line('public/storage/products', $perm($pp).(is_dir($pp) ? '  readable:'.(is_readable($pp) ? 'yes' : 'NO') : ''));
$pf = is_dir($pp) ? array_values(array_filter(scandir($pp), fn ($f) => $f[0] !== '.')) : [];
$line('photos there', count($pf));
$sf = $files[0] ?? ($pf[0] ?? null);
if ($sf) {
    $line('same sample photo there', file_exists($pp.'/'.$sf) ? $perm($pp.'/'.$sf).'  readable:'.(is_readable($pp.'/'.$sf) ? 'yes' : 'NO') : 'MISSING');
}
$line('public folder permissions', $perm($public));
$line('public_html/abiha.pk permissions', $perm($app));
$line('public_html permissions', $perm(dirname($app)));
$line('home folder permissions', $perm(dirname(dirname($app))));
$line('products/.htaccess', file_exists($pp.'/.htaccess') ? 'yes' : 'no');

echo "\n-- public/storage/.htaccess (first lines) --\n";
echo implode("\n", array_slice(preg_split('/\R/', (string) @file_get_contents($ps.'/.htaccess')), 0, 22))."\n";

echo "\n-- web server rules --\n";
$ht = @file_get_contents($public.'/.htaccess') ?: '';
$line('public/.htaccess', $ht !== '' ? 'found' : 'MISSING');
$line('mentions SymLinks', preg_match('/SymLinks/i', $ht) ? 'yes' : 'no');
$line('open_basedir', ini_get('open_basedir') ?: '(none)');
