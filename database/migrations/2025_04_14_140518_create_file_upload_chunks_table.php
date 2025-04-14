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
        Schema::create('file_upload_chunks', function (Blueprint $table) {
            $table->id();
            $table->string('file_identifier')->comment('References file_uploads.file_identifier');
            $table->unsignedBigInteger('upload_id')->comment('References file_uploads.id');
            $table->integer('chunk_index');
            $table->integer('chunk_size')->comment('Size of this chunk in bytes');
            $table->string('chunk_path')->nullable()->comment('Temporary path where the chunk is stored');
            $table->boolean('is_received')->default(false);
            $table->dateTime('received_at')->nullable();
            $table->timestamps();
            
            // Add indexes and constraints
            $table->index('file_identifier');
            $table->index('upload_id');
            $table->unique(['file_identifier', 'chunk_index']);
            $table->foreign('upload_id')->references('id')->on('file_uploads')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_upload_chunks');
    }
};
