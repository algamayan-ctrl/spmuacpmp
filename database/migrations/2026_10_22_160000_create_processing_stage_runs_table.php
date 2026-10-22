<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_stage_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('borrowing_requests')->cascadeOnDelete();
            $table->foreignId('request_version_id')->constrained('request_versions')->cascadeOnDelete();
            $table->string('stage_code', 40);
            $table->foreignId('started_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamps();
            $table->unique(['request_version_id', 'stage_code'], 'processing_stage_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_stage_runs');
    }
};
