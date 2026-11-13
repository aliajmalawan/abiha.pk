<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Support\Settings;

/**
 * Data for the content pages (port of the private helpers of the old Storefront\PageController):
 *
 *   ContentPages::faqGroups()       the built-in FAQ (app/Config/faq.php) with its {tokens} turned into links / live values
 *   ContentPages::aboutData()       collections (top-level categories with products), paymentLine, phone, email
 *   ContentPages::paymentLabels()   labels of the payment methods that are switched on AND available (PaymentManager::enabled())
 *   ContentPages::shippingMethods() active shipping methods by sort_order (ShippingService::methods())
 *   ContentPages::join($list, ', ', ' and ')   Laravel's Collection::join
 */
final class ContentPages
{
    private const LABELS = ['cod' => 'Cash on Delivery', 'bank_transfer' => 'Bank Transfer', 'card' => 'Debit / Credit Card'];

    public static function phone(): string
    {
        return trim((string) Settings::get('contact.phone', Config::get('abiha.contact.phone')));
    }

    public static function email(): string
    {
        return trim((string) Settings::get('contact.email', Config::get('abiha.contact.email')));
    }

    /** @return list<string> */
    public static function paymentLabels(): array
    {
        if (class_exists(\App\Services\Payments\PaymentManager::class) && method_exists(\App\Services\Payments\PaymentManager::class, 'enabled')) {
            return array_values(array_map(static fn ($g): string => (string) $g->label(), \App\Services\Payments\PaymentManager::enabled()));
        }
        $stored = Settings::get('payments.enabled');
        $codes = array_values(is_array($stored) ? $stored : (array) Config::get('payments.enabled', []));
        $gateways = (array) Config::get('payments.gateways', []);
        $out = [];
        foreach ($codes as $code) {
            if (!is_string($code) || !isset($gateways[$code]) || isset($out[$code]) || !self::available($code, (string) $gateways[$code])) {
                continue;
            }
            $out[$code] = self::LABELS[$code] ?? ucwords(str_replace('_', ' ', $code));
        }
        return array_values($out);
    }

    /** Gateway availability: the cart & orders slice's class when it exists, else the old rules. */
    private static function available(string $code, string $class): bool
    {
        if (class_exists($class) && method_exists($class, 'isAvailable')) {
            try {
                $r = new \ReflectionMethod($class, 'isAvailable');
                return (bool) ($r->isStatic() ? $class::isAvailable() : (new $class())->isAvailable());
            } catch (\Throwable) {
                // fall through to the old rules
            }
        }
        return match ($code) {
            'cod' => true,
            'bank_transfer' => trim((string) Settings::get('bank.details', '')) !== '',
            'card' => self::cardReady(),
            default => false,
        };
    }

    private static function cardReady(): bool
    {
        $driver = Config::get('payments.card.driver');
        if (!is_string($driver) || $driver === '' || !class_exists($driver)) {
            return false;
        }
        foreach (['key', 'secret', 'webhook_secret'] as $k) {
            if (trim((string) Config::get('payments.card.' . $k)) === '') {
                return false;
            }
        }
        return true;
    }

