<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\View;

/**
 * Length-aware pagination (port of Laravel's LengthAwarePaginator + UrlWindow, rendered with the old
 * resources/views/vendor/pagination/abiha.blade.php = app/Views/components/pagination.php).
 *
 *   $page = Paginator::currentPage($request->query);              // ?page=N, anything invalid = 1 (like Laravel)
 *   $p = new Paginator($items, $total, $perPage, $page);
 *   $p->withQueryString($request->query)                         // keep the filters in the page links
 *     ->path(url('/shop'))->pageName('page')->fragment('reviews');
 *   foreach ($p->items as $row) ...;  $p->total; $p->isEmpty(); echo $p->links();
 */
final class Paginator
{
    public int $lastPage;
    private string $path = '';
    private string $pageName = 'page';
    private array $query = [];
    private ?string $fragment = null;

    public function __construct(public array $items, public int $total, public int $perPage, public int $currentPage)
    {
        $this->perPage = max(1, $perPage);
        $this->currentPage = max(1, $currentPage);
        $this->lastPage = max((int) ceil($total / $this->perPage), 1);
        $req = View::shared('request');
        $this->path = $req instanceof \App\Core\Request ? url($req->path) : url('/');
    }

    /** Laravel's Paginator::resolveCurrentPage: a valid integer >= 1, else 1. */
    public static function currentPage(array $query, string $name = 'page'): int
    {
        $v = $query[$name] ?? null;
        return is_scalar($v) && filter_var($v, FILTER_VALIDATE_INT) !== false && (int) $v >= 1 ? (int) $v : 1;
    }

    public function path(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    public function pageName(string $name): self
    {
        $this->pageName = $name;
        return $this;
    }

    public function fragment(?string $f): self
    {
        $this->fragment = $f;
        return $this;
    }

    public function withQueryString(array $query): self
    {
        unset($query[$this->pageName]);
        $this->query = $query;
        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function firstItem(): int
    {
        return ($this->currentPage - 1) * $this->perPage + 1;
    }

    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    public function hasPages(): bool
    {
        return $this->currentPage != 1 || $this->hasMorePages();
    }

    public function url(int $page): string
    {
        $page = max(1, $page);
        $params = array_merge($this->query, [$this->pageName => $page]);
        return $this->path . (str_contains($this->path, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986)
            . ($this->fragment ? '#' . $this->fragment : '');
    }

    public function previousPageUrl(): ?string
    {
        return $this->currentPage > 1 ? $this->url($this->currentPage - 1) : null;
    }

    public function nextPageUrl(): ?string
    {
        return $this->hasMorePages() ? $this->url($this->currentPage + 1) : null;
    }

    /** Laravel's elements(): page => url arrays separated by '...' strings (UrlWindow with onEachSide = 3). */
    public function elements(): array
    {
        $each = 3;
        $last = $this->lastPage;
        $cur = $this->currentPage;
        $range = fn (int $a, int $b): array => array_combine(range($a, $b), array_map(fn ($p) => $this->url($p), range($a, $b)));
        if (!$this->hasPages()) {
            return [];
        }
        $first = $slider = $end = null;
        if ($last < $each * 2 + 8) {
            $first = $range(1, $last);
        } else {
            $window = $each + 4;
            if ($cur <= $window) {
                $first = $range(1, $window + $each);
                $end = $range($last - 1, $last);
            } elseif ($cur > $last - $window) {
                $first = $range(1, 2);
                $end = $range($last - ($window + ($each - 1)), $last);
            } else {
                $first = $range(1, 2);
                $slider = $range($cur - $each, $cur + $each);
                $end = $range($last - 1, $last);
            }
        }
        return array_values(array_filter([$first, is_array($slider) ? '...' : null, $slider, is_array($end) ? '...' : null, $end]));
    }

    /** The old abiha pagination markup ('' when there is only one page). */
    public function links(): string
    {
        return View::partial('components/pagination', ['paginator' => $this]);
    }
}
