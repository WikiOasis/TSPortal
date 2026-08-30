<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->string('wiki_username')->nullable()->after('username_key');

            $table->timestamp('erased_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropIndex(['erased_at']);
            $table->dropColumn(['wiki_username', 'erased_at']);
        });
    }
};
