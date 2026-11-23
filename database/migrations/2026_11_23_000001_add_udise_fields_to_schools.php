<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School Master: the extra UDISE+ 2025-26 columns (Documents/SchoolMaster_UDISE 202526.xlsx).
 * All nullable, so schools uploaded earlier stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('school_type', 10)->nullable()->after('category_id');   // boys / girls / co_ed
            $table->string('area_type', 10)->nullable()->after('school_type');     // rural / urban
            $table->string('medium', 60)->nullable()->after('area_type');          // e.g. "AssameseBodo" (UDISE Med_Final_25)
            $table->string('assembly_constituency', 60)->nullable()->after('medium');
            $table->string('lok_sabha', 40)->nullable()->after('assembly_constituency');
            $table->json('streams')->nullable()->after('lok_sabha');               // {"arts":true,...}; null = no Higher Secondary
            $table->char('pincode', 6)->nullable()->after('address');
            $table->index('area_type');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropIndex(['area_type']);
            $table->dropColumn(['school_type', 'area_type', 'medium', 'assembly_constituency', 'lok_sabha', 'streams', 'pincode']);
        });
    }
};
