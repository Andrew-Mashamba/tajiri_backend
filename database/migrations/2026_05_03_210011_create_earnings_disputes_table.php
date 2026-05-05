<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('earnings_disputes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('user_id');
            $table->text('reason');
            $table->string('status')->default('open');     // open|investigating|resolved|reversed|denied
            $table->text('resolution_notes')->nullable();
            $table->timestampTz('filed_at')->useCurrent();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'status']);
            $table->index(['event_id', 'status']);
            $table->index('filed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('earnings_disputes');
    }
};
