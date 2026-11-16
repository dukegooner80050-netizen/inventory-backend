<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'employee_id')) {
            Schema::table('users', function (Blueprint $table) {
                // Given at sign-up so the AMO can check the person against school records.
                $table->string('employee_id', 30)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'employee_id')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('employee_id'));
        }
    }
};
