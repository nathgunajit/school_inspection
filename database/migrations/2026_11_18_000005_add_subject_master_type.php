<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modification V2 item 10: subject master for the Classroom Performance autocomplete.
 * Adds 'subject' to the allowed master_values types. Existing rows are untouched.
 * Seed the list with: php artisan db:seed --class=MasterValueSeeder
 */
return new class extends Migration
{
    private const OLD = ['designation', 'management', 'category', 'photo_category', 'fund_category'];

    public function up(): void
    {
        Schema::table('master_values', function (Blueprint $table) {
            $table->enum('type', [...self::OLD, 'subject'])->change();
        });
    }

    public function down(): void
    {
        DB::table('master_values')->where('type', 'subject')->delete();
        Schema::table('master_values', function (Blueprint $table) {
            $table->enum('type', self::OLD)->change();
        });
    }
};
