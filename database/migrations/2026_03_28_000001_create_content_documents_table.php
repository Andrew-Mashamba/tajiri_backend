<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_documents', function (Blueprint $table) {
            $table->id();

            // Source identity
            $table->string('source_type', 20);
            $table->bigInteger('source_id');

            // Denormalized content
            $table->text('title')->nullable();
            $table->text('body')->nullable();
            // media_types, hashtags, mentions added as VARCHAR[] via raw SQL below
            $table->string('language', 5)->nullable();

            // Creator context
            $table->bigInteger('creator_id');
            $table->string('creator_tier', 20)->nullable();
            $table->float('creator_authority')->default(0);

            // Pre-computed scores
            $table->float('quality_score')->default(0);
            $table->float('engagement_score')->default(0);
            $table->float('freshness_score')->default(0);
            $table->float('content_rank')->default(0);
            $table->float('trending_score')->default(0);
            $table->float('spam_score')->default(0);
            $table->float('composite_score')->default(0);

            // Content tier
            $table->string('content_tier', 20)->default('medium');

            // Metadata
            $table->string('privacy', 20)->default('public');
            $table->string('region_name', 100)->nullable();
            $table->string('district_name', 100)->nullable();
            $table->string('category', 50)->nullable();

            // Timestamps
            $table->timestamp('published_at');
            $table->timestamp('indexed_at')->useCurrent();
            $table->timestamp('scores_updated_at')->nullable();

            $table->unique(['source_type', 'source_id']);
        });

        // Add columns not supported by Laravel Schema builder
        DB::statement("ALTER TABLE content_documents ADD COLUMN media_types VARCHAR[] DEFAULT '{}'");
        DB::statement("ALTER TABLE content_documents ADD COLUMN hashtags VARCHAR[] DEFAULT '{}'");
        DB::statement("ALTER TABLE content_documents ADD COLUMN mentions VARCHAR[] DEFAULT '{}'");
        DB::statement('ALTER TABLE content_documents ADD COLUMN embedding vector(768)');

        // Indexes
        DB::statement('CREATE INDEX idx_cd_composite ON content_documents(content_tier, composite_score DESC)');
        DB::statement('CREATE INDEX idx_cd_creator ON content_documents(creator_id)');
        DB::statement('CREATE INDEX idx_cd_region ON content_documents(region_name)');
        DB::statement('CREATE INDEX idx_cd_published ON content_documents(published_at DESC)');
        DB::statement('CREATE INDEX idx_cd_trending ON content_documents(trending_score DESC) WHERE trending_score > 0');
        DB::statement('CREATE INDEX idx_cd_embedding ON content_documents USING hnsw (embedding vector_cosine_ops)');
        DB::statement('CREATE INDEX idx_cd_hashtags ON content_documents USING gin (hashtags)');
        DB::statement('CREATE INDEX idx_cd_source ON content_documents(source_type, source_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('content_documents');
    }
};
