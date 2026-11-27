<?php

use App\Models\MasterValue;
use App\Models\User;
use App\Support\DesignationMaster;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 27-11-2026: designations typed after "Other" move into the Designation master
 * (an existing label is reused, only new ones are added) and the officers point at them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $other = MasterValue::idFor('designation', User::OTHER_DESIGNATION);
        if (! $other) {
            return;
        }

        DB::transaction(function () use ($other) {
            DB::table('users')->where('designation_id', $other)->whereNotNull('designation_other')
                ->orderBy('id')->get(['id', 'designation_other'])
                ->each(function ($u) {
                    if (trim($u->designation_other) === '' || DesignationMaster::isJustOther($u->designation_other)) {
                        return;
                    }
                    [$id] = DesignationMaster::resolve($u->designation_other);
                    DB::table('users')->where('id', $u->id)->update(['designation_id' => $id, 'designation_other' => null]);
                });
        });
    }

    public function down(): void
    {
        // the added designations stay in the master
    }
};
