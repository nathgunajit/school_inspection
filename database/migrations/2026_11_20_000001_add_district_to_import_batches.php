<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** District-wise School Master upload: which district a batch was for, and per-block counts. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->foreignId('district_id')->nullable()->after('type')->constrained('districts')->nullOnDelete();
            $table->json('summary')->nullable()->after('error_file_path');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('district_id');
            $table->dropColumn('summary');
        });
    }
};
