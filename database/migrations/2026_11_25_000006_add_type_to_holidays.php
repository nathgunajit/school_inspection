<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 25-11-2026: holiday types from the Assam GAD holiday list 2026. gazetted = office closed (blocks Today's schools and
 * plans); restricted = Restricted Holiday (an employee may take 2, offices open: shown only); half = half holiday from 1 PM
 * (shown only). Existing rows are gazetted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->string('type', 20)->default('gazetted')->after('name');
            $table->index(['type', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropIndex(['type', 'date']);
            $table->dropColumn('type');
        });
    }
};
