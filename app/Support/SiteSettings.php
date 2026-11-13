<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Admin-editable settings on top of the app/Config defaults (port of the old App\Support\SiteSettings).
 *
 *   SiteSettings::apply()            once per request (Kernel) and console run: site.name -> abiha.name,
 *                                    currency.code/currency.symbol -> abiha.currency.*, mail.from_address/from_name -> mail.*
 *   SiteSettings::asset('site.logo') public URL of an uploaded branding file (logo/favicon), or null = use the supplied artwork
 *   SiteSettings::logoPath()         stored path of the uploaded logo when the file exists, else null
 *
 * Safe when the settings table does not exist yet (fresh install / during migrations).
 */
final class SiteSettings
{
    /** @var array<string,mixed> config defaults before apply(), so apply() can run more than once (tests) */
    private static ?array $defaults = null;

    public static function apply(): void
    {
        self::$defaults ??= [
            'abiha.name' => Config::get('abiha.name'),
            'abiha.currency.code' => Config::get('abiha.currency.code'),
            'abiha.currency.symbol' => Config::get('abiha.currency.symbol'),
            'mail.from_address' => Config::get('mail.from_address'),
            'mail.from_name' => Config::get('mail.from_name'),
        ];
        foreach (self::$defaults as $k => $v) {
            Config::set($k, $v);
        }
        try {
            $name = trim((string) Settings::get('site.name', ''));
            $code = strtoupper(trim((string) Settings::get('currency.code', '')));
            $symbol = trim((string) Settings::get('currency.symbol', ''));
            $fromAddress = trim((string) Settings::get('mail.from_address', ''));
            $fromName = trim((string) Settings::get('mail.from_name', ''));
        } catch (\Throwable) {
            return;
        }
        $over = array_filter([
            'abiha.name' => $name,
            'abiha.currency.code' => $code,
            'abiha.currency.symbol' => $symbol,
            'mail.from_address' => $fromAddress,
            'mail.from_name' => $fromName,
        ], static fn ($v) => $v !== '');
        foreach ($over as $k => $v) {
            Config::set($k, $v);
        }
        \App\Services\Payments\CardSettings::apply();   // card keys saved in Admin -> Payment methods
    }

    /** Public URL of an uploaded branding file (setting key such as site.logo / site.favicon), or null. */
    public static function asset(string $key): ?string
    {
        $path = Settings::get($key);
        return is_string($path) && $path !== '' && Img::exists($path) ? Img::url($path) : null;
    }

    public static function logoPath(): ?string
    {
        $path = Settings::get('site.logo');
        return is_string($path) && $path !== '' && Img::exists($path) ? $path : null;
    }
}
