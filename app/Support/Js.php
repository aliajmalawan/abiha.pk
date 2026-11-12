<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Blade's @js() (Illuminate\Support\Js::from): a PHP value as a JavaScript expression that is safe inside an HTML
 * attribute. Arrays become JSON.parse('...') exactly like the old views, scalars plain JSON.
 *
 *   <div x-data="gallery(<?= Js::from($urls) ?>)">
 */
final class Js
{
    private const FLAGS = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;

    public static function from(mixed $data): string
    {
        $json = json_encode($data, self::FLAGS);
        if (is_string($data)) {
            return "'" . substr($json, 1, -1) . "'";
        }
        if (is_array($data) || is_object($data)) {
            return "JSON.parse('" . substr(json_encode($json, self::FLAGS), 1, -1) . "')";
        }
        return $json;
    }
}
