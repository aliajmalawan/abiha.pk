<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Core\Database;

/**
 * Factories for tests, against the ABIHA schema. Every method inserts real rows and returns the row as an array.
 * The default password of Fx::user() is "Password123".
 */
final class Fx
{
    public const PASSWORD = 'Password123';

    /** A user with a role slug (customer, admin, manager, staff, product_manager, inventory_manager, content_manager). */
    public static function user(string $role = 'customer', array $over = []): array
    {
        $db = Database::get();
        $roleId = $db->value('SELECT id FROM roles WHERE slug = ?', [$role]);
        if ($roleId === null) {
            throw new RuntimeException("Unknown role {$role}");
        }
        $row = $over + [
            'name' => 'Test ' . $role,
            'email' => $role . '-' . bin2hex(random_bytes(4)) . '@example.test',
            'phone' => '03001234567',
            'password' => self::hash(),
            'role_id' => $roleId,
            'is_active' => 1,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $id = self::insert('users', $row);
        return $db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** bcrypt of PASSWORD, computed once (cost 12 is slow on purpose). */
    public static function hash(): string
    {
        static $h = null;
        return $h ??= Auth::hashPassword(self::PASSWORD);
    }

    public static function category(array $over = []): array
    {
        $n = bin2hex(random_bytes(3));
        $id = self::insert('categories', $over + [
            'name' => 'Category ' . $n, 'slug' => 'category-' . $n, 'is_active' => 1, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Database::get()->one('SELECT * FROM categories WHERE id = ?', [$id]);
    }

    /** Active simple product (no variants). Prices are whole rupees. */
    public static function product(array $over = []): array
    {
        $n = bin2hex(random_bytes(3));
        $id = self::insert('products', $over + [
            'name' => 'Product ' . $n, 'slug' => 'product-' . $n, 'sku' => 'P-' . strtoupper($n),
            'price' => 2500, 'stock' => 10, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return Database::get()->one('SELECT * FROM products WHERE id = ?', [$id]);
    }

    public static function variant(int $productId, array $over = []): array
    {
        $n = bin2hex(random_bytes(3));
        $id = self::insert('product_variants', $over + [
            'product_id' => $productId, 'sku' => 'V-' . strtoupper($n), 'stock' => 5, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Database::get()->exec('UPDATE products SET has_variants = 1 WHERE id = ?', [$productId]);
        return Database::get()->one('SELECT * FROM product_variants WHERE id = ?', [$id]);
    }

    public static function page(array $over = []): array
    {
        $n = bin2hex(random_bytes(3));
        $id = self::insert('pages', $over + [
            'title' => 'Page ' . $n, 'slug' => 'page-' . $n, 'body' => '<p>Text</p>', 'is_published' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Database::get()->one('SELECT * FROM pages WHERE id = ?', [$id]);
    }

    /** Generic insert: Fx::insert('banners', [...]) returns the new id. */
    public static function insert(string $table, array $row): int
    {
        $cols = implode(', ', array_map(static fn ($c) => '`' . $c . '`', array_keys($row)));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        return Database::get()->insert("INSERT INTO `{$table}` ({$cols}) VALUES ({$marks})", array_values($row));
    }

    /** A temporary image file (a $_FILES-like array) made with GD. $kind: jpg | png | webp. */
    public static function image(int $w = 400, int $h = 300, string $kind = 'jpg', ?callable $paint = null): array
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 160, 60));
        if ($paint) {
            $paint($img);
        }
        $file = BASE_PATH . '/tests/.tmp/upload-' . bin2hex(random_bytes(4)) . '.' . $kind;
        match ($kind) {
            'png' => imagepng($img, $file),
            'webp' => imagewebp($img, $file),
            default => imagejpeg($img, $file, 90),
        };
        return ['name' => 'photo.' . $kind, 'type' => 'image/' . $kind, 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)];
    }
}
