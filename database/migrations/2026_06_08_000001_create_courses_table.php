<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// courses — db_design §3. Columns marked "presentation" extend the spec to
// support the course detail UI (summary, level, lessons, duration, outcomes).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('summary');                       // presentation — short excerpt
            $table->text('description');
            $table->string('level')->nullable();             // presentation
            $table->unsignedInteger('lessons_count')->default(0); // presentation
            $table->string('duration_label')->nullable();    // presentation
            $table->json('outcomes')->nullable();            // presentation — "what you'll learn"
            $table->string('status')->default('published');  // draft | published | archived
            $table->timestamps();

            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
