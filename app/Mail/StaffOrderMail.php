<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A short "new order" email to the address saved as Order notification email (Admin → Settings). Optional. */
class StaffOrderMail extends Mailable
{
    public function __construct(public readonly Order $order)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('abiha.name').'®: new order '.$this->order->number);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.staff-order', with: [
            'order' => $this->order->loadMissing('shippingAddress'),
            'cur' => config('abiha.currency.symbol'),
            'adminUrl' => route('admin.orders.show', $this->order),
        ]);
    }
}
