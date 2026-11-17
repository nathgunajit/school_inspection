<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School Master (PRD 7.2) and Excel import history (PRD 7.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->char('udise_code', 11)->unique();
            $table->string('name', 200);
            $table->foreignId('state_id')->constrained()->restrictOnDelete();
            $table->foreignId('district_id')->constrained()->restrictOnDelete();
            $table->foreignId('block_id')->constrained()->restrictOnDelete();
            $table->foreignId('cluster_id')->constrained()->restrictOnDelete();
            $table->foreignId('management_id')->nullable()->constrained('master_values')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('master_values')->restrictOnDelete();
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->enum('status', ['active', 'closed'])->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->index('name');
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['location', 'school']);
            $table->string('file_name');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('added')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->string('error_file_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('schools');
    }
};
