<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_applications', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('job_posting_id')
                ->constrained('job_postings')
                ->cascadeOnDelete();

            // Host-supplied user model — unconstrained for the same reason as employer_id.
            $table->unsignedBigInteger('user_id')->index();

            $table->text('cover_letter')->nullable();
            $table->string('resume_path')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();

            $table->string('status')->default('submitted')->index();
            $table->text('employer_notes')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();

            $table->timestamps();

            $table->index(['job_posting_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_applications');
    }
};
