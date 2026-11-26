<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 26-11-2026 performance, module D: FULLTEXT index on schools.name for the live school searches
 * (App\Support\SchoolSearch::live()). MySQL / MariaDB only; the tests' SQLite keeps the LIKE search.
 */
return new class extends Migration
{
    private const NAME = 'schools_name_ft';

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || Schema::hasIndex('schools', self::NAME)) {
            return;
        }
        DB::statement('ALTER TABLE schools ADD FULLTEXT '.self::NAME.' (name)');
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && Schema::hasIndex('schools', self::NAME)) {
            DB::statement('ALTER TABLE schools DROP INDEX '.self::NAME);
        }
    }
};
