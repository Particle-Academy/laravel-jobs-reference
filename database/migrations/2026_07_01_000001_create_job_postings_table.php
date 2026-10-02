<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $table): void {
            $table->id();

            // The employer model is host-supplied (config laravel-jobs.employer_model),
            // so this is an unconstrained id rather than a foreign key — the package
            // cannot know the table it points at.
            $table->unsignedBigInteger('employer_id')->index();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();

            $table->string('employment_type')->nullable()->index();
            $table->string('location')->nullable();
            $table->boolean('is_remote')->default(false)->index();

            $table->unsignedInteger('pay_min')->nullable();
            $table->unsignedInteger('pay_max')->nullable();
            $table->string('pay_unit')->nullable();   // hour | day | week | month | year
            $table->string('currency', 3)->nullable();

            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('apply_url')->nullable();

            $table->string('status')->default('draft')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();

            $table->unsignedInteger('openings')->default(1);
            $table->unsignedInteger('applications_count')->default(0);

            $table->timestamps();

            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_postings');
    }
};
