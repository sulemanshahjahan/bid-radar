<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bid count over time for "bids per hour". Freelancer project data: purged after 24 hours.
        Schema::create('bid_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('bid_count');
            $table->decimal('bid_avg', 12, 2)->nullable();
            $table->timestamp('recorded_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bid_snapshots');
    }
};
