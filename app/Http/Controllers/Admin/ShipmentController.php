<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Every shipment record (courier, tracking reference, status). Details are edited on the order itself. */
class ShipmentController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:pending,shipped,delivered,returned']]);
        $like = isset($f['q']) ? '%'.addcslashes($f['q'], '%_\\').'%' : null;

        $shipments = Shipment::query()->with('order:id,number,email,status')
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('tracking_number', 'like', $like)->orWhere('courier', 'like', $like)
                ->orWhereHas('order', fn ($o) => $o->where('number', 'like', $like)->orWhere('email', 'like', $like))))
            ->latest('id')->paginate(config('abiha.pagination.admin'))->withQueryString();

        return view('admin.shipments.index', ['shipments' => $shipments, 'filters' => $f]);
    }
}
