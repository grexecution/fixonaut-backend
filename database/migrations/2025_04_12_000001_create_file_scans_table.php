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
        Schema::create('file_scans', function (Blueprint $table) {
            $table->id();
            $table->string('site_url');
            $table->string('theme');
            $table->string('file_path');
            $table->string('file_type');
            $table->dateTime('scan_date');
            $table->json('issues_found')->nullable();
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            // Add indexes for frequent lookups
            $table->index('site_url');
            $table->index('scan_date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_scans');
    }
};
