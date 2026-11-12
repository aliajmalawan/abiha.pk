<?php

declare(strict_types=1);

namespace App\Support;

use App\Auth\Auth;

/**
 * The editable link lists of the storefront (Admin -> Menus): the header links next to the "Shop" mega menu, and two
 * footer columns (port of the old App\Support\Menus). Until the owner saves a list, the defaults below are used. A saved
 * list (setting "menu.<key>") is used exactly as saved (an empty list shows no links).
 */
final class Menus
{
    /** @var array<string,string> key => admin label */
    public const LISTS = [
        'header' => 'Header links (next to Shop)',
        'footer_shop' => 'Footer: Shop column',
        'footer_help' => 'Footer: Help column',
    ];

    public const DEFAULTS = [
        'header' => [
            ['label' => 'New In', 'url' => '/shop?sort=newest'],
            ['label' => 'Sale', 'url' => '/shop?sale=1'],
            ['label' => 'Track order', 'url' => '/track-order'],
        ],
        'footer_shop' => [
            ['label' => 'New in', 'url' => '/shop?sort=newest'],
            ['label' => 'All products', 'url' => '/shop'],
            ['label' => 'Sale', 'url' => '/shop?sale=1'],
        ],
        'footer_help' => [
            ['label' => 'Shipping & returns', 'url' => '/shipping-returns'],
            ['label' => 'Track your order', 'url' => '/track-order'],
            ['label' => 'Contact us', 'url' => '/contact'],
            ['label' => 'My account', 'url' => '/account'],
        ],
    ];

    /** @return list<array{label:string,url:string}> the stored (or default) rows, for the admin form */
    public static function rows(string $key): array
    {
        $saved = Settings::get('menu.' . $key);
        return array_values(is_array($saved) ? $saved : (self::DEFAULTS[$key] ?? []));
    }

    /** @return list<array{label:string,url:string,href:string}> ready to print: only safe links, with absolute addresses */
    public static function get(string $key): array
    {
        $out = [];
        foreach (self::rows($key) as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $url = trim((string) ($row['url'] ?? ''));
            if ($label === '' || !self::safe($url)) {
                continue;
            }
            $target = ($url === '/account' && !Auth::check()) ? '/login' : $url;   // a visitor who is not signed in goes straight to sign-in
            $out[] = ['label' => $label, 'url' => $url, 'href' => str_starts_with($target, '/') ? url($target) : $target];
        }
        return $out;
    }

    /** A site path ("/shop") or a full https:// / http:// address. Never javascript:, data: or a protocol-relative "//host". */
    public static function safe(string $url): bool
    {
        return (bool) preg_match('#^(/(?!/)[^\s]*|https?://[^\s]+)$#i', $url);
    }
}
