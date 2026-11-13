<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Config;
use App\Services\Payments\Drivers\SafepayDriver;
use App\Support\Secrets;
use App\Support\Settings;

/**
 * Card (Safepay) keys entered in Admin -> Payment methods. They are kept in the settings table: the API key as typed,
 * the secret key and the webhook secret encrypted (App\Support\Secrets). A value saved here replaces the .env value of the
 * same name; .env still works when nothing is saved. apply() runs once per request from SiteSettings::apply().
 */
final class CardSettings
{
    private const KEY = 'payments.card';

    /** @return array{key:string,secret:string,webhook_secret:string,environment:string} saved values, decrypted ('' = not saved) */
    public static function stored(): array
    {
        $raw = Settings::get(self::KEY);
        $raw = is_array($raw) ? $raw : [];
        return [
            'key' => trim((string) ($raw['key'] ?? '')),
            'secret' => Secrets::decrypt($raw['secret'] ?? null),
            'webhook_secret' => Secrets::decrypt($raw['webhook_secret'] ?? null),
            'environment' => ($raw['environment'] ?? '') === 'production' ? 'production' : (($raw['environment'] ?? '') === 'sandbox' ? 'sandbox' : ''),
        ];
    }

    /**
     * Save what the admin typed. A blank secret field keeps the saved value; $clear removes every saved key.
     * @param array<string,string> $input key, secret, webhook_secret, environment
     */
    public static function save(array $input, bool $clear = false): void
    {
        if ($clear) {
            Settings::put(self::KEY, null);
            return;
        }
        $raw = Settings::get(self::KEY);
        $raw = is_array($raw) ? $raw : [];
        $key = trim((string) ($input['key'] ?? ''));
        $secret = trim((string) ($input['secret'] ?? ''));
        $hook = trim((string) ($input['webhook_secret'] ?? ''));
        $env = (string) ($input['environment'] ?? '');
        if ($key !== '') {
            $raw['key'] = $key;
        }
        if ($secret !== '') {
            $raw['secret'] = Secrets::encrypt($secret);
        }
        if ($hook !== '') {
            $raw['webhook_secret'] = Secrets::encrypt($hook);
        }
        if (in_array($env, ['sandbox', 'production'], true)) {
            $raw['environment'] = $env;
        }
        Settings::put(self::KEY, $raw ?: null);
    }

    /** Put the saved values over the .env ones (nothing saved = nothing changes). Safe to call many times. */
    public static function apply(): void
    {
        try {
            $s = self::stored();
        } catch (\Throwable) {
            return;
        }
        $cfg = (array) Config::get('payments.card', []);
        foreach (['key', 'secret', 'webhook_secret', 'environment'] as $k) {
            if ($s[$k] !== '') {
                $cfg[$k] = $s[$k];
            }
        }
        if (trim((string) ($cfg['driver'] ?? '')) === '' && ($s['key'] !== '' || $s['secret'] !== '')) {
            $cfg['driver'] = SafepayDriver::class;      // Safepay is the provider this shop supports
        }
        Config::set('payments.card', $cfg);
    }
}
