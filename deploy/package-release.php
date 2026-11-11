<?php

/*
 * Build an upload-ready release for cPanel.
 *
 *     php deploy/package-release.php [output-dir]          (default: dist/abiha-release)
 *
 * Produces:
 *     <output>/abiha/          the application: upload to /home/CPANELUSER/abiha   (private, above the web root)
 *     <output>/public_html/    public files + the split-layout index.php: upload to /home/CPANELUSER/public_html
 *     <output>.zip             both folders in one archive, when this PHP (ZipArchive) or the OS `tar` can make one
 *
 * Left OUT on purpose: .env and any credentials, tests, node_modules, source maps, development uploads and logs, SQLite
 * files, brand master files, Vite dev state, cached framework files (they are rebuilt on the server).
 * Run `npm run build` first so public/build is current.
 */

$root = dirname(__DIR__);
$out = rtrim($argv[1] ?? $root.'/dist/abiha-release', '/\\');
$sep = DIRECTORY_SEPARATOR;

if (! is_file($root.'/public/build/manifest.json')) {
    fwrite(STDERR, "public/build/manifest.json is missing: run `npm run build` first.\n");
    exit(1);
}

// Only these top-level paths belong in the private application folder.
$include = ['app', 'bootstrap', 'config', 'database', 'lang', 'resources', 'routes', 'storage', 'artisan', 'composer.json', 'composer.lock', '.env.production.example', 'README.md', 'docs', 'deploy'];   // deploy/ carries backup.sh for the server's cron job

// Never copied, wherever they sit (matched against the path relative to the project root, with / separators).
$excluded = [
    '#^storage/logs/(?!\.gitignore)#', '#^storage/framework/(cache|sessions|views|testing)/.+#', '#^storage/app/(public|private)/(?!\.gitignore$|\.htaccess$)#', '#^storage/pail#',
    '#^bootstrap/cache/(?!\.gitignore$)#', '#^database/.*\.sqlite#', '#^database/.*\.sqlite-journal#', '#(^|/)\.env$#', '#(^|/)\.env\.(?!production\.example$)#',
    '#\.map$#', '#(^|/)\.git(/|$)#', '#(^|/)\.DS_Store$#', '#(^|/)Thumbs\.db$#', '#^storage/app/public/\.gitignore$#',
];

$skip = static function (string $rel) use ($excluded): bool {
    foreach ($excluded as $pattern) {
        if (preg_match($pattern, $rel)) {
            return true;
        }
    }

    return false;
};

$copy = static function (string $from, string $to, string $base, callable $skip) use (&$copy): int {
    $n = 0;
    if (is_dir($from)) {
        $dirRel = ltrim(str_replace(chr(92), '/', substr($from, strlen($base))), '/');
        if ($dirRel !== '' && $skip($dirRel.'/')) {
            return 0;                                   // an excluded folder is not even created empty
        }
        @mkdir($to, 0775, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $n += $copy($from.'/'.$item, $to.'/'.$item, $base, $skip);
        }

        return $n;
    }
    $rel = ltrim(str_replace('\\', '/', substr($from, strlen($base))), '/');
    if ($skip($rel)) {
        return 0;
    }
    @mkdir(dirname($to), 0775, true);
    copy($from, $to);

    return 1;
};

$rmTree = static function (string $dir) use (&$rmTree): void {
    if (! is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $i) {
        if ($i !== '.' && $i !== '..') {
            is_dir("{$dir}/{$i}") && ! is_link("{$dir}/{$i}") ? $rmTree("{$dir}/{$i}") : @unlink("{$dir}/{$i}");
        }
    }
    @rmdir($dir);
};

echo "Building release in {$out}\n";
$rmTree($out);
@unlink($out.'.zip');
mkdir($out.'/abiha', 0775, true);
mkdir($out.'/public_html', 0775, true);

$files = 0;
foreach ($include as $path) {
    if (file_exists($root.'/'.$path)) {
        $files += $copy($root.'/'.$path, $out.'/abiha/'.$path, $root, $skip);
    }
}
echo "  application: {$files} files\n";

