<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_categories', function (Blueprint $table) {
            $table->string('slug', 50)->primary();
            $table->string('name_en', 100);
            $table->string('name_sw', 100);
        });

        DB::table('content_categories')->insert([
            ['slug' => 'entertainment', 'name_en' => 'Entertainment', 'name_sw' => 'Burudani'],
            ['slug' => 'music', 'name_en' => 'Music', 'name_sw' => 'Muziki'],
            ['slug' => 'sports', 'name_en' => 'Sports', 'name_sw' => 'Michezo'],
            ['slug' => 'news', 'name_en' => 'News', 'name_sw' => 'Habari'],
            ['slug' => 'business', 'name_en' => 'Business', 'name_sw' => 'Biashara'],
            ['slug' => 'education', 'name_en' => 'Education', 'name_sw' => 'Elimu'],
            ['slug' => 'lifestyle', 'name_en' => 'Lifestyle', 'name_sw' => 'Mtindo wa Maisha'],
            ['slug' => 'technology', 'name_en' => 'Technology', 'name_sw' => 'Teknolojia'],
            ['slug' => 'politics', 'name_en' => 'Politics', 'name_sw' => 'Siasa'],
            ['slug' => 'religion', 'name_en' => 'Religion', 'name_sw' => 'Dini'],
            ['slug' => 'food', 'name_en' => 'Food', 'name_sw' => 'Chakula'],
            ['slug' => 'travel', 'name_en' => 'Travel', 'name_sw' => 'Safari'],
            ['slug' => 'fashion', 'name_en' => 'Fashion', 'name_sw' => 'Mitindo'],
            ['slug' => 'health', 'name_en' => 'Health', 'name_sw' => 'Afya'],
            ['slug' => 'comedy', 'name_en' => 'Comedy', 'name_sw' => 'Vichekesho'],
            ['slug' => 'other', 'name_en' => 'Other', 'name_sw' => 'Nyingine'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('content_categories');
    }
};
