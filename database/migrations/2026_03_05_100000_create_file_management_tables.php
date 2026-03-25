<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. User Files (files + folders)
        Schema::create('user_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('folder_id')->nullable();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('path', 1024)->default('/');
            $table->string('parent_path', 1024)->nullable();
            $table->string('storage_path', 1024)->default('');
            $table->string('mime_type', 127)->default('application/octet-stream');
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('is_folder')->default(false);
            $table->boolean('is_starred')->default(false);
            $table->boolean('is_offline')->default(false);
            $table->boolean('is_shared')->default(false);
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamp('share_expires_at')->nullable();
            $table->string('thumbnail_path', 1024)->nullable();
            $table->string('preview_path', 1024)->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('folder_id')->references('id')->on('user_files')->onDelete('cascade');

            $table->index('folder_id', 'idx_user_files_folder_id');
            $table->index(['user_id', 'path'], 'idx_user_files_path');
            $table->index(['user_id', 'is_starred'], 'idx_user_files_starred');
            $table->index(['user_id', 'mime_type'], 'idx_user_files_mime_type');
            $table->index(['user_id', 'updated_at'], 'idx_user_files_updated');
            $table->index(['user_id', 'last_accessed_at'], 'idx_user_files_recent');
        });

        // 2. File Shares (sharing with specific users)
        Schema::create('user_file_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained('user_files')->onDelete('cascade');
            $table->foreignId('shared_by_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('shared_with_user_id')->constrained('users')->onDelete('cascade');
            $table->string('permission')->default('view'); // view, edit
            $table->timestamps();

            $table->unique(['file_id', 'shared_with_user_id'], 'unique_file_share');
        });

        // 3. Storage Quotas
        Schema::create('user_storage_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->unsignedBigInteger('total_bytes')->default(5368709120); // 5GB
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedInteger('folder_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_file_shares');
        Schema::dropIfExists('user_files');
        Schema::dropIfExists('user_storage_quotas');
    }
};
