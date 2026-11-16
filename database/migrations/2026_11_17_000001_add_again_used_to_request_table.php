<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('request', 'again_used_at')) {
            Schema::table('request', function (Blueprint $table) {
                $table->timestamp('again_used_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('request', 'again_used_at')) {
            Schema::table('request', function (Blueprint $table) {
                $table->dropColumn('again_used_at');
            });
        }
    }
};
