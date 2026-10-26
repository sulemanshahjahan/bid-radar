<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            // Warnings for the owner, e.g. budget too low for scope, no turnaround for this category.
            $table->json('warnings')->nullable()->after('check_failures');
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropColumn('warnings');
        });
    }
};
