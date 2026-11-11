<?php

namespace App\Http\Controllers\Admin;

use App\Models\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PageController extends ResourceController
{
    protected function config(): array
    {
        return [
            'model' => Page::class, 'label' => 'Page', 'plural' => 'Pages', 'route' => 'admin.pages',
            'search' => ['title', 'slug'], 'order' => ['sort_order', 'asc'],
            'defaults' => ['is_published' => false, 'show_in_footer' => false, 'sort_order' => 0],
            'columns' => [['title', 'Title'], ['slug', 'URL', 'text'], ['is_published', 'Published', 'bool'], ['show_in_footer', 'In footer', 'bool']],
            'fields' => [
                ['name' => 'title', 'label' => 'Title'],
                ['name' => 'slug', 'label' => 'URL slug', 'help' => 'Leave blank to generate from the title. Page address: /pages/your-slug'],
                ['name' => 'body', 'label' => 'Content (HTML allowed: p, h2-h4, ul, ol, li, strong, em, a, br, blockquote)', 'type' => 'textarea', 'rows' => 14],
                ['name' => 'meta_title', 'label' => 'SEO title'],
                ['name' => 'meta_description', 'label' => 'SEO description', 'type' => 'textarea', 'rows' => 2],
                ['name' => 'sort_order', 'label' => 'Footer order', 'type' => 'number'],
                ['name' => 'is_published', 'label' => 'Published', 'type' => 'checkbox'],
                ['name' => 'show_in_footer', 'label' => 'Link from footer', 'type' => 'checkbox'],
            ],
            'rules' => fn (?Model $m) => [
                'title' => ['required', 'string', 'max:160'],
                'slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('pages', 'slug')->ignore($m?->id)],
                'body' => ['nullable', 'string', 'max:100000'],
                'meta_title' => ['nullable', 'string', 'max:160'],
                'meta_description' => ['nullable', 'string', 'max:300'],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
                'is_published' => ['nullable', 'boolean'],
                'show_in_footer' => ['nullable', 'boolean'],
            ],
        ];
    }

    protected function beforeSave(array &$data, Model $model): void
    {
        $data['body'] = isset($data['body']) ? \App\Support\HtmlSanitizer::clean($data['body']) : null;
        $data['slug'] = $data['slug'] ?? $this->uniqueSlug($data['title'], $model);
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_sample'] = false;
    }

    private function uniqueSlug(string $title, Model $model): string
    {
        $base = Str::slug($title) ?: 'page';
        $slug = $base;
        for ($i = 2; Page::query()->where('slug', $slug)->where('id', '!=', $model->id ?? 0)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
