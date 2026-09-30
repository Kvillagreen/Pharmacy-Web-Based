<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('batch_histories') || Schema::hasColumn('batch_histories', 'meta')) {
            return;
        }

        Schema::table('batch_histories', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('notes');
        });

        if (Schema::hasColumn('batch_histories', 'metadata')) {
            DB::table('batch_histories')->whereNotNull('metadata')->update([
                'meta' => DB::raw('metadata'),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('batch_histories') && Schema::hasColumn('batch_histories', 'meta')) {
            Schema::table('batch_histories', function (Blueprint $table) {
                $table->dropColumn('meta');
            });
        }
    }
};
