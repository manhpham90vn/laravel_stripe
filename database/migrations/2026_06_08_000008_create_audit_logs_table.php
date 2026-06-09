<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// audit_logs — db_design §9 (NFR-3). Tracks order/enrollment/batch transitions.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');          // order | enrollment | sale_batch | reservation
            $table->unsignedBigInteger('subject_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('actor');                  // user | admin | system | webhook
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
