<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Auth\Auth;
use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ContentPages;
use App\Support\Settings;

/** Port of the old Storefront\PageController: CMS pages (with the built-in FAQ and About us), shipping & returns, contact. */
final class PageController extends Controller
{
    public function show(Request $request): Response
    {
        $page = Database::get()->one('SELECT * FROM pages WHERE slug = ? LIMIT 1', [(string) ($request->params['slug'] ?? '')]);
        if (!$page || (int) $page['is_published'] !== 1) {
            throw new HttpException(404);
        }
        $builtIn = (int) $page['is_sample'] === 1 || trim(strip_tags((string) $page['body'])) === '';

        // The FAQ page shows the built-in questions (app/Config/faq.php) until the owner writes their own text in Admin -> Pages.
        if ($page['slug'] === 'faq' && $builtIn) {
            return $this->view('store/pages/faq', ['page' => $page, 'groups' => ContentPages::faqGroups()], [
                'title' => $page['meta_title'] ?: 'FAQ: Frequently asked questions',
                'description' => $page['meta_description'] ?: 'Answers about ordering, payment, delivery, returns and your account at ' . cfg('abiha.name') . '.',
            ]);
        }

        // Same for About us: a ready-made page built only from what the shop really has.
        if ($page['slug'] === 'about-us' && $builtIn) {
            return $this->view('store/pages/about', ['page' => $page] + ContentPages::aboutData(), [
                'title' => $page['meta_title'] ?: 'About us',
                'description' => $page['meta_description'] ?: 'Meet ' . cfg('abiha.name') . ': performance, comfort and style for an active life. Browse our collections and learn how shopping with us works.',
            ]);
        }

        return $this->view('store/pages/page', ['page' => $page], [
            'title' => $page['meta_title'] ?: $page['title'],
            'description' => $page['meta_description'] ?: str_limit((string) $page['body'], 155),
        ]);
    }

    public function shippingReturns(Request $request): Response
    {
        return $this->view('store/pages/shipping-returns', [
            'deliveryInfo' => Settings::get('delivery.info'),
            'returnPolicy' => Settings::get('returns.policy'),
            'methods' => ContentPages::shippingMethods(),
        ], ['title' => 'Shipping & returns']);
    }

    public function contact(Request $request): Response
    {
        return $this->view('store/pages/contact', [], ['title' => 'Contact us']);
    }

    public function sendContact(Request $request): Response
    {
        $data = $this->validate($request->post, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'regex:/^[\d+\-\s()]{7,20}$/'],
            'subject' => ['nullable', 'string', 'max:160'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
            'website' => ['max:0'],                    // honeypot: humans leave it empty
        ]);
        $now = now();
        Database::get()->insert(
            "INSERT INTO contact_messages (user_id, name, email, phone, subject, message, status, ip_address, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'new', ?, ?, ?)",
            [Auth::id(), $data['name'], $data['email'], $data['phone'], $data['subject'], $data['message'], $request->ip(), $now, $now]
        );

        return $this->redirect(route('contact'), 'Thank you. Your message has been sent and we will reply by email.');
    }
}
