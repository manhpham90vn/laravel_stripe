<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// reservations — db_design §5 (Phương án A: reserve-with-timeout).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active');   // active | consumed | expired | released
            $table->timestamp('reserved_until');
            $table->timestamps();

            $table->index(['status', 'reserved_until']);    // for the expiry-sweeping job
        });

        // Now that reservations exists, link orders.reservation_id to it.
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('reservation_id')->references('id')->on('reservations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['reservation_id']);
        });

        Schema::dropIfExists('reservations');
    }
};
