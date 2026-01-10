<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('acronym')->nullable();
            $table->string('sector');
            $table->enum('ownership', ['government', 'private', 'public_listed', 'foreign']);
            $table->enum('category', [
                'parastatal',
                'dse_listed',
                'dse_cross_listed',
                'conglomerate',
                'subsidiary',
                'multinational',
                'sme'
            ]);
            $table->string('parent')->nullable();
            $table->string('region')->nullable();
            $table->string('ministry')->nullable();
            $table->string('owner')->nullable();
            $table->string('isin')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->text('products')->nullable();
            $table->string('year_established')->nullable();
            $table->string('source')->nullable();
            $table->string('source_url')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('name');
            $table->index('acronym');
            $table->index('sector');
            $table->index('ownership');
            $table->index('category');
            $table->index('parent');
            $table->index('region');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
