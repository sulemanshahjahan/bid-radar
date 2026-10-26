<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default('paste'); // paste | freelancer
            $table->unsignedBigInteger('freelancer_id')->nullable()->unique();
            $table->string('url')->nullable();

            // Project data: encrypted at rest, purged after 24 hours.
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->text('details')->nullable();  // budget, currency, bids, client, skills
            $table->text('scoring')->nullable();  // full scoring JSON (derived from the brief)
            $table->timestamp('purged_at')->nullable();

            // Kept long-term.
            $table->string('category')->nullable();
            $table->unsignedTinyInteger('fit_score')->nullable();
            $table->string('status')->default('new'); // new | drafted | bid_placed | skipped | won | lost
            $table->string('ai_state')->default('queued'); // queued | scoring | scored | drafting | drafted | failed | cap_reached
            $table->text('ai_error')->nullable();
            $table->boolean('auto_draft')->default(false);

            $table->decimal('my_bid_amount', 12, 2)->nullable();
            $table->string('my_bid_currency', 8)->nullable();
            $table->unsignedInteger('bid_count_at_bid')->nullable();
            $table->decimal('avg_bid_at_bid', 12, 2)->nullable();
            $table->timestamp('bid_placed_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'fit_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
