<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;
use App\Services\Admin\Rules;
use App\Services\Payments\PaymentManager;
use App\Support\Settings;

/** Which payment methods customers see, plus COD and bank-transfer configuration (port of Admin\PaymentSettingsController). */
final class PaymentSettingsController extends AdminController
{
    public function edit(Request $request): Response
    {
        return $this->adminView('admin/payments', [
            'gateways' => PaymentManager::all(),
            'enabled' => PaymentManager::enabledCodes(),
            'codNote' => Settings::get('cod.note'),
            'bankDetails' => Settings::get('bank.details'),
        ], 'Payments');
    }

    public function update(Request $request): Response
    {
        $all = PaymentManager::all();
        $back = route('admin.payments.edit');
        $input = $request->post;
        Rules::throwIf(Rules::run($input, [
            'enabled' => ['nullable', 'array'],
            'enabled.*' => ['string', 'in:' . implode(',', array_keys($all))],
            'cod_note' => ['nullable', 'string', 'max:300'],
            'bank_details' => ['nullable', 'string', 'max:1000'],
        ]));
        $enabled = array_values(array_map('strval', (array) ($input['enabled'] ?? [])));
        $codNote = trim((string) ($input['cod_note'] ?? ''));
        $bank = trim((string) ($input['bank_details'] ?? ''));

        // Customers must never be sent to a bank transfer with no account to pay into.
        if (in_array('bank_transfer', $enabled, true) && $bank === '') {
            return $this->backWithErrors(['bank_details' => 'Enter your bank account details before enabling Bank Transfer.'], $back, true, $input);
        }
        // A provider that is not configured cannot be switched on (it would only show customers a dead option).
        foreach ($enabled as $code) {
            if ($code !== 'bank_transfer' && !$all[$code]->isAvailable()) {
                return $this->backWithErrors(['enabled' => $all[$code]->label() . ' is not set up yet, so it cannot be enabled. See the Card payments section.'], $back, true, $input);
            }
        }

        Settings::put('payments.enabled', $enabled);
        Settings::put('cod.note', $codNote !== '' ? $codNote : null);
        Settings::put('bank.details', $bank !== '' ? $bank : null);
        ActivityLogger::log('payments.settings_updated', null, null, ['enabled' => $enabled]);

        return $this->back($back, $enabled ? 'Payment settings saved.' : 'Saved. No payment method is enabled, so customers cannot check out until you enable one.');
    }
}
