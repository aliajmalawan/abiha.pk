<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** The append-only history of every payment event (attempts, provider notifications, transfer reports, staff decisions, refunds). */
class PaymentTransactionController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate([
            'provider' => ['nullable', 'in:cod,bank_transfer,card'],
            'status' => ['nullable', 'in:pending,paid,failed,refunded'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $like = isset($f['q']) ? '%'.addcslashes($f['q'], '%_\\').'%' : null;

        $rows = PaymentTransaction::query()->with(['order:id,number', 'user:id,name'])
            ->when($f['provider'] ?? null, fn ($q, $v) => $q->where('provider', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', $like)->orWhereHas('order', fn ($o) => $o->where('number', 'like', $like))))
            ->latest('id')->paginate(config('abiha.pagination.admin'))->withQueryString();

        return view('admin.payment-history', [
            'rows' => $rows, 'filters' => $f,
            'totals' => PaymentTransaction::query()->selectRaw('status, count(*) as n, sum(amount) as total')->groupBy('status')->get()->keyBy('status'),
        ]);
    }
}
