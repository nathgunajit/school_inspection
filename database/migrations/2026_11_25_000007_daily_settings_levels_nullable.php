<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 25-11-2026: the officer levels that get Today's schools are the State's choice. A district row with levels = null
 * follows the state default (District users cannot change them; the State Admin can still set a district's own).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_display_settings', fn (Blueprint $t) => $t->json('levels')->nullable()->change());

        // district rows last saved by a District user follow the State from now on
        $admins = DB::table('users')->where('level', 'state_admin')->pluck('id');
        DB::table('daily_display_settings')->whereNotNull('district_id')
            ->where(fn ($q) => $q->whereNull('updated_by')->orWhereNotIn('updated_by', $admins))
            ->update(['levels' => null]);
    }

    public function down(): void
    {
        DB::table('daily_display_settings')->whereNull('levels')->update(['levels' => json_encode(['cluster'])]);
        Schema::table('daily_display_settings', fn (Blueprint $t) => $t->json('levels')->nullable(false)->change());
    }
};
