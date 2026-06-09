<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// sale_batches — db_design §4. Invariant 0 <= slots_taken <= capacity must hold
// (enforced at runtime via transaction + lockForUpdate, spec §6).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('slots_taken')->default(0);
            $table->unsignedBigInteger('price');             // JPY = yen directly (zero-decimal)
            $table->char('currency', 3)->default('JPY');
            $table->timestamp('sale_starts_at');
            $table->timestamp('sale_ends_at')->nullable();
            $table->string('status')->default('scheduled');  // scheduled | on_sale | sold_out | closed
            $table->timestamps();

            $table->index(['course_id', 'status']);
            $table->index(['status', 'sale_starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_batches');
    }
};
