<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->boolean('automated')->default(false)->after('threat_to_life')->index();
        });

        DB::table('cases')->where('flow', 'jev')->update(['automated' => true]);
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropIndex(['automated']);
            $table->dropColumn('automated');
        });
    }
};
