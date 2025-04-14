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
        Schema::create('file_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('file_identifier')->unique()->comment('Unique identifier for the upload');
            $table->string('site_url');
            $table->string('theme');
            $table->string('file_path');
            $table->string('file_type');
            $table->bigInteger('file_size');
            $table->string('storage_path')->nullable()->comment('Path where the file is stored');
            $table->string('status')->default('pending')->comment('pending, processing, completed, failed, aborted');
            $table->integer('total_chunks')->default(1);
            $table->integer('received_chunks')->default(0);
            $table->json('chunk_status')->nullable()->comment('Status of each chunk');
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedBigInteger('scan_id')->nullable()->comment('Link to file_scans table');
            $table->timestamps();
            
            // Add indexes
            $table->index('file_identifier');
            $table->index('site_url');
            $table->index('status');
            $table->index('scan_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_uploads');
    }
};
