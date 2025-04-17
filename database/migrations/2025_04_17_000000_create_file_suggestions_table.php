<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('file_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_scan_id')->constrained('file_scans')->onDelete('cascade');
            $table->string('file_path');
            $table->integer('line_number')->nullable();
            $table->text('code_snippet')->nullable();
            $table->text('suggestion');
            $table->enum('status', ['pending', 'processing', 'processed', 'failed'])->default('pending');
            $table->string('ai_model')->nullable();
            $table->integer('token_count')->nullable();
            $table->timestamp('last_modified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamps();
            
            // Index for faster lookups
            $table->index(['file_scan_id', 'status']);
            $table->index(['file_path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_suggestions');
    }
};
