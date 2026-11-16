<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A requester may appeal a rejected request once. The AMO (or the program head who rejected it) answers.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request', function (Blueprint $table) {
            if (!Schema::hasColumn('request', 'appeal_message')) {
                $table->string('appeal_message', 100)->nullable();
            }
            if (!Schema::hasColumn('request', 'appealed_at')) {
                $table->timestamp('appealed_at')->nullable();
            }
            if (!Schema::hasColumn('request', 'appeal_decision')) {
                $table->string('appeal_decision', 20)->nullable();   // Reopened | Upheld
            }
            if (!Schema::hasColumn('request', 'appeal_reply')) {
                $table->string('appeal_reply', 100)->nullable();
            }
            if (!Schema::hasColumn('request', 'appeal_decided_by')) {
                $table->unsignedBigInteger('appeal_decided_by')->nullable();
            }
            if (!Schema::hasColumn('request', 'appeal_decided_at')) {
                $table->timestamp('appeal_decided_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('request', function (Blueprint $table) {
            foreach (['appeal_message', 'appealed_at', 'appeal_decision', 'appeal_reply', 'appeal_decided_by', 'appeal_decided_at'] as $c) {
                if (Schema::hasColumn('request', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
