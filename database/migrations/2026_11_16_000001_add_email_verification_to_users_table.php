<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'email')) {
                $table->string('email', 120)->nullable()->unique();
            }
            if (!Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable();
            }
            // One-time sign-up code: only a hash is stored, never the code itself.
            if (!Schema::hasColumn('users', 'email_code_hash')) {
                $table->string('email_code_hash', 64)->nullable();
                $table->timestamp('email_code_expires_at')->nullable();
                $table->timestamp('email_code_sent_at')->nullable();
                $table->unsignedTinyInteger('email_code_attempts')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['email', 'email_verified_at', 'email_code_hash', 'email_code_expires_at', 'email_code_sent_at', 'email_code_attempts'] as $c) {
                if (Schema::hasColumn('users', $c)) {
                    if ($c === 'email') {
                        $table->dropUnique(['email']);
                    }
                    $table->dropColumn($c);
                }
            }
        });
    }
};
