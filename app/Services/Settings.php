<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Owner-editable settings stored in the database, falling back to
 * config('bidradar.defaults'). Registered as a singleton.
 */
class Settings
{
    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->loaded === null) {
            // A stored null is treated as "not set", so the default applies instead of null.
            $stored = array_filter(Setting::query()->pluck('value', 'key')->all(), fn ($value) => $value !== null);
            $this->loaded = array_merge(config('bidradar.defaults'), $stored);
        }

        return $this->loaded;
    }

    /** @param array<string, mixed> $values */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $this->loaded = null;
    }

    public function float(string $key): float
    {
        return (float) $this->get($key);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        return array_values(array_filter(array_map('trim', (array) $this->get($key, [])), 'strlen'));
    }

    /** Polling needs BOTH the env flag (written permission) and the Settings toggle. */
    public function pollingActive(): bool
    {
        return config('bidradar.freelancer.polling_enabled') === true && (bool) $this->get('polling_enabled');
    }
}
