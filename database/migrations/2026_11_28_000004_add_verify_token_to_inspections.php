<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 28-11-2026: random code behind the QR on the inspection PDF (public, view-only page inspections.verify).
 * Created the first time the PDF is made; never a record ID, so it cannot be guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->string('verify_token', 48)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropUnique(['verify_token']);
            $table->dropColumn('verify_token');
        });
    }
};
