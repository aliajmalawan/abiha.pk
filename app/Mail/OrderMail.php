<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Setting;
use App\Services\Payments\BankTransferGateway;
use App\Services\Payments\PaymentManager;
use App\Support\SiteSettings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One customer email about an order. `kind` decides the subject and the headline; the body always shows only what the database says
 * (no promised dates, no invented tracking). Sent through the SMTP settings of the .env file; see docs/DEPLOYMENT.md.
 *
 * kind: placed | paid | shipped | delivered | cancelled | returned
 */
class OrderMail extends Mailable
{
    private const HEADLINES = [
        'placed' => ['Order {n} received', 'Thank you. We have received your order.'],
        'paid' => ['Payment received for order {n}', 'We have received your payment. Thank you.'],
        'shipped' => ['Your order {n} has shipped', 'Your order is on its way.'],
        'delivered' => ['Your order {n} was delivered', 'Your order has been marked as delivered.'],
        'cancelled' => ['Your order {n} was cancelled', 'Your order has been cancelled.'],
        'returned' => ['Return recorded for order {n}', 'We have recorded the return of your order.'],
    ];

    public function __construct(public readonly Order $order, public readonly string $kind)
    {
    }

    public function envelope(): Envelope
    {
        [$subject] = self::HEADLINES[$this->kind];
        $contact = Setting::get('contact.email');

        return new Envelope(
            subject: config('abiha.name').'®: '.str_replace('{n}', $this->order->number, $subject),
            replyTo: $contact ? [new Address($contact, config('abiha.name'))] : [],
        );
    }

    public function content(): Content
    {
        $order = $this->order->loadMissing(['items', 'shippingAddress', 'shipment']);

        return new Content(
            view: 'emails.order',
            text: 'emails.order-text',
            with: [
                'order' => $order,
                'kind' => $this->kind,
                'headline' => self::HEADLINES[$this->kind][1],
                'brand' => config('abiha.name').'®',
                'cur' => config('abiha.currency.symbol'),
                'logo' => SiteSettings::asset('site.logo') ?: asset('assets/brand/abiha-logo-full-300.png'),
                'orderUrl' => route('order.confirmation', ['order' => $order, 'token' => $order->access_token]),
                'bank' => $order->payment_method === 'bank_transfer' && $order->payment_status !== 'paid' && in_array($this->kind, ['placed'], true)
                    ? trim(app(BankTransferGateway::class)->instructions()) : null,
                'paymentLabel' => app(PaymentManager::class)->all()->get($order->payment_method)?->label() ?? ucwords(str_replace('_', ' ', $order->payment_method)),
                'contactEmail' => Setting::get('contact.email'),
                'contactPhone' => Setting::get('contact.phone'),
            ],
        );
    }
}
