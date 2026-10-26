<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('filter_reason')->nullable()->after('ai_error'); // rules-filter skip reason
            $table->timestamp('alerted_at')->nullable()->after('filter_reason');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['filter_reason', 'alerted_at']);
        });
    }
};