    /** Laravel Collection::join($glue, $finalGlue). */
    public static function join(array $items, string $glue, string $final): string
    {
        $items = array_values($items);
        $n = count($items);
        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return (string) $items[0];
        }
        $last = array_pop($items);
        return implode($glue, $items) . $final . $last;
    }

    /**
     * The FAQ text before its {tokens} are filled in: the owner's own questions (Admin -> FAQ, setting faq.groups) when saved,
     * otherwise the built-in ones from app/Config/faq.php.
     *
     * @return array<string, list<array{q:string,a:string}>>
     */
    public static function faqSource(): array
    {
        $saved = Settings::get('faq.groups');
        if (is_array($saved) && $saved !== []) {
            $out = [];
            foreach ($saved as $g) {
                $title = trim((string) ($g['title'] ?? ''));
                foreach ((array) ($g['items'] ?? []) as $i) {
                    if (trim((string) ($i['q'] ?? '')) !== '') {
                        $out[$title !== '' ? $title : 'Questions'][] = ['q' => (string) $i['q'], 'a' => (string) ($i['a'] ?? '')];
                    }
                }
            }
            if ($out !== []) {
                return $out;
            }
        }
        return (array) Config::get('faq.groups', []);
    }

    /** @return array<string, list<array{q:string,a:string}>> */
    public static function faqGroups(): array
    {
        $payments = self::paymentLabels() ?: ['see the options at checkout'];
        $links = [
            '{payments}' => e(self::join($payments, ', ', ' and ')),
            '{shipping}' => '<a href="' . e(route('page.shipping')) . '">Shipping &amp; returns</a>',
            '{track}' => '<a href="' . e(route('track')) . '">Track your order</a>',
            '{contact}' => '<a href="' . e(route('contact')) . '">contact us</a>',
            '{login}' => '<a href="' . e(route('login')) . '">sign in</a>',
            '{register}' => '<a href="' . e(route('register')) . '">create an account</a>',
            '{forgot}' => '<a href="' . e(route('password.request')) . '">forgot password</a>',
            '{privacy}' => '<a href="' . e(url('/pages/privacy-policy')) . '">Privacy policy</a>',
        ];
        $phone = self::phone();
        $email = self::email();
        $links['{phone}'] = $phone !== '' ? 'You can also call us on <a href="tel:' . e(preg_replace('/[^\d+]/', '', $phone)) . '">' . e($phone) . '</a>.' : '';
        $links['{email}'] = $email !== '' ? 'Or email <a href="mailto:' . e($email) . '">' . e($email) . '</a>.' : '';

        $groups = [];
        foreach (self::faqSource() as $title => $items) {
            foreach ($items as $i) {
                $groups[$title][] = ['q' => $i['q'], 'a' => trim(strtr(e($i['a']), $links))];
            }
        }
        return $groups;
    }

    /** @return array{collections: list<array{category: array, subs: list<string>}>, paymentLine: string, phone: string, email: string} */
    public static function aboutData(): array
    {
        $db = Database::get();
        $roots = $db->all('SELECT * FROM categories WHERE is_active = 1 AND parent_id IS NULL AND deleted_at IS NULL ORDER BY sort_order');
        $collections = [];
        foreach ($roots as $c) {
            if (!self::hasProducts((int) $c['id'])) {
                continue;
            }
            $subs = [];
            foreach ($db->all('SELECT id, name FROM categories WHERE parent_id = ? AND is_active = 1 AND deleted_at IS NULL ORDER BY sort_order', [$c['id']]) as $k) {
                if (self::hasProducts((int) $k['id'])) {
                    $subs[] = (string) $k['name'];
                }
            }
            $collections[] = ['category' => $c, 'subs' => $subs];
        }
        $methods = self::paymentLabels();
        return [
            'collections' => $collections,
            'paymentLine' => $methods === [] ? 'Choose how you would like to pay at checkout.' : 'Pay by ' . self::join($methods, ', ', ' or ') . '. You always see the available options at checkout.',
            'phone' => self::phone(),
            'email' => self::email(),
        ];
    }

    /** An active product in this category or any of its descendants (Category::descendantIds()). */
    private static function hasProducts(int $categoryId): bool
    {
        $db = Database::get();
        $ids = [$categoryId];
        $frontier = [$categoryId];
        while ($frontier) {
            $marks = implode(',', array_fill(0, count($frontier), '?'));
            $frontier = array_map('intval', $db->column("SELECT id FROM categories WHERE parent_id IN ({$marks}) AND deleted_at IS NULL", $frontier));
            $frontier = array_values(array_diff($frontier, $ids));
            $ids = array_merge($ids, $frontier);
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        return (bool) $db->value("SELECT 1 FROM products WHERE is_active = 1 AND deleted_at IS NULL AND category_id IN ({$marks}) LIMIT 1", $ids);
    }

    /** @return list<array<string,mixed>> */
    public static function shippingMethods(): array
    {
        if (class_exists(ShippingService::class) && method_exists(ShippingService::class, 'methods')) {
            try {
                $r = new \ReflectionMethod(ShippingService::class, 'methods');
                $m = $r->isStatic() ? ShippingService::methods() : (new ShippingService())->methods();
                if (is_array($m)) {
                    return array_values($m);
                }
            } catch (\Throwable) {
                // fall back to the table
            }
        }
        return Database::get()->all('SELECT * FROM shipping_methods WHERE is_active = 1 ORDER BY sort_order');
    }
}
