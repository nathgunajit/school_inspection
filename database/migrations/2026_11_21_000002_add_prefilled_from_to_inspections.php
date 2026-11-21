<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modification V11 item 4: a new inspection is pre-filled from the school's previous submitted inspection
 * (App\Services\InspectionPrefill). The source is kept so the wizard can say where the answers came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->foreignId('prefilled_from_id')->nullable()->after('questionnaire_version')->constrained('inspections')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prefilled_from_id');
        });
    }
};
