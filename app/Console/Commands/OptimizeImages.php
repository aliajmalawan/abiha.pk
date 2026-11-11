<?php

namespace App\Console\Commands;

use App\Support\Img;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class OptimizeImages extends Command
{
    protected $signature = 'abiha:images-optimize {--force : Rebuild variants that already exist}';
    protected $description = 'Create responsive WebP variants for images uploaded before they were generated automatically';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $done = $skipped = 0;

        foreach ($disk->allFiles() as $path) {
            if (! preg_match('/\.(jpe?g|png|webp)$/i', $path) || preg_match('/-w\d+\.webp$/', $path)) {
                continue;                                         // not an original (a variant, ico, or something else)
            }
            if (! $this->option('force') && Img::widths($path) !== []) {
                $skipped++;
                continue;
            }
            Img::generate($path);
            $done++;
        }
        $this->info("Variants created for {$done} image(s); {$skipped} already had them.");

        return self::SUCCESS;
    }
}
