<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Speed timeline: posted_at -> created_at (found) -> scored_at -> alerted_at -> drafted_at -> bid_placed_at.
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('scored_at')->nullable()->after('posted_at');
            $table->timestamp('drafted_at')->nullable()->after('scored_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['scored_at', 'drafted_at']);
        });
    }
};
