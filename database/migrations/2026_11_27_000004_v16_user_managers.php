<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V16 item 1 (27-11-2026): approving registrations and managing users now follow the designation (State Admin,
 * District Programmer, Block MIS), not per-user grants. The old "If permitted" grants of these two are removed;
 * each officer who lost one is written to the audit log (permissions_retired). Also makes sure the two
 * designations exist (District Programmer, Block MIS) on databases seeded before them.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['district_programmer' => 'District Programmer', 'block_mis' => 'Block MIS'] as $code => $label) {
            if (! DB::table('master_values')->where('type', 'designation')->where('code', $code)->exists()) {
                $other = DB::table('master_values')->where('type', 'designation')->where('code', 'other')->value('sort') ?? 1000;
                DB::table('master_values')->insert(['type' => 'designation', 'code' => $code, 'label' => $label, 'sort' => max(0, $other - 1),
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        $ids = DB::table('permissions')->whereIn('slug', ['approve_registrations', 'manage_users'])->pluck('slug', 'id');
        if ($ids->isEmpty()) {
            return;
        }
        $rows = DB::table('user_permissions')->whereIn('permission_id', $ids->keys())->get(['user_id', 'permission_id']);
        foreach ($rows->groupBy('user_id') as $userId => $grants) {
            DB::table('audit_logs')->insert([
                'user_id' => null, 'action' => 'permissions_retired', 'auditable_type' => 'App\Models\User', 'auditable_id' => $userId,
                'meta' => json_encode(['permissions' => $grants->map(fn ($g) => $ids[$g->permission_id])->values(), 'reason' => 'V16: user management follows the designation']),
                'created_at' => now(),
            ]);
        }
        DB::table('user_permissions')->whereIn('permission_id', $ids->keys())->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids->keys())->delete();
    }

    public function down(): void
    {
        // the removed grants are listed in the audit log (permissions_retired); they are not restored automatically
    }
};
