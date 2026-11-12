<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Services\AccountOrders;
use App\Services\Cart\CartException;

/** Port of the old Account\OrderController + OrderPolicy (view: owner; cancel: owner and cancellable). */
final class OrderController extends AccountBase
{
    private const PER_PAGE = 10;

    public function index(Request $request): Response
    {
        $db = Database::get();
        $uid = (int) $this->me()['id'];
        $total = (int) $db->value('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$uid]);
        $last = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $orders = $db->all(
            'SELECT o.*, (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS items_count FROM orders o WHERE o.user_id = ? ORDER BY o.created_at DESC, o.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            [$uid]
        );
        return $this->accountView('account/orders', ['orders' => $orders, 'page' => $page, 'lastPage' => $last], 'Order history', 'Order history');
    }

    public function show(Request $request): Response
    {
        $order = $this->owned($request);
        return $this->accountView('account/order', ['order' => AccountOrders::withDetails($order)], 'Order ' . $order['number'], 'Order details');
    }

    public function cancel(Request $request): Response
    {
        $order = $this->owned($request);
        if (!AccountOrders::isCancellable($order)) {
            throw new HttpException(403, 'This action is unauthorized.');
        }
        try {
            AccountOrders::cancel($order, $this->me());
        } catch (CartException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }
        if (class_exists(\App\Services\OrderMailer::class) && method_exists(\App\Services\OrderMailer::class, 'statusChanged')) {
            \App\Services\OrderMailer::statusChanged(AccountOrders::find((string) $order['number']), 'cancelled');
        }

        return $this->back(route('account.orders.show', $order), 'Your order has been cancelled.');
    }

    private function owned(Request $request): array
    {
        $order = AccountOrders::find((string) ($request->params['number'] ?? ''));
        if (!$order) {
            throw new HttpException(404);
        }
        if ((int) $order['user_id'] !== (int) $this->me()['id']) {
            throw new HttpException(403, 'This action is unauthorized.');
        }
        return $order;
    }
}
