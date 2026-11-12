<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;
use App\Services\Admin\Pager;
use App\Support\CatalogCache;

final class ReviewController extends AdminController
{
    public function index(Request $request): Response
    {
        $f = $this->validate($request->query, ['status' => 'nullable|in:pending,approved', 'q' => 'nullable|string|max:100']);
        $status = $f['status'] ?? 'pending';
        $where = [$status === 'pending' ? 'r.is_approved = 0' : 'r.is_approved = 1'];
        $params = [];
        if (!empty($f['q'])) {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $where[] = '(r.body LIKE ? OR r.name LIKE ?)';
            $params = [$like, $like];
        }
        $reviews = Pager::query($request,
            'SELECT r.*, p.name AS product_name, p.slug AS product_slug FROM reviews r LEFT JOIN products p ON p.id = r.product_id AND p.deleted_at IS NULL
             WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id DESC', $params, (int) cfg('abiha.pagination.admin', 20));
        return $this->adminView('admin/reviews/index', ['reviews' => $reviews, 'status' => $status, 'q' => $f['q'] ?? null], 'Reviews');
    }

    public function approve(Request $request): Response
    {
        $review = $this->find((int) $request->params['id']);
        Database::get()->exec('UPDATE reviews SET is_approved = 1, is_sample = 0, updated_at = ? WHERE id = ?', [now(), (int) $review['id']]);
        ActivityLogger::log('review.approved', 'Review', (int) $review['id']);
        CatalogCache::bump();
        return $this->back(route('admin.reviews.index'), 'Review published.');
    }

    public function unapprove(Request $request): Response
    {
        $review = $this->find((int) $request->params['id']);
        Database::get()->exec('UPDATE reviews SET is_approved = 0, updated_at = ? WHERE id = ?', [now(), (int) $review['id']]);
        ActivityLogger::log('review.unpublished', 'Review', (int) $review['id']);
        CatalogCache::bump();
        return $this->back(route('admin.reviews.index'), 'Review hidden.');
    }

    public function destroy(Request $request): Response
    {
        $review = $this->find((int) $request->params['id']);
        ActivityLogger::log('review.deleted', 'Review', (int) $review['id'], ['product_id' => (int) $review['product_id']]);
        Database::get()->exec('DELETE FROM reviews WHERE id = ?', [(int) $review['id']]);
        CatalogCache::bump();
        return $this->back(route('admin.reviews.index'), 'Review deleted.');
    }

    private function find(int $id): array
    {
        $r = Database::get()->one('SELECT * FROM reviews WHERE id = ?', [$id]);
        if ($r === null) {
            throw new HttpException(404);
        }
        return $r;
    }
}
