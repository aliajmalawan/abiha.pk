<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Inventory\InsufficientStockException;
use App\Services\Inventory\InventoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use App\Support\Csv;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function index(Request $request): View
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:low,out']]);

        $products = Product::query()->with(['allVariants'])->search($f['q'] ?? null)
            ->when(($f['status'] ?? null) === 'out', fn ($q) => $q->outOfStock())
            ->when(($f['status'] ?? null) === 'low', fn ($q) => $q->lowStock())
            ->orderBy('name')->paginate(config('abiha.pagination.admin'))->withQueryString();

        return view('admin.inventory.index', ['products' => $products, 'filters' => $f]);
    }

    /** Add stock, remove stock, or set an exact level (stock-take) for one product or variant. */
    public function adjust(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'target' => ['required', 'in:product,variant'],
            'id' => ['required', 'integer'],
            'mode' => ['required', 'in:add,remove,set'],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reason' => ['required', 'in:restock,adjustment,return'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        $item = $data['target'] === 'variant' ? ProductVariant::query()->findOrFail($data['id']) : Product::query()->findOrFail($data['id']);
        if ($item instanceof Product && $item->has_variants) {
            return back()->withErrors(['inventory' => 'This product tracks stock per variant. Adjust a variant instead.']);
        }

        try {
            match ($data['mode']) {
                'add' => $this->inventory->adjust($item, $data['quantity'], $data['reason'], null, $request->user(), $data['note'] ?? null),
                'remove' => $this->inventory->adjust($item, -$data['quantity'], $data['reason'], null, $request->user(), $data['note'] ?? null),
                'set' => $this->inventory->setLevel($item, $data['quantity'], $request->user(), $data['note'] ?? null, $data['reason']),
            };
        } catch (InsufficientStockException $e) {
            return back()->withErrors(['inventory' => $e->getMessage()]);
        }

        return back()->with('status', 'Stock updated.');
    }

    /** CSV of current stock for every product and variant. */
    public function export(): StreamedResponse
    {
        $rows = (function () {
            foreach (Product::query()->with('allVariants')->orderBy('name')->lazyById(200) as $p) {
                if ($p->has_variants) {
                    foreach ($p->allVariants as $v) {
                        yield [$p->name, $v->sku, $v->label, $v->stock, $p->low_stock_threshold, $p->is_active ? 'yes' : 'no'];
                    }
                } else {
                    yield [$p->name, $p->sku, '', $p->stock, $p->low_stock_threshold, $p->is_active ? 'yes' : 'no'];
                }
            }
        })();

        return Csv::download('stock-'.now()->format('Ymd').'.csv', ['Product', 'SKU', 'Variant', 'On hand', 'Low-stock alert at', 'Visible'], $rows);
    }

    /** CSV of the whole stock ledger (inventory history). */
    public function exportLedger(): StreamedResponse
    {
        $rows = (function () {
            foreach (InventoryMovement::query()->with(['product', 'variant', 'user:id,name'])->lazyById(500) as $m) {
                yield [$m->created_at->format('Y-m-d H:i'), $m->product?->name, $m->variant?->sku, $m->change, $m->balance_after, $m->reason, $m->order_id, $m->user?->name, $m->note];
            }
        })();

        return Csv::download('inventory-history-'.now()->format('Ymd').'.csv', ['When', 'Product', 'Variant SKU', 'Change', 'Balance after', 'Reason', 'Order id', 'By', 'Note'], $rows);
    }

    public function ledger(Request $request): View
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'reason' => ['nullable', 'in:'.implode(',', InventoryService::REASONS)]]);

        $rows = InventoryMovement::query()->with(['product', 'variant', 'user:id,name'])
            ->when($f['reason'] ?? null, fn ($q, $r) => $q->where('reason', $r))
            ->when($f['q'] ?? null, fn ($q, $term) => $q->whereHas('product', fn ($p) => $p->withTrashed()->search($term)))
            ->latest('id')->paginate(30)->withQueryString();

        return view('admin.inventory.ledger', ['rows' => $rows, 'filters' => $f, 'reasons' => InventoryService::REASONS]);
    }
}
