<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Applies admin-editable settings on top of config/*.php defaults. Called once per request at boot.
 * Safe when the settings table does not exist yet (fresh install / during migrations).
 */
class SiteSettings
{
    public static function apply(): void
    {
        try {
            $name = trim((string) Setting::get('site.name', ''));
            $code = strtoupper(trim((string) Setting::get('currency.code', '')));
            $symbol = trim((string) Setting::get('currency.symbol', ''));
            $fromAddress = Setting::get('mail.from_address');
            $fromName = Setting::get('mail.from_name');
        } catch (\Throwable) {
            return;                                     // table not migrated yet
        }

        config(array_filter([
            'abiha.name' => $name ?: null,
            'abiha.currency.code' => $code ?: null,
            'abiha.currency.symbol' => $symbol ?: null,
            'mail.from.address' => $fromAddress ?: null,
            'mail.from.name' => $fromName ?: null,
        ], fn ($v) => $v !== null));
    }

    /** Public URL of an uploaded branding file (logo/favicon), or null to use the supplied artwork. */
    public static function asset(string $key): ?string
    {
        $path = Setting::get($key);

        return $path && Storage::disk('public')->exists($path) ? \App\Support\Img::url($path) : null;
    }
}
