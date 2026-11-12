<?php

declare(strict_types=1);

namespace App\Services\Admin;

/**
 * View helpers for the admin templates.
 *
 *   Form::field('sku', 'SKU', $value, ['required' => true, 'help' => '...', 'attrs' => ['x-model' => 'sku', '@input' => '...']])
 *       the port of <x-field> (label, input, error or help text; old input wins over $value)
 *   Form::js($value)   JSON for an Alpine attribute (Blade's @js), safe inside a double-quoted attribute
 */
final class Form
{
    /** @param array{type?:string,required?:bool,help?:?string,autocomplete?:?string,class?:string,attrs?:array<string,string|int>} $o */
    public static function field(string $name, string $label, mixed $value = null, array $o = []): string
    {
        $type = $o['type'] ?? 'text';
        $err = error($name);
        $help = $o['help'] ?? null;
        $shown = old($name, $value);
        $shown = $type === 'password' || !is_scalar($shown) ? '' : $shown;
        $extra = '';
        foreach ($o['attrs'] ?? [] as $k => $v) {
            $extra .= ' ' . preg_replace('/[^A-Za-z0-9_:.@-]/', '', (string) $k) . '="' . e($v) . '"';
        }
        $cls = trim('field ' . ($o['class'] ?? ''));
        return '<div class="' . e($cls) . '">'
            . '<label class="label" for="f-' . e($name) . '">' . e($label) . (!empty($o['required']) ? ' <span class="req" aria-hidden="true">*</span>' : '') . '</label>'
            . '<input class="input" id="f-' . e($name) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($shown) . '"'
            . (!empty($o['required']) ? ' required' : '')
            . (!empty($o['autocomplete']) ? ' autocomplete="' . e($o['autocomplete']) . '"' : '')
            . ($err !== null ? ' aria-invalid="true" aria-describedby="e-' . e($name) . '"' : ($help ? ' aria-describedby="h-' . e($name) . '"' : ''))
            . $extra . '>'
            . ($err !== null ? '<span class="error-text" id="e-' . e($name) . '">' . e($err) . '</span>' : ($help ? '<span class="help" id="h-' . e($name) . '">' . e($help) . '</span>' : ''))
            . '</div>';
    }

    public static function js(mixed $value): string
    {
        return e(json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Laravel's Str::plural for the few English words the admin uses. */
    public static function plural(string $word, int $n): string
    {
        return $n === 1 ? $word : $word . 's';
    }
}
