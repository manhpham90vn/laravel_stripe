<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Keep the Checkout Session id alongside the PaymentIntent so we can actively
// expire the session the moment the slot-hold TTL elapses (§8.2a), instead of
// only waiting out Stripe's passive expires_at. createCheckout used to overwrite
// the cs_ id with the pi_ id, losing the handle needed for sessions->expire().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('stripe_checkout_session_id')->nullable()->index()->after('stripe_payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('stripe_checkout_session_id');
        });
    }
};
