<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 25-11-2026: the Notices & Orders letter types become a master (add / edit / make inactive). `notices.type` keeps the
 * stable `code`. The three original types and Publication, Meeting Minutes and Information are added here.
 */
return new class extends Migration
{
    public const DEFAULTS = [
        ['instruction', 'Instruction', 'bi-list-check', '#1565c0'],
        ['notification', 'Notification', 'bi-megaphone', '#1e9b4b'],
        ['order', 'Order', 'bi-file-earmark-text', '#e07b00'],
        ['publication', 'Publication', 'bi-journal-richtext', '#7b1fa2'],
        ['meeting_minutes', 'Meeting Minutes', 'bi-people', '#00838f'],
        ['information', 'Information', 'bi-info-circle', '#5c6bc0'],
    ];

    public function up(): void
    {
        Schema::create('notice_types', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();     // stored in notices.type; never changes
            $t->string('name', 60)->unique();
            $t->string('icon', 40)->default('bi-megaphone');
            $t->char('color', 7)->default('#1565c0');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        $now = now();
        DB::table('notice_types')->insert(array_map(fn ($d, $i) => [
            'code' => $d[0], 'name' => $d[1], 'icon' => $d[2], 'color' => $d[3], 'sort_order' => ($i + 1) * 10,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], self::DEFAULTS, array_keys(self::DEFAULTS)));

        Schema::table('notices', fn (Blueprint $t) => $t->string('type', 30)->change());
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_types');
    }
};
