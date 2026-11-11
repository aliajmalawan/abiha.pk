<?php

namespace App\View\Components;

use Illuminate\Support\Facades\Cache;
use Illuminate\View\Component;

/**
 * Inline Lucide icon. SVGs live in resources/svg/icons (copied from lucide-static),
 * so there is no icon-font, no runtime JS and no extra HTTP requests.
 */
class Icon extends Component
{
    public function __construct(public string $name, public int $size = 20, public float $stroke = 1.75)
    {
    }

    public function render(): \Closure
    {
        return function (array $data) {
            $svg = Cache::store('array')->rememberForever('icon.'.$this->name, function () {
                $path = resource_path('svg/icons/'.preg_replace('/[^a-z0-9-]/', '', $this->name).'.svg');

                return is_file($path) ? file_get_contents($path) : '';
            });

            if ($svg === '') {
                return '';
            }

            $svg = preg_replace('/<!--.*?-->/s', '', $svg);

            // Rewrite ONLY the opening <svg …> tag. Child shapes (<rect width= height=>, <circle r=>) keep their
            // own dimensions; stripping those would collapse icons such as layout-dashboard and mail.
            return trim((string) preg_replace_callback('/<svg\b[^>]*>/s', function (array $m) use ($data) {
                $open = preg_replace('/\s(class|width|height|stroke-width)="[^"]*"/', '', $m[0]);

                return preg_replace(
                    '/<svg/',
                    '<svg width="'.$this->size.'" height="'.$this->size.'" stroke-width="'.$this->stroke.'" aria-hidden="true" focusable="false" '.$data['attributes']->toHtml(),
                    $open,
                    1
                );
            }, $svg, 1));
        };
    }
}
