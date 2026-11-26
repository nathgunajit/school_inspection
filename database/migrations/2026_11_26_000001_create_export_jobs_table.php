<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 26-11-2026 performance, module B: large Excel / PDF downloads are made by the queue worker (Downloads page). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('route_name', 80);
            $table->text('url');                       // the GET request that is replayed (filters in the query string)
            $table->string('title', 150);
            $table->string('status', 12)->default('queued'); // queued, running, done, failed
            $table->string('file_path')->nullable();   // private:exports/{user}/{random}.{ext}
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('error', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'created_at'], 'export_jobs_user_status_idx');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
