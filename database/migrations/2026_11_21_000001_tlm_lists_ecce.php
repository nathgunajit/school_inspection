<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modification V10: a second TLM list, ECCE (Documents/TLM -2025-26-ECCE (1).docx), offered next to the
 * FLN list on a classroom observation. `list` = fln / ecce; `section` groups ECCE items (for students / schools).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tlm_items', function (Blueprint $table) {
            $table->string('list', 10)->default('fln')->after('id')->index();
            $table->string('section', 60)->nullable()->after('class');
        });
        Schema::table('tlm_items', function (Blueprint $table) {
            $table->unique(['list', 'class', 'name']);
        });
        Schema::table('tlm_items', function (Blueprint $table) {
            $table->dropUnique(['class', 'name']);
        });

        $now = now();
        $students = [['Alphabet and Number Tracing Card', null], ['Number Dot Domino', null]];
        $schools = [
            ['Alphabet Card', 'One box, cards per language'], ['Number Card', '9 cards'], ['Picture Story', '15 cards'],
            ['Picture Reading Poster', '6 posters'], ['Poem Poster', '3 posters'], ['Sound Domino', '24 cards'],
            ['Pre Number Concept Card', '25 cards'], ['Sequential Thinking Card', '16 cards'], ['Self Corrective Puzzle', '10 cards'],
            ['Jig Saw Puzzle', '6 cards'], ['User Manual', '26 pages'],
        ];
        $rows = [];
        foreach ([['TLM for students', $students], ['TLM for schools', $schools]] as $g => [$section, $items]) {
            foreach ($items as $i => [$name, $remarks]) {
                $rows[] = ['list' => 'ecce', 'class' => null, 'section' => $section, 'name' => $name, 'code' => null, 'variety' => 1,
                    'remarks' => $remarks, 'sort' => ($g * 100) + $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('tlm_items')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        $ecce = DB::table('tlm_items')->where('list', 'ecce')->pluck('id');
        DB::table('inspection_classroom_tlm')->whereIn('tlm_item_id', $ecce)->delete();
        DB::table('tlm_items')->whereIn('id', $ecce)->delete();
        Schema::table('tlm_items', function (Blueprint $table) {
            $table->unique(['class', 'name']);
        });
        Schema::table('tlm_items', function (Blueprint $table) {
            $table->dropUnique(['list', 'class', 'name']);
            $table->dropIndex(['list']);
            $table->dropColumn(['list', 'section']);
        });
    }
};
