<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modification V14 (updated 25-11-2026), item 1: Instruction / Notification / Order letters from the admin
 * to the officers (CRCCs and others), with an optional attachment on the private disk, and who has read
 * and acknowledged each one. Audience = the chosen levels inside an area (district / block / cluster; null = all).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->string('type', 15);                    // instruction | notification | order
            $table->string('ref_no', 60)->nullable();      // letter / order number
            $table->string('title', 200);
            $table->text('body');
            $table->date('notice_date');
            $table->string('attachment_path')->nullable(); // private disk, notices/<random>.<ext>
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->json('levels');                        // UserLevel values that receive it
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('block_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cluster_id')->nullable()->constrained()->nullOnDelete();
            $table->date('expires_on')->nullable();
            $table->string('status', 12)->default('published'); // published | withdrawn
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'notice_date']);
        });

        Schema::create('notice_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['notice_id', 'user_id']);
            $table->index(['user_id', 'notice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_reads');
        Schema::dropIfExists('notices');
    }
};
