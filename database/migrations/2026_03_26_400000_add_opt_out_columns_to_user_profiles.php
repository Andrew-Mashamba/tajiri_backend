<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->boolean('opt_out_sponsored')->default(false);
            $table->boolean('opt_out_collaboration')->default(false);
            $table->boolean('opt_out_battles')->default(false);
            $table->boolean('opt_out_threads')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn(['opt_out_sponsored', 'opt_out_collaboration', 'opt_out_battles', 'opt_out_threads']);
        });
    }
};
