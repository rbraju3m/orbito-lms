<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. The provider's id for the MONEY, learned when it is captured.
 *
 * With Stripe Checkout the handoff id is a Checkout Session (`cs_…`), and it
 * stays in `external_id` because that is what checkout.session.* events name.
 * The money itself is a PaymentIntent (`pi_…`) — what Stripe refunds, and what
 * its refund events name — and it does not exist until the learner pays. Null
 * until then, and for a gateway whose handoff id already is the payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('provider_payment_id', 191)->nullable()->after('external_id');

            // A refund event names this one; it must find exactly one payment.
            $table->unique(['gateway', 'provider_payment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['gateway', 'provider_payment_id']);
            $table->dropColumn('provider_payment_id');
        });
    }
};
