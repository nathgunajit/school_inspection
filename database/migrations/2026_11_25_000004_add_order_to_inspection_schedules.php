<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modification V14 (updated 25-11-2026), item 4: a higher officer schedules school visits for a CRCC
 * with an attached order / document (private disk, schedule-orders/), and an order number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_schedules', function (Blueprint $table) {
            $table->string('order_ref', 60)->nullable()->after('notes');
            $table->string('attachment_path')->nullable()->after('order_ref');
            $table->string('attachment_name')->nullable()->after('attachment_path');
            $table->string('attachment_mime', 100)->nullable()->after('attachment_name');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_schedules', function (Blueprint $table) {
            $table->dropColumn(['order_ref', 'attachment_path', 'attachment_name', 'attachment_mime']);
        });
    }
};
