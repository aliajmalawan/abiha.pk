<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plain-PHP templates in app/Views (".php" files). Templates must escape output with e(); raw HTML is only printed
 * for values that went through App\Support\HtmlSanitizer::clean() or were built by trusted helpers (icon(), logo()).
 *
 *   View::render('store/home', ['products' => $p]);              // page inside layouts/store
 *   View::render('admin/orders/index', $data, 'layouts/admin');  // page inside the admin layout
 *   View::partial('partials/flash', $data);                      // fragment, no layout
 *
 * Shared values (View::share) are visible in every template; per-call $data wins over shared values.
 */
final class View
{
    /** @var array<string,mixed> Shared with every template. */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function shared(string $key, mixed $default = null): mixed
    {
        return self::$shared[$key] ?? $default;
    }

    /** Tests: forget everything shared by a previous request. */
    public static function reset(): void
    {
        self::$shared = [];
    }

    public static function exists(string $template): bool
    {
        return is_file(APP_PATH . '/Views/' . $template . '.php');
    }

    public static function render(string $template, array $data = [], ?string $layout = 'layouts/store'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, ['content' => $content] + $data);
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = APP_PATH . '/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }
        $vars = $data + self::$shared;
        return (static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string) ob_get_clean();
        })($file, $vars);
    }
}
