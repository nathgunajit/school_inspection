<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 27-11-2026: a state holiday withdrawn for one district (e.g. a shorter summer vacation where Durga Puja holidays
 * were given instead). One row per withdrawn state-holiday day per district; that district works on that day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['holiday_id', 'district_id']);
            $table->index('district_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_withdrawals');
    }
};
