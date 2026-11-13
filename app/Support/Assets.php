<?php

declare(strict_types=1);

namespace App\Support;

/** Which stylesheet the pages load: the single minified build while it is newer than its sources, else the two source files. */
final class Assets
{
    private static ?bool $fresh = null;

    public static function builtCss(): bool
    {
        if (self::$fresh === null) {
            $dir = PUBLIC_PATH . '/assets/abiha/';
            $built = @filemtime($dir . 'app.min.css');
            self::$fresh = $built !== false && $built >= (int) max(@filemtime($dir . 'abiha.css'), @filemtime($dir . 'theme.css'));
        }
        return self::$fresh;
    }
}
