<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drafts record which prompt version wrote them and the plan behind them (hook
 * type, proof, self-review), so wins can later be compared by hook type. Older
 * drafts keep null. A stored word maximum still on the old default (150) moves
 * to 250, so complex jobs can get a longer proposal; small jobs stay short
 * through their own per-draft limit (ProposalLength).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->string('prompt_version', 32)->nullable()->after('attempts');
            $table->json('strategy')->nullable()->after('prompt_version');
        });

        $max = Setting::query()->where('key', 'word_max')->first();
        if ($max && (int) $max->value === 150) {
            $max->update(['value' => 250]);
        }
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropColumn(['prompt_version', 'strategy']);
        });
    }
};
