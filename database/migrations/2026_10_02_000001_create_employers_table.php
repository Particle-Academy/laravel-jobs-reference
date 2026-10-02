<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            // Present but UNUSED by this app's config. The gate's behaviour
            // cannot be asserted against a table that has no column to gate on,
            // and `EmployerGate` reading a column the table lacks is a different
            // branch (forgiving, with a warning) from reading one this instance
            // did not load (which must deny). Both need the column to exist.
            $table->string('status')->default('pending');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employers');
    }
};
