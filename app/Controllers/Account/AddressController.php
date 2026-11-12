<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/** Port of the old Account\AddressController + AddressRequest + AddressPolicy (owner only). */
final class AddressController extends AccountBase
{
    public function index(Request $request): Response
    {
        return $this->accountView('account/addresses', ['addresses' => self::addressesOf((int) $this->me()['id'])], 'Address book', 'Address book');
    }

    public function store(Request $request): Response
    {
        $uid = (int) $this->me()['id'];
        $data = $this->validated($request);
        $db = Database::get();
        $makeDefault = $request->bool('is_default') || !$db->value('SELECT 1 FROM addresses WHERE user_id = ? LIMIT 1', [$uid]);
        $db->transaction(static function (Database $db) use ($uid, $data, $makeDefault): void {
            if ($makeDefault) {
                $db->exec('UPDATE addresses SET is_default = 0, updated_at = ? WHERE user_id = ?', [now(), $uid]);
            }
            $db->insert(
                'INSERT INTO addresses (user_id, label, name, phone, province, city, address, postal_code, is_default, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$uid, $data['label'], $data['name'], $data['phone'], $data['province'], $data['city'], $data['address'], $data['postal_code'], $makeDefault ? 1 : 0, now(), now()]
            );
        });

        return $this->back(route('account.addresses'), 'Address saved.');
    }

    public function update(Request $request): Response
    {
        $address = $this->find($request);
        $data = $this->validated($request);
        $this->authorize($address);
        $uid = (int) $address['user_id'];
        $default = $request->bool('is_default');
        Database::get()->transaction(static function (Database $db) use ($address, $uid, $data, $default): void {
            if ($default) {
                $db->exec('UPDATE addresses SET is_default = 0, updated_at = ? WHERE user_id = ?', [now(), $uid]);
            }
            $db->exec(
                'UPDATE addresses SET label = ?, name = ?, phone = ?, province = ?, city = ?, address = ?, postal_code = ?, is_default = ?, updated_at = ? WHERE id = ?',
                [$data['label'], $data['name'], $data['phone'], $data['province'], $data['city'], $data['address'], $data['postal_code'], ($default || (int) $address['is_default'] === 1) ? 1 : 0, now(), $address['id']]
            );
        });

        return $this->back(route('account.addresses'), 'Address updated.');
    }

    public function destroy(Request $request): Response
    {
        $address = $this->find($request);
        $this->authorize($address);
        $db = Database::get();
        $db->exec('DELETE FROM addresses WHERE id = ?', [$address['id']]);
        if ((int) $address['is_default'] === 1 && ($next = self::addressesOf((int) $address['user_id'])[0] ?? null)) {
            $db->exec('UPDATE addresses SET is_default = 1, updated_at = ? WHERE id = ?', [now(), $next['id']]);   // another one takes over
        }

        return $this->back(route('account.addresses'), 'Address removed.');
    }

    /** The user's addresses, default first, then newest (User::addresses()). */
    public static function addressesOf(int $userId): array
    {
        return Database::get()->all('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, created_at DESC, id DESC', [$userId]);
    }

    private function find(Request $request): array
    {
        $a = Database::get()->one('SELECT * FROM addresses WHERE id = ?', [(int) ($request->params['address'] ?? 0)]);
        if (!$a) {
            throw new HttpException(404);
        }
        return $a;
    }

    private function authorize(array $address): void
    {
        if ((int) $address['user_id'] !== (int) $this->me()['id']) {
            throw new HttpException(403, 'This action is unauthorized.');
        }
    }

    private function validated(Request $request): array
    {
        $input = $request->post;
        if (Validator::pakistaniPhone($input['phone'] ?? null)) {
            $input['phone'] = Validator::normalizePhone($input['phone']);
        }
        return $this->validate($input, [
            'label' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'pk_phone'],
            'province' => ['required', 'in:' . implode(',', (array) Config::get('abiha.provinces', []))],
            'city' => ['required', 'string', 'min:2', 'max:80'],
            'address' => ['required', 'string', 'min:10', 'max:500'],
            'postal_code' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9\- ]+$/'],
            'is_default' => ['nullable', 'boolean'],
        ], ['address.min' => 'Please enter your complete address (house, street, area).']);
    }
}
