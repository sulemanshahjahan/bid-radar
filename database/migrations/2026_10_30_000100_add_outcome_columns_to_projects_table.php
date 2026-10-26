<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // My own outcome data: kept long term (not Freelancer project data).
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedInteger('my_bid_rank')->nullable()->after('my_bid_currency');
            $table->boolean('viewed')->nullable()->after('bid_placed_at');
            $table->boolean('chat_started')->nullable()->after('viewed');
            $table->timestamp('won_at')->nullable()->after('chat_started');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['my_bid_rank', 'viewed', 'chat_started', 'won_at']);
        });
    }
};
