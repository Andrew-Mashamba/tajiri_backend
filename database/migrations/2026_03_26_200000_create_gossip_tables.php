<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("gossip_threads", function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("seed_post_id")->nullable()->index();
            $table->string("title_key")->nullable();
            $table->json("title_slots")->nullable();
            $table->string("category")->default("general")->index();
            $table->decimal("velocity_score", 8, 2)->default(0);
            $table->integer("post_count")->default(1);
            $table->integer("participant_count")->default(1);
            $table->string("status")->default("active")->index();
            $table->string("geographic_scope")->default("global");
            $table->decimal("latitude", 10, 7)->nullable();
            $table->decimal("longitude", 10, 7)->nullable();
            $table->timestamp("cooling_since")->nullable();
            $table->timestamps();
            $table->index(["status", "velocity_score"]);
            $table->index(["status", "category"]);
        });

        Schema::create("gossip_thread_posts", function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("thread_id")->index();
            $table->unsignedBigInteger("post_id")->index();
            $table->decimal("relevance_score", 5, 2)->default(0);
            $table->timestamp("added_at")->nullable();
            $table->timestamps();
            $table->unique(["thread_id", "post_id"]);
            $table->foreign("thread_id")->references("id")->on("gossip_threads")->onDelete("cascade");
        });

        Schema::create("thread_title_templates", function (Blueprint $table) {
            $table->id();
            $table->string("key")->unique();
            $table->string("template_en");
            $table->string("template_sw");
            $table->json("slots")->nullable();
            $table->string("category")->default("general");
            $table->string("tone")->default("hot");
            $table->boolean("is_active")->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("gossip_thread_posts");
        Schema::dropIfExists("gossip_threads");
        Schema::dropIfExists("thread_title_templates");
    }
};