// public/ → public_html (no dev artefacts), with the split-layout front controller
$publicSkip = static fn (string $rel): bool => (bool) preg_match('#^public/(hot$|storage(/|$)|index\.php$|\.DS_Store$)|\.map$#', $rel);
$pub = $copy($root.'/public', $out.'/public_html', $root, $publicSkip);
copy(__DIR__.'/cpanel/index.php', $out.'/public_html/index.php');
echo '  public_html: '.($pub + 1)." files\n";

// Framework folders that must exist (empty) on the server
foreach (['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'storage/app/public', 'bootstrap/cache'] as $dir) {
    @mkdir($out.'/abiha/'.$dir, 0775, true);
}
copy($root.'/resources/stubs/uploads.htaccess', $out.'/abiha/storage/app/public/.htaccess');

// Production dependencies only (no phpunit, faker, collision …) when Composer is available.
$composer = null;
foreach ([getenv('COMPOSER_BIN') ?: '', $root.'/composer.phar'] as $candidate) {
    if ($candidate && is_file($candidate)) {
        $composer = PHP_BINARY.' '.escapeshellarg($candidate);
    }
}
if (! $composer) {
    $probe = stripos(PHP_OS, 'WIN') === 0 ? 'where composer 2>NUL' : 'command -v composer 2>/dev/null';
    $found = trim((string) @shell_exec($probe));
    $composer = $found !== '' ? escapeshellarg(strtok($found, "\r\n")) : null;
}
if ($composer) {
    echo "  installing production dependencies with Composer (no dev packages)...\n";
    passthru("{$composer} install --no-dev --optimize-autoloader --no-interaction --no-scripts --working-dir=".escapeshellarg($out.'/abiha'), $code);
    if ($code !== 0) {
        fwrite(STDERR, "Composer failed (exit {$code}).\n");
        exit(1);
    }
} else {
    echo "  WARNING: Composer was not found, so the CURRENT vendor/ folder (including development packages) is copied.\n";
    echo "           Better: install Composer, or run `composer install --no-dev -o` on the server (docs/DEPLOYMENT.md).\n";
    $copy($root.'/vendor', $out.'/abiha/vendor', $root, static fn (string $rel): bool => false);
}

file_put_contents($out.'/READ-ME-FIRST.txt', "ABIHA.PK release\n\n1. Upload the folder 'abiha' to /home/CPANELUSER/abiha  (NOT inside public_html).\n2. Upload the contents of 'public_html' into /home/CPANELUSER/public_html.\n3. Create .env in the abiha folder from .env.production.example and fill it in.\n4. Follow docs/DEPLOYMENT.md (database, migrations, php artisan abiha:prepare-storage, optimize, abiha:preflight).\n");

$zip = false;
if (class_exists('ZipArchive')) {
    $za = new ZipArchive;
    if ($za->open($out.'.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $za->addFile($f->getPathname(), str_replace('\\', '/', substr($f->getPathname(), strlen($out) + 1)));
        }
        $za->close();
        $zip = true;
    }
} elseif (stripos(PHP_OS, 'WIN') === 0 || is_file('/usr/bin/zip')) {
    $cmd = stripos(PHP_OS, 'WIN') === 0 ? escapeshellarg((getenv('SystemRoot') ?: 'C:/Windows').'/System32/tar.exe').' -a -c -f '.escapeshellarg($out.'.zip').' -C '.escapeshellarg($out).' .' : 'cd '.escapeshellarg($out).' && zip -qr '.escapeshellarg($out.'.zip').' .';
    @exec($cmd, $o, $code);
    $zip = $code === 0 && is_file($out.'.zip');
}
echo $zip ? "  archive: {$out}.zip\n" : "  (no archiver available: upload the two folders directly, or zip {$out} yourself)\n";
echo "Done. Next: docs/DEPLOYMENT.md\n";
