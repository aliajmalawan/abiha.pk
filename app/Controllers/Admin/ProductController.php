<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Admin\Pager;
use App\Services\Admin\ProductService;
use App\Services\Admin\Rules;

/** Admin products: list with filters, the product form (details, specifications, pricing, variants, photos, SEO), delete. */
final class ProductController extends AdminController
{
    public function index(Request $request): Response
    {
        $filters = $this->validate($request->query, [
            'q' => 'nullable|string|max:100', 'category' => 'nullable|integer', 'status' => 'nullable|in:active,inactive,low,out',
        ]);
        [$search, $params] = ProductService::searchSql($filters['q'] ?? null);
        $where = ['p.deleted_at IS NULL', $search];
        if (!empty($filters['category'])) {
            $exists = Database::get()->value('SELECT id FROM categories WHERE id = ? AND deleted_at IS NULL', [$filters['category']]);
            $ids = $exists ? ProductService::descendantIds((int) $filters['category']) : [(int) $filters['category']];
            $where[] = 'p.category_id IN ' . Database::in($ids);
            $params = [...$params, ...$ids];
        }
        $where[] = match ($filters['status'] ?? null) {
            'active' => 'p.is_active = 1',
            'inactive' => 'p.is_active = 0',
            'out' => ProductService::OUT_OF_STOCK_SQL,
            'low' => ProductService::LOW_STOCK_SQL,
            default => '1 = 1',
        };
        $sql = 'SELECT p.*, (SELECT c.name FROM categories c WHERE c.id = p.category_id AND c.deleted_at IS NULL) AS category_name,
                   (SELECT i.path FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS image_path,
                   (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) AS variant_count,
                   (SELECT COALESCE(SUM(v.stock), 0) FROM product_variants v WHERE v.product_id = p.id) AS variant_stock
                FROM products p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id DESC';
        return $this->adminView('admin/products/index', [
            'products' => Pager::query($request, $sql, $params, (int) cfg('abiha.pagination.admin', 20)),
            'categories' => Database::get()->all('SELECT id, name FROM categories WHERE deleted_at IS NULL ORDER BY name'),
            'filters' => $filters,
        ], 'Products');
    }

    public function create(Request $request): Response
    {
        return $this->adminView('admin/products/form', $this->formData(null), 'New product');
    }

    public function store(Request $request): Response
    {
        [$data, $variants, $images] = $this->validated($request, null);
        $product = ProductService::save(null, $data, $variants, $images, [], Auth::id());
        return $this->redirect(route('admin.products.edit', ['id' => $product['id']]), 'Product created.');
    }

    public function edit(Request $request): Response
    {
        return $this->adminView('admin/products/form', $this->formData($this->find((int) $request->params['id'])), 'Edit product');
    }

    public function update(Request $request): Response
    {
        $product = $this->find((int) $request->params['id']);
        [$data, $variants, $images] = $this->validated($request, $product);
        ProductService::save($product, $data, $variants, $images, array_map('intval', (array) ($data['remove_images'] ?? [])), Auth::id(), array_map('intval', (array) ($data['image_order'] ?? [])));
        return $this->back(route('admin.products.edit', ['id' => $product['id']]), 'Product saved.');
    }

    public function destroy(Request $request): Response
    {
        ProductService::delete($this->find((int) $request->params['id']));
        return $this->redirect(route('admin.products.index'), 'Product deleted. Existing orders keep their details.');
    }

    private function find(int $id): array
    {
        $p = Database::get()->one('SELECT * FROM products WHERE id = ? AND deleted_at IS NULL', [$id]);
        if ($p === null) {
            throw new HttpException(404);
        }
        return $p;
    }

    /**
     * Port of the old ProductRequest (rules + after() checks). Returns [validated data, variant rows, new image files].
     *
     * @return array{0:array,1:list<array>,2:list<array>}
     */
    private function validated(Request $request, ?array $product): array
    {
        $in = $request->post;
        $hasVariants = $request->bool('has_variants');
        $id = $product['id'] ?? 'NULL';
        $rules = [
            'name' => 'required|string|max:190',
            'slug' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:products,slug,' . $id],
            'sku' => ['required', 'string', 'max:80', 'unique:products,sku,' . $id],
            'category_id' => 'nullable|integer|exists:categories,id',
            'short_description' => 'nullable|string|max:500',
            'description' => 'nullable|string|max:50000',
            'specifications' => 'nullable|array|max:25',
            'specifications.*.label' => 'nullable|string|max:60',
            'specifications.*.value' => 'nullable|string|max:300',
            'price' => 'required|integer|min:0|max:100000000',
            'compare_price' => 'nullable|integer|max:100000000',
            'stock' => ($hasVariants ? 'nullable' : 'required') . '|integer|min:0|max:1000000',
            'low_stock_threshold' => 'nullable|integer|min:0|max:100000',
            'badge' => 'nullable|string|max:40',
            'meta_title' => 'nullable|string|max:190',
            'meta_description' => 'nullable|string|max:300',
            'has_variants' => 'nullable|boolean',
            'is_active' => 'nullable|boolean', 'is_featured' => 'nullable|boolean', 'is_new' => 'nullable|boolean',
            'is_bestseller' => 'nullable|boolean', 'is_trending' => 'nullable|boolean',
            'image_order' => 'nullable|array|max:50', 'image_order.*' => 'integer',
            'remove_images' => 'nullable|array', 'remove_images.*' => 'integer',
            'image_alts' => 'nullable|array', 'image_alts.*' => 'nullable|string|max:160',
            'variants' => ($hasVariants ? 'required' : 'nullable') . '|array|max:100',
            'variants.*.id' => 'nullable|integer',
            'variants.*.sku' => 'required|string|max:80',
            'variants.*.price' => 'nullable|integer|min:0|max:100000000',
            'variants.*.compare_price' => 'nullable|integer|min:0|max:100000000',
            'variants.*.stock' => 'required|integer|min:0|max:1000000',
            'variants.*.is_active' => 'nullable|boolean',
            'variants.*.options' => 'required|array|min:1',
            'variants.*.options.*' => 'integer|exists:attribute_values,id',
        ];
        $expanded = Rules::expand($in, $rules);
        $v = Validator::make($in, $expanded);
        $errors = $v->errors();

        // compare_price: gt:price (Laravel: "must be greater than <price>")
        if (!isset($errors['compare_price']) && isset($in['compare_price']) && is_scalar($in['compare_price']) && trim((string) $in['compare_price']) !== ''
            && is_scalar($in['price'] ?? null) && is_numeric($in['price']) && is_numeric($in['compare_price']) && (int) $in['compare_price'] <= (int) $in['price']) {
            $errors['compare_price'] = 'The compare price field must be greater than ' . (int) $in['price'] . '.';
        }
        // variants.*.sku: distinct
        $rows = is_array($in['variants'] ?? null) ? $in['variants'] : [];
        $skus = [];
        foreach ($rows as $i => $row) {
            if (is_array($row) && is_scalar($row['sku'] ?? null) && trim((string) $row['sku']) !== '') {
                $skus[$i] = trim((string) $row['sku']);
            }
        }
        $counts = array_count_values($skus);
        foreach ($skus as $i => $sku) {
            if ($counts[$sku] > 1 && !isset($errors["variants.$i.sku"])) {
                $errors["variants.$i.sku"] = "The variants.$i.sku field has a duplicate value.";
            }
        }

        // images: nullable|array|max:12, each image|mimes:jpg,jpeg,png,webp|max:4096|dimensions:max 8000
        $files = $request->fileList('images');
        if (count($files) > 12) {
            $errors['images'] = 'The images field must not have more than 12 items.';
        }
        foreach ($files as $i => $file) {
            if (($msg = Rules::photo($file, "images.$i")) !== null) {
                $errors["images.$i"] ??= $msg;
            }
        }

        // after(): at most 12 photos in total; variant SKUs unique across the catalogue; no repeated option combination
        $remove = array_map('intval', array_filter((array) ($in['remove_images'] ?? []), 'is_scalar'));
        $kept = $product ? (int) Database::get()->value('SELECT COUNT(*) FROM product_images WHERE product_id = ?' . ($remove ? ' AND id NOT IN ' . Database::in($remove) : ''), [(int) $product['id'], ...$remove]) : 0;
        if ($kept + count($files) > 12) {
            $errors['images'] ??= 'A product can have at most 12 photos. Remove some first.';
        }
        if ($hasVariants) {
            $seen = [];
            foreach ($rows as $i => $row) {
                $row = is_array($row) ? $row : [];
                $sku = is_scalar($row['sku'] ?? null) ? trim((string) $row['sku']) : '';
                $vid = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
                $clash = (int) Database::get()->value('SELECT COUNT(*) FROM product_variants WHERE sku = ? AND id <> ?', [$sku, $vid]) > 0
                    || (int) Database::get()->value('SELECT COUNT(*) FROM products WHERE sku = ?', [$sku]) > 0;
                if ($clash) {
                    $errors["variants.$i.sku"] ??= 'This SKU is already in use.';
                }
                $opts = array_map('strval', array_filter((array) ($row['options'] ?? []), 'is_scalar'));
                sort($opts);
                $combo = implode('-', $opts);
                if (isset($seen[$combo])) {
                    $errors["variants.$i.options"] ??= 'Another variant already has this combination of options.';
                }
                $seen[$combo] = true;
            }
        }
        // optional product video: an uploaded MP4/WebM (checked by its real signature) or a YouTube / Vimeo / .mp4 link
        $videoFile = $request->file('video_file');
        foreach (\App\Support\Video::check($videoFile, is_scalar($in['video_url'] ?? null) ? (string) $in['video_url'] : '') ?? [] as $field => $msg) {
            $errors[$field] ??= $msg;
        }
        Rules::throwIf($errors);

        $data = array_filter($v->validated(), static fn ($k) => !str_contains((string) $k, '.'), ARRAY_FILTER_USE_KEY);
        $data['compare_price'] = isset($data['compare_price']) ? (int) $data['compare_price'] : null;
        $data['specifications'] = is_array($in['specifications'] ?? null) ? $in['specifications'] : [];
        $data['image_alts'] = is_array($in['image_alts'] ?? null) ? $in['image_alts'] : [];
        $data['image_order'] = is_array($in['image_order'] ?? null) ? $in['image_order'] : [];
        $data['remove_images'] = $remove;
        $data['video_file'] = $videoFile;
        $data['video_url'] = is_scalar($in['video_url'] ?? null) ? trim((string) $in['video_url']) : null;
        $data['remove_video'] = !empty($in['remove_video']);
        $variants = [];
        foreach ($rows as $row) {
            $variants[] = [
                'id' => $row['id'] ?? null, 'sku' => trim((string) $row['sku']), 'price' => $row['price'] ?? null, 'compare_price' => $row['compare_price'] ?? null,
                'stock' => (int) $row['stock'], 'is_active' => $row['is_active'] ?? null, 'options' => (array) $row['options'],
            ];
        }
        return [$data, $variants, $files];
    }

    private function formData(?array $product): array
    {
        $db = Database::get();
        $attributes = $db->all('SELECT id, name FROM attributes ORDER BY sort_order, id');
        $values = $db->all('SELECT id, attribute_id, value FROM attribute_values ORDER BY sort_order, id');
        $attrData = [];
        foreach ($attributes as $a) {
            $attrData[] = ['id' => (int) $a['id'], 'name' => $a['name'], 'values' => array_values(array_map(
                static fn ($v) => ['id' => (int) $v['id'], 'value' => $v['value']],
                array_filter($values, static fn ($v) => (int) $v['attribute_id'] === (int) $a['id'])
            ))];
        }
        $images = [];
        $variantRows = [];
        if ($product) {
            $images = $db->all('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id', [(int) $product['id']]);
            $variants = $db->all('SELECT * FROM product_variants WHERE product_id = ? ORDER BY sort_order, id', [(int) $product['id']]);
            foreach ($variants as $v) {
                $variantRows[] = [
                    'id' => (int) $v['id'], 'sku' => $v['sku'], 'price' => $v['price'] === null ? null : (int) $v['price'],
                    'compare_price' => $v['compare_price'] === null ? null : (int) $v['compare_price'], 'stock' => (int) $v['stock'],
                    'is_active' => (bool) $v['is_active'],
                    'options' => array_map('intval', $db->column('SELECT attribute_value_id FROM attribute_value_product_variant WHERE product_variant_id = ?', [(int) $v['id']])),
                ];
            }
        }
        $oldVariants = old('variants', null);
        return [
            'product' => $product,
            'categories' => $db->all('SELECT id, name, parent_id FROM categories WHERE deleted_at IS NULL ORDER BY name'),
            'attrData' => $attrData,
            'images' => $images,
            'variantRows' => is_array($oldVariants) ? array_values($oldVariants) : $variantRows,
        ];
    }
}
