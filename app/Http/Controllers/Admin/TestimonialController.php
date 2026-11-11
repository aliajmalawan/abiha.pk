<?php

namespace App\Http\Controllers\Admin;

use App\Models\Testimonial;
use Illuminate\Database\Eloquent\Model;

class TestimonialController extends ResourceController
{
    protected function config(): array
    {
        return [
            'model' => Testimonial::class, 'label' => 'Testimonial', 'plural' => 'Testimonials', 'route' => 'admin.testimonials',
            'search' => ['name', 'body'], 'order' => ['sort_order', 'asc'],
            'defaults' => ['rating' => 5, 'is_active' => true, 'sort_order' => 0],
            'columns' => [['name', 'Customer'], ['rating', 'Rating'], ['sort_order', 'Order'], ['is_active', 'Active', 'bool']],
            'fields' => [
                ['name' => 'name', 'label' => 'Customer name'],
                ['name' => 'location', 'label' => 'City / location'],
                ['name' => 'rating', 'label' => 'Rating (1-5)', 'type' => 'number'],
                ['name' => 'body', 'label' => 'Testimonial', 'type' => 'textarea', 'rows' => 4, 'help' => 'Only publish genuine customer feedback you have permission to use.'],
                ['name' => 'sort_order', 'label' => 'Display order', 'type' => 'number'],
                ['name' => 'is_active', 'label' => 'Show on the website', 'type' => 'checkbox'],
            ],
            'rules' => fn (?Model $m) => [
                'name' => ['required', 'string', 'max:120'], 'location' => ['nullable', 'string', 'max:120'],
                'rating' => ['required', 'integer', 'between:1,5'], 'body' => ['required', 'string', 'max:1000'],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'], 'is_active' => ['nullable', 'boolean'],
            ],
        ];
    }

    protected function beforeSave(array &$data, Model $model): void
    {
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_sample'] = false;
    }
}
