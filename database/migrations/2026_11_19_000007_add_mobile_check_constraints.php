<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Modification V7 (items 2 and 3): a mobile number is 10 digits starting with 6, 7, 8 or 9, enforced in the
 * database as well as by the form rules. MySQL 8 / MariaDB 10.2+ only; SQLite (tests) cannot add a CHECK to an
 * existing table, and the application rules cover it there.
 */
return new class extends Migration
{
    private const CHECKS = [
        ['users', 'chk_users_mobile', 'mobile'],
        ['mobile_change_requests', 'chk_mcr_new_mobile', 'new_mobile'],
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach (self::CHECKS as [$table, $name, $col]) {
            $bad = DB::table($table)->whereRaw("`$col` NOT REGEXP '^[6-9][0-9]{9}$'")->count();
            if ($bad > 0) {
                throw new RuntimeException("$bad row(s) in $table.$col are not valid mobile numbers; fix them before migrating.");
            }
            DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `$name` CHECK (`$col` REGEXP '^[6-9][0-9]{9}$')");
        }
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach (self::CHECKS as [$table, $name]) {
            DB::statement("ALTER TABLE `$table` DROP CONSTRAINT `$name`");
        }
    }
};
