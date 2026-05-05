<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            if (!Schema::hasColumn('posts', 'is_discovery_mode')) {
                $table->boolean('is_discovery_mode')->default(false);
            }
            if (!Schema::hasColumn('posts', 'discovery_mode_until')) {
                $table->timestampTz('discovery_mode_until')->nullable();
            }
            $table->index('is_discovery_mode');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['is_discovery_mode']);
            $table->dropColumn(['is_discovery_mode', 'discovery_mode_until']);
        });
    }
};
