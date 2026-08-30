<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('disk', 64)->nullable()->after('path');

            $table->string('checksum', 64)->nullable();

            $table->timestamp('uploaded_at')->nullable();

            $table->string('refused_reason', 255)->nullable();
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->index(['case_id', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex(['case_id', 'checksum']);
            $table->dropColumn(['disk', 'checksum', 'uploaded_at', 'refused_reason']);
        });
    }
};
