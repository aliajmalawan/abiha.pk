<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ContactMessage;
use App\Models\Page;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Payments\PaymentManager;
use App\Services\ShippingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function show(Page $page): View
    {
        abort_unless($page->is_published, 404);

        // The FAQ page shows the built-in questions (config/faq.php) until the owner writes their own text in Admin → Pages.
        if ($page->slug === 'faq' && ($page->is_sample || trim(strip_tags((string) $page->body)) === '')) {
            return view('storefront.faq', ['page' => $page, 'groups' => $this->faqGroups()]);
        }

        // Same for About us: a ready-made page built only from what the shop really has, until the owner writes their own text.
        if ($page->slug === 'about-us' && ($page->is_sample || trim(strip_tags((string) $page->body)) === '')) {
            return view('storefront.about', ['page' => $page] + $this->aboutData());
        }

        return view('storefront.page', ['page' => $page]);
    }

    /** @return array{collections: \Illuminate\Support\Collection, paymentLine: string, phone: string, email: string} */
    private function aboutData(): array
    {
        // Top-level categories that really have products (in themselves or in a sub-category), with a few of their sub-category names.
        $collections = Category::query()->active()->roots()->with('children')->orderBy('sort_order')->get()
            ->filter(fn (Category $c) => Product::query()->where('is_active', true)->whereIn('category_id', $c->descendantIds())->exists())
            ->map(fn (Category $c) => ['category' => $c, 'subs' => $c->children->where('is_active', true)->filter(fn (Category $k) => Product::query()->where('is_active', true)->whereIn('category_id', $k->descendantIds())->exists())->pluck('name')])
            ->values();

        $methods = app(PaymentManager::class)->enabled()->map(fn ($g) => $g->label())->values();
        $paymentLine = $methods->isEmpty() ? 'Choose how you would like to pay at checkout.' : 'Pay by '.$methods->join(', ', ' or ').'. You always see the available options at checkout.';

        return [
            'collections' => $collections,
            'paymentLine' => $paymentLine,
            'phone' => trim((string) Setting::get('contact.phone', config('abiha.contact.phone'))),
            'email' => trim((string) Setting::get('contact.email', config('abiha.contact.email'))),
        ];
    }

    /** The built-in FAQ with its {tokens} turned into escaped text, links and the shop's live values. @return array<string, list<array{q:string,a:string}>> */
    private function faqGroups(): array
    {
        $links = [
            '{payments}' => e(app(\App\Services\Payments\PaymentManager::class)->enabled()->map(fn ($g) => $g->label())->values()->whenEmpty(fn ($c) => $c->push('see the options at checkout'))->join(', ', ' and ')),
            '{shipping}' => '<a href="'.e(route('page.shipping')).'">Shipping &amp; returns</a>',
            '{track}' => '<a href="'.e(route('track')).'">Track your order</a>',
            '{contact}' => '<a href="'.e(route('contact')).'">contact us</a>',
            '{login}' => '<a href="'.e(route('login')).'">sign in</a>',
            '{register}' => '<a href="'.e(route('register')).'">create an account</a>',
            '{forgot}' => '<a href="'.e(route('password.request')).'">forgot password</a>',
            '{privacy}' => '<a href="'.e(url('/pages/privacy-policy')).'">Privacy policy</a>',
        ];
        $phone = trim((string) Setting::get('contact.phone', config('abiha.contact.phone')));
        $email = trim((string) Setting::get('contact.email', config('abiha.contact.email')));
        $links['{phone}'] = $phone !== '' ? 'You can also call us on <a href="tel:'.e(preg_replace('/[^\d+]/', '', $phone)).'">'.e($phone).'</a>.' : '';
        $links['{email}'] = $email !== '' ? 'Or email <a href="mailto:'.e($email).'">'.e($email).'</a>.' : '';

        return collect(config('faq.groups'))->map(fn ($items) => collect($items)->map(fn ($i) => [
            'q' => $i['q'],
            'a' => trim(strtr(e($i['a']), array_map(fn ($v) => $v, $links))),
        ])->all())->all();
    }

    public function shippingReturns(ShippingService $shipping): View
    {
        return view('storefront.shipping-returns', [
            'deliveryInfo' => Setting::get('delivery.info'),
            'returnPolicy' => Setting::get('returns.policy'),
            'methods' => $shipping->methods(),
        ]);
    }

    public function contact(): View
    {
        return view('storefront.contact');
    }

    public function sendContact(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'regex:/^[\d+\-\s()]{7,20}$/'],
            'subject' => ['nullable', 'string', 'max:160'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
            'website' => ['max:0'],                    // honeypot: humans leave it empty
        ]);
        unset($data['website']);

        ContactMessage::create($data + ['user_id' => $request->user()?->id, 'ip_address' => $request->ip()]);

        return redirect()->route('contact')->with('status', 'Thank you. Your message has been sent and we will reply by email.');
    }
}
