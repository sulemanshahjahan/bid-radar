<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * The owner offers basic SEO (2026-10-27): stored settings that predate this get SEO
 * as a target skill, lose the plain "SEO" exclusion, and list it in services offered.
 * Settings still on their defaults pick this up from config/bidradar.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->edit('target_skills', fn (array $skills) => in_array('seo', array_map('mb_strtolower', $skills), true) ? $skills : [...$skills, 'SEO']);
        $this->edit('exclude_keywords', fn (array $words) => array_values(array_filter($words, fn ($w) => mb_strtolower(trim((string) $w)) !== 'seo')));

        $offered = Setting::query()->where('key', 'services_offered')->first();
        if ($offered && is_string($offered->value) && trim($offered->value) !== '' && ! preg_match('/\bSEO\b/i', $offered->value)) {
            $offered->update(['value' => rtrim($offered->value)."\nBasic SEO."]);
        }
    }

    private function edit(string $key, callable $change): void
    {
        $setting = Setting::query()->where('key', $key)->first();
        if ($setting && is_array($setting->value)) {
            $setting->update(['value' => array_values($change($setting->value))]);
        }
    }
};
