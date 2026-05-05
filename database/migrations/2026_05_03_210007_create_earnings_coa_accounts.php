<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('db:seed', ['--class' => 'CreatorsFundCoaSeeder', '--force' => true]);
    }

    public function down(): void
    {
        // Removing COA rows in down() risks data loss — leave them in place.
    }
};
