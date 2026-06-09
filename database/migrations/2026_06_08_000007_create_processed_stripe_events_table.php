<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// processed_stripe_events — db_design §8. Idempotency guard for webhooks (BR-5).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_stripe_events', function (Blueprint $table) {
            $table->string('event_id')->primary();  // Stripe event.id, e.g. evt_...
            $table->string('type');
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_stripe_events');
    }
};
