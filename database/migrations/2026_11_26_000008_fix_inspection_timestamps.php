<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 26-11-2026: times printed wrong on the inspection report (PDF and web).
 *
 * 1. MariaDB / MySQL with explicit_defaults_for_timestamp = OFF give the first NOT NULL TIMESTAMP column of a table
 *    "ON UPDATE CURRENT_TIMESTAMP". So inspections.started_at was set to "now" on every save (the report showed the
 *    start after the submit time), and inspection_ratings.calculated_at / otp_verifications.expires_at the same.
 *    The automatic update is removed, and started_at is repaired from created_at (written in the same moment at start,
 *    never changed): the earlier of the two is kept, so planned / load-test start times stay as they are.
 * 2. inspection_evidence.taken_at: the phone sends the photo time in UTC (toISOString), and it was stored as if it were
 *    India time, so photos showed 5 h 30 min early. EvidenceController converts it now; stored rows are converted here.
 */
return new class extends Migration
{
    private const NO_AUTO_UPDATE = [
        ['inspections', 'started_at', 'NOT NULL DEFAULT CURRENT_TIMESTAMP'],
        ['inspection_ratings', 'calculated_at', 'NOT NULL DEFAULT CURRENT_TIMESTAMP'],
        ['otp_verifications', 'expires_at', 'NOT NULL DEFAULT CURRENT_TIMESTAMP'],
    ];

    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (self::NO_AUTO_UPDATE as [$table, $col, $def]) {
                $extra = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())
                    ->where('TABLE_NAME', $table)->where('COLUMN_NAME', $col)->value('EXTRA');
                if ($extra !== null && stripos($extra, 'on update') !== false) {
                    DB::statement("ALTER TABLE `$table` MODIFY `$col` TIMESTAMP $def");
                }
            }
        }

        // started_at overwritten by later saves: the start is created_at (the earlier of the two)
        DB::table('inspections')->whereNotNull('created_at')->whereColumn('started_at', '>', 'created_at')
            ->update(['started_at' => DB::raw('created_at')]);

        // photo times stored in UTC -> the application time zone
        $tz = config('app.timezone');
        DB::table('inspection_evidence')->whereNotNull('taken_at')->orderBy('id')->select(['id', 'taken_at'])
            ->chunkById(500, function ($rows) use ($tz) {
                foreach ($rows as $r) {
                    DB::table('inspection_evidence')->where('id', $r->id)->update([
                        'taken_at' => Carbon::parse($r->taken_at, 'UTC')->setTimezone($tz)->format('Y-m-d H:i:s'),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // the repaired times are correct; nothing is put back
    }
};
