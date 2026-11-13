<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\HtmlSanitizer;

final class PageController extends ResourceController
{
    protected function config(): array
    {
        return [
            'table' => 'pages', 'model' => 'Page',
            'label' => 'Page', 'plural' => 'Pages', 'route' => 'admin.pages',
            'search' => ['title', 'slug'], 'order' => ['sort_order', 'asc'],
            'defaults' => ['is_published' => false, 'show_in_footer' => false, 'sort_order' => 0],
            'columns' => [['title', 'Title'], ['slug', 'URL', 'text'], ['is_published', 'Published', 'bool'], ['show_in_footer', 'In footer', 'bool']],
            'fields' => [
                ['name' => 'title', 'label' => 'Title'],
                ['name' => 'slug', 'label' => 'URL slug', 'help' => 'Leave blank to generate from the title. Page address: /pages/your-slug'],
                ['name' => 'body', 'label' => 'Content', 'help' => 'Use the buttons above the box for bold, headings, lists and links. Leave it empty on the FAQ and About us pages to show the built-in version.', 'type' => 'textarea', 'rows' => 14, 'rich' => true],
                ['name' => 'meta_title', 'label' => 'SEO title'],
                ['name' => 'meta_description', 'label' => 'SEO description', 'type' => 'textarea', 'rows' => 2],
                ['name' => 'sort_order', 'label' => 'Footer order', 'type' => 'number'],
                ['name' => 'is_published', 'label' => 'Published', 'type' => 'checkbox'],
                ['name' => 'show_in_footer', 'label' => 'Link from footer', 'type' => 'checkbox'],
            ],
            'rules' => static fn (?array $m): array => [
                'title' => 'required|string|max:160',
                'slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:pages,slug,' . ($m['id'] ?? 'NULL')],
                'body' => 'nullable|string|max:100000',
                'meta_title' => 'nullable|string|max:160',
                'meta_description' => 'nullable|string|max:300',
                'sort_order' => 'nullable|integer|min:0|max:9999',
                'is_published' => 'nullable|boolean',
                'show_in_footer' => 'nullable|boolean',
            ],
        ];
    }

    protected function beforeSave(array &$data, ?array $model): void
    {
        $data['body'] = isset($data['body']) ? HtmlSanitizer::clean((string) $data['body']) : null;
        $data['slug'] = $data['slug'] ?? $this->uniqueSlug('pages', (string) $data['title'], 'page', (int) ($model['id'] ?? 0));
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_sample'] = 0;
    }
}
