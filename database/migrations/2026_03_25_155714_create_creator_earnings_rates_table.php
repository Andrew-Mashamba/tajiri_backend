<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('creator_earnings_rates', function (Blueprint $table) {
            $table->id();
            $table->string('metric')->unique(); // view, like, share, save, comment, watch_second
            $table->decimal('rate', 10, 4); // amount in TSh per unit
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed default monetization rates.
        // Metric meanings:
        // - view: earned per view
        // - like/share/save/comment: earned per interaction
        // - watch_second: earned per second of watch time
        $now = now();
        DB::table('creator_earnings_rates')->insert([
            ['metric' => 'view', 'rate' => 0.5, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['metric' => 'like', 'rate' => 2.0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['metric' => 'share', 'rate' => 5.0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['metric' => 'save', 'rate' => 3.0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['metric' => 'comment', 'rate' => 2.5, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['metric' => 'watch_second', 'rate' => 0.1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('creator_earnings_rates');
    }
};
