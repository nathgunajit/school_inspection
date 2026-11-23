<?php

use App\Models\School;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 23-11-2026: a school may teach in more than one medium. UDISE+ joins them ("AssameseBodo"); store them as a list
 * ("Assamese, Bodo", School::normaliseMedium()) and give the column room for four or five languages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('medium', 120)->nullable()->change();
        });

        foreach (DB::table('schools')->whereNotNull('medium')->distinct()->pluck('medium') as $old) {
            $new = School::normaliseMedium($old);
            if ($new !== $old) {
                DB::table('schools')->where('medium', $old)->update(['medium' => $new]);
            }
        }
    }

    public function down(): void
    {
        // the joined UDISE text is not needed back; the list form reads the same everywhere
    }
};
