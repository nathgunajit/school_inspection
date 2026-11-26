<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 26-11-2026 performance, module C: the check and the import of an Excel upload run on the queue. A batch row now
 * exists from the check on, with its status (existing rows were finished imports: done).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            // checking, checked, check_failed, queued, importing, done, failed
            $table->string('status', 14)->default('done')->after('type');
            $table->boolean('district_all')->default(false)->after('district_id');
            $table->string('pending_path')->nullable()->after('error_file_path'); // private:imports/pending/…
            $table->json('preview')->nullable()->after('summary');
            $table->string('error', 1000)->nullable()->after('preview');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->index(['status', 'created_at'], 'import_batches_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropIndex('import_batches_status_idx');
            $table->dropColumn(['status', 'district_all', 'pending_path', 'preview', 'error', 'started_at', 'finished_at']);
        });
    }
};
