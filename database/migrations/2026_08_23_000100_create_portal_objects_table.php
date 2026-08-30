<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();

            $table->unsignedInteger('allocated')->default(0);

            $table->timestamps();
        });

        Schema::create('portal_objects', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 32)->unique();

            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');

            $table->string('object_type', 32)->index();
            $table->unsignedBigInteger('object_id')->nullable();

            $table->string('label')->nullable();

            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['object_type', 'object_id']);
            $table->index(['year', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_objects');
        Schema::dropIfExists('reference_counters');
    }
};
