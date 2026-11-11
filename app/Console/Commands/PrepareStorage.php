<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * One idempotent post-deploy step: every writable folder exists, the uploads folder cannot run scripts, and uploaded
 * images are reachable at /storage (symlink, or a PUBLIC_DISK_ROOT inside the web root on hosts without symlinks).
 */
class PrepareStorage extends Command
{
    protected $signature = 'abiha:prepare-storage';

    protected $description = 'Create required folders, protect the uploads folder and link public storage. Safe to run on every deploy.';

    public function handle(): int
    {
        $failed = false;
        $root = Storage::disk('public')->path('');

        $dirs = [storage_path('framework/cache/data'), storage_path('framework/sessions'), storage_path('framework/views'), storage_path('logs'), base_path('bootstrap/cache'), $root];
        foreach ($dirs as $dir) {
            File::ensureDirectoryExists($dir, 0775);
            $ok = is_writable($dir);
            $failed = $failed || ! $ok;
            $this->line(($ok ? '  <fg=green>OK</>    ' : '  <fg=red>FAIL</>  ').$this->short($dir).($ok ? '' : ' is not writable: set permissions to 775 (or 755 if PHP runs as your cPanel user)'));
        }

        // Uploads are data, never code: copy the protection into whichever folder the public disk really uses.
        File::put(rtrim($root, '/'.chr(92)).DIRECTORY_SEPARATOR.'.htaccess', File::get(resource_path('stubs/uploads.htaccess')));
        $this->line('  <fg=green>OK</>    uploads folder protected (.htaccess blocks script execution)');

        $default = realpath(storage_path('app/public'));
        if ($default && realpath($root) === $default) {
            $link = public_path('storage');
            if (is_link($link) || file_exists($link)) {
                $this->line('  <fg=green>OK</>    public/storage already present');
            } else {
                try {
                    $made = @symlink($default, $link);
                } catch (\Throwable) {
                    $made = false;
                }
                if ($made) {
                    $this->line('  <fg=green>OK</>    public/storage → storage/app/public linked');
                } else {
                    $failed = true;
                    $this->line('  <fg=red>FAIL</>  could not create the public/storage symlink (this host may forbid symlinks).');
                    $this->line('        Fix: set PUBLIC_DISK_ROOT="'.public_path('storage').'" in .env, then run this command again.');
                }
            }
        } else {
            $this->line('  <fg=green>OK</>    public disk writes directly to '.$this->short($root).' (no symlink needed)');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function short(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
