<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FLN TLM master (Documents/FLN TLM list_2026-27.docx): the TLM items an officer ticks on a classroom
 * observation when "Use of TLM by teacher" = Yes. class null = offered for every class.
 * Items are never deleted, only made inactive, so older observations keep their items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tlm_items', function (Blueprint $table) {
            $table->id();
            $table->string('class', 4)->nullable()->index(); // InspectionClassroomObservation::CLASSES key; null = all classes
            $table->string('name', 150);
            $table->string('code', 60)->nullable();
            $table->unsignedSmallInteger('variety')->default(1);
            $table->string('remarks', 200)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['class', 'name']);
        });

        Schema::create('inspection_classroom_tlm', function (Blueprint $table) {
            $table->foreignId('classroom_observation_id')->constrained('inspection_classroom_observations')->cascadeOnDelete();
            $table->foreignId('tlm_item_id')->constrained('tlm_items')->restrictOnDelete();
            $table->primary(['classroom_observation_id', 'tlm_item_id']);
        });

        $now = now();
        $rows = [];
        $list = [
            'I' => [['Karjyapatra (Vidya Pravesh)', 'NIPUN AXOM G1L3'], ['Karjyapatra (Literacy)', 'NIPUN AXOM G1L4'], ['Karjyapatra (Numeracy)', 'NIPUN AXOM G1N2'],
                ['Reading Books (Literacy)', 'NIPUN AXOM G1L30'], ['English Activity Book, Class 1', null], ['Holistic Progress Card (HPC)', null]],
            'II' => [['Karjyapatra (Literacy)', 'NIPUN AXOM G2L2'], ['Karjyapatra (Numeracy)', 'NIPUN AXOM G2N2'], ['Reading Books (Literacy)', 'NIPUN AXOM G2L23'],
                ['English Activity Book, Class 2', null], ['Holistic Progress Card (HPC)', null]],
        ];
        foreach ($list as $class => $items) {
            foreach ($items as $i => [$name, $code]) {
                $rows[] = ['class' => $class, 'name' => $name, 'code' => $code, 'variety' => 1, 'remarks' => 'One copy per child',
                    'sort' => $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('tlm_items')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_classroom_tlm');
        Schema::dropIfExists('tlm_items');
    }
};
