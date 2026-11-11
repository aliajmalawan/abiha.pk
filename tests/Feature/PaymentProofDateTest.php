<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/** The admin order page crashed (500) for a bank-transfer order with an uploaded receipt, because the receipt time was plain text. */
class PaymentProofDateTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    public function test_the_receipt_upload_time_is_a_date(): void
    {
        $p = new Payment(['proof_submitted_at' => '2026-11-11 09:39:44']);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $p->proof_submitted_at);
        $this->assertSame('11 Nov', $p->proof_submitted_at->format('j M'));
    }
}
