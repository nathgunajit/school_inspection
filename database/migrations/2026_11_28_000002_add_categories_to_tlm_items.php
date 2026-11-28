<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 28-11-2026: a TLM item (FLN / ECCE) belongs to school categories (master_values type 'category').
 * category_ids null or empty = every category, so existing items keep showing for every school.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tlm_items', function (Blueprint $table) {
            $table->json('category_ids')->nullable()->after('section');
        });
    }

    public function down(): void
    {
        Schema::table('tlm_items', function (Blueprint $table) {
            $table->dropColumn('category_ids');
        });
    }
};
