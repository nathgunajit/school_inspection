<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 28-11-2026:
 * - ref_code: one printed reference code per inspection (e.g. SI-1801-7K3QX9), shown beside the QR on the inspection
 *   report PDF and on the no-internet declaration form, and on the scanned page, so the two papers can be matched and
 *   a duplicate entry for the same school / day is seen.
 * - declaration_first_at / declaration_last_at / declaration_downloads: when the no-internet declaration form was
 *   downloaded for the school (also for a draft that is not submitted yet).
 * Nullable, so MySQL adds no ON UPDATE CURRENT_TIMESTAMP (rule 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $t) {
            $t->string('ref_code', 20)->nullable()->unique();
            $t->dateTime('declaration_first_at')->nullable();
            $t->dateTime('declaration_last_at')->nullable();
            $t->unsignedInteger('declaration_downloads')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $t) {
            $t->dropUnique(['ref_code']);
            $t->dropColumn(['ref_code', 'declaration_first_at', 'declaration_last_at', 'declaration_downloads']);
        });
    }
};
