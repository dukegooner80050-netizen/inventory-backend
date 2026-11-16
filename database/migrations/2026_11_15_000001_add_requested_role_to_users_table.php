<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // What the person said they are when signing up. Only a claim: the AMO decides the real role.
            if (!Schema::hasColumn('users', 'requested_role')) {
                $table->string('requested_role', 30)->nullable();
            }
            if (!Schema::hasColumn('users', 'requested_iddept')) {
                $table->unsignedBigInteger('requested_iddept')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['requested_role', 'requested_iddept'] as $c) {
                if (Schema::hasColumn('users', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
