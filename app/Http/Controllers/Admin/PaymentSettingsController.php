<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\Payments\OnlinePaymentGateway;
use App\Services\Payments\PaymentManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Which payment methods customers see, plus COD and bank-transfer configuration. */
class PaymentSettingsController extends Controller
{
    public function edit(PaymentManager $payments): View
    {
        $all = $payments->all();

        return view('admin.payments', [
            'gateways' => $all,
            'enabled' => $payments->enabledCodes(),
            'codNote' => Setting::get('cod.note'),
            'bankDetails' => Setting::get('bank.details'),
            'online' => $all->filter(fn ($g) => $g instanceof OnlinePaymentGateway),
        ]);
    }

    public function update(Request $request, PaymentManager $payments): RedirectResponse
    {
        $codes = $payments->all()->keys()->all();
        $data = $request->validate([
            'enabled' => ['nullable', 'array'],
            'enabled.*' => ['string', 'in:'.implode(',', $codes)],
            'cod_note' => ['nullable', 'string', 'max:300'],
            'bank_details' => ['nullable', 'string', 'max:1000'],
        ]);
        $enabled = array_values($data['enabled'] ?? []);

        // Customers must never be sent to a bank transfer with no account to pay into.
        if (in_array('bank_transfer', $enabled, true) && trim((string) ($data['bank_details'] ?? '')) === '') {
            return back()->withInput()->withErrors(['bank_details' => 'Enter your bank account details before enabling Bank Transfer.']);
        }

        // A provider that is not configured cannot be switched on (it would only show customers a dead option).
        foreach ($enabled as $code) {
            if (! $payments->all()->get($code)?->isAvailable() && $code !== 'bank_transfer') {
                return back()->withInput()->withErrors(['enabled' => $payments->all()->get($code)->label().' is not set up yet, so it cannot be enabled. See the Card payments section.']);
            }
        }

        Setting::put('payments.enabled', $enabled);
        Setting::put('cod.note', ($data['cod_note'] ?? '') ?: null);
        Setting::put('bank.details', ($data['bank_details'] ?? '') ?: null);
        ActivityLogger::log('payments.settings_updated', null, ['enabled' => $enabled]);

        return back()->with('status', $enabled ? 'Payment settings saved.' : 'Saved. No payment method is enabled, so customers cannot check out until you enable one.');
    }
}
