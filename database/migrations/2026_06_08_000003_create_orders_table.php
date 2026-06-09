<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// orders — db_design §6. amount is snapshotted from sale_batches.price at
// checkout (server-side, BR-3). State machine in spec §5.1.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('reservation_id')->nullable(); // Phương án A (no FK yet)
            $table->string('status')->default('pending');   // pending|processing|paid|failed|canceled|refunded|disputed
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('JPY');
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('stripe_charge_id')->nullable()->index();
            $table->string('payment_method_type')->nullable(); // card | konbini | ...
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
