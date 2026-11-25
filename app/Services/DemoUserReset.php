<?php

namespace App\Services;

use App\Enums\UserLevel;
use App\Models\User;
use Database\Seeders\DemoNoticeSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Modification V13 item 1: remove every user except the State Administrator, then create
 * 3–4 demo officers per level on the current Location Master (DemoUserSeeder).
 */
class DemoUserReset
{
    /** @return array{removed: int, inspections: int, created: int} */
    public function run(): array
    {
        abort_if(app()->isProduction(), 403, 'Demo users cannot be reset in production.');

        $ids = User::withTrashed()->where('level', '!=', UserLevel::StateAdmin->value)->pluck('id');
        $admin = User::where('level', UserLevel::StateAdmin->value)->orderBy('id')->value('id');
        $files = [];
        $inspections = 0;

        DB::transaction(function () use ($ids, $admin, &$files, &$inspections) {
            foreach ($ids->chunk(500) as $chunk) {
                $insp = DB::table('inspections')->whereIn('officer_id', $chunk)->pluck('id');
                $inspections += $insp->count();
                foreach ($insp->chunk(500) as $ic) {
                    $files = array_merge($files, DB::table('inspection_evidence')->whereIn('inspection_id', $ic)->whereNotNull('storage_path')->pluck('storage_path')->all());
                    DB::table('inspection_evidence')->whereIn('inspection_id', $ic)->delete();
                    DB::table('inspections')->whereIn('prefilled_from_id', $ic)->update(['prefilled_from_id' => null]);
                    DB::table('inspections')->whereIn('id', $ic)->delete(); // answers, rows, ratings cascade
                }
                DB::table('inspection_evidence')->whereIn('uploaded_by', $chunk)->update(['uploaded_by' => $admin]);
                DB::table('inspection_schedules')->whereIn('officer_id', $chunk)->delete(); // items cascade
                DB::table('import_batches')->whereIn('uploaded_by', $chunk)->update(['uploaded_by' => $admin]);
                $files = array_merge($files,
                    DB::table('users')->whereIn('id', $chunk)->whereNotNull('photo_path')->pluck('photo_path')->all(),
                    DB::table('users')->whereIn('id', $chunk)->whereNotNull('id_proof_path')->where('id_proof_path', '!=', 'id-proofs/demo-id-card.png')->pluck('id_proof_path')->all());
                // 25-11-2026: notices they sent (created_by restricts the delete); reads cascade
                $files = array_merge($files, DB::table('notices')->whereIn('created_by', $chunk)->whereNotNull('attachment_path')->pluck('attachment_path')->all());
                DB::table('notices')->whereIn('created_by', $chunk)->delete();
                DB::table('mobile_change_requests')->whereIn('user_id', $chunk)->delete();
                DB::table('registration_requests')->whereIn('user_id', $chunk)->delete();
                DB::table('user_jurisdictions')->whereIn('user_id', $chunk)->delete();
                DB::table('user_permissions')->whereIn('user_id', $chunk)->delete();
                DB::table('sessions')->whereIn('user_id', $chunk)->delete();
                DB::table('users')->whereIn('id', $chunk)->delete(); // login logs / audit rows keep a null user
            }
        });

        $disk = Storage::disk('private');
        foreach (array_unique($files) as $path) {
            $disk->delete($path);
        }

        app(DemoUserSeeder::class)->run();
        app(DemoNoticeSeeder::class)->run(); // the demo officers' notices again
        $created = User::where('level', '!=', UserLevel::StateAdmin->value)->count();
        // the signed-in user may have been one of the removed accounts: record it under them only if they still exist
        $actor = User::find(auth()->id()) ?? User::find($admin);
        AuditLogger::log('demo_users_reset', null, ['removed' => $ids->count(), 'inspections' => $inspections, 'created' => $created], $actor);

        return ['removed' => $ids->count(), 'inspections' => $inspections, 'created' => $created];
    }
}
