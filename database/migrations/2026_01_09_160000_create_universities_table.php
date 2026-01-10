<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('universities', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('acronym')->nullable();
            $table->string('region');
            $table->enum('ownership', ['public', 'private']);
            $table->enum('category', [
                'public_universities',
                'private_universities',
                'public_university_colleges',
                'private_university_colleges',
                'university_institutes_centers'
            ]);
            $table->string('type_label');
            $table->string('parent')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();

            $table->index('name');
            $table->index('acronym');
            $table->index('category');
            $table->index('ownership');
            $table->index('region');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('universities');
    }
};
