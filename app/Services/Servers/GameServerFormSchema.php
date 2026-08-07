<?php

namespace App\Services\Servers;

use App\Services\Localization\LanguageManager;
use Illuminate\Validation\Rule;

final class GameServerFormSchema
{
    public function __construct(
        private readonly LanguageManager $languages,
        private readonly ServerDriverRegistry $drivers,
    ) {}

    /** @return array<string,mixed> */
    public function generalRules(): array
    {
        $rules = [
            'translations' => ['required', 'array'],
            'serverRates' => ['nullable', 'string', 'max:100'],
            'serverChronicle' => ['nullable', 'string', 'max:100'],
            'serverMode' => ['nullable', 'string', 'max:100'],
            'maintenanceEnabled' => ['required', 'boolean'],
            'maintenanceMessages' => ['required', 'array'],
            'statisticsEnabled' => ['required', 'boolean'],
            'statisticsLevelEnabled' => ['required', 'boolean'],
            'statisticsPvpEnabled' => ['required', 'boolean'],
            'statisticsPkEnabled' => ['required', 'boolean'],
            'statisticsPlayTimeEnabled' => ['required', 'boolean'],
            'statisticsHeroesEnabled' => ['required', 'boolean'],
            'statisticsCastlesEnabled' => ['required', 'boolean'],
            'statisticsLevelLimit' => ['required', 'integer', 'between:1,100'],
            'statisticsPvpLimit' => ['required', 'integer', 'between:1,100'],
            'statisticsPkLimit' => ['required', 'integer', 'between:1,100'],
            'statisticsPlayTimeLimit' => ['required', 'integer', 'between:1,100'],
        ];

        foreach ($this->languages->enabledCodes() as $locale) {
            $rules['translations.'.$locale] = $locale === $this->languages->default()
                ? ['required', 'string', 'max:100']
                : ['nullable', 'string', 'max:100'];
            $rules['maintenanceMessages.'.$locale] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    /** @return array<string,mixed> */
    public function connectionRules(bool $useLoginServerConnection): array
    {
        $rules = [
            'loginServerId' => ['required', 'integer', 'exists:login_servers,id'],
            'driver' => ['required', Rule::in($this->drivers->gameDriverKeys())],
            'useLoginServerConnection' => ['required', 'boolean'],
            'databasePassword' => ['nullable', 'string', 'max:1024'],
            'serviceHost' => ['nullable', 'string', 'max:255'],
            'servicePort' => ['required', 'integer', 'between:1,65535'],
        ];

        if (! $useLoginServerConnection) {
            $rules += [
                'databaseHost' => ['required', 'string', 'max:255'],
                'databasePort' => ['required', 'integer', 'between:1,65535'],
                'databaseName' => ['required', 'string', 'max:64'],
                'databaseUsername' => ['required', 'string', 'max:128'],
                'databaseCharset' => ['required', Rule::in(['utf8mb4', 'utf8', 'latin1', 'cp1251'])],
            ];
        }

        return $rules;
    }

    /** @return array<string,mixed> */
    public function featureRules(): array
    {
        return [
            'characterRescueEnabled' => ['required', 'boolean'],
            'characterRescueLocationName' => ['required', 'string', 'max:100'],
            'characterRescueX' => ['required', 'integer', 'between:-2147483648,2147483647'],
            'characterRescueY' => ['required', 'integer', 'between:-2147483648,2147483647'],
            'characterRescueZ' => ['required', 'integer', 'between:-2147483648,2147483647'],
            'characterRescueOfflineDelayMinutes' => ['required', 'integer', 'between:0,1440'],
            'characterRescueCooldownHours' => ['required', 'integer', 'between:0,720'],
        ];
    }

    /** @return array<string,string> */
    public function featureAttributes(): array
    {
        return [
            'characterRescueEnabled' => __('Enable character rescue'),
            'characterRescueLocationName' => __('Location name'),
            'characterRescueX' => __('Coordinate X'),
            'characterRescueY' => __('Coordinate Y'),
            'characterRescueZ' => __('Coordinate Z'),
            'characterRescueOfflineDelayMinutes' => __('Minimum offline time'),
            'characterRescueCooldownHours' => __('Reuse cooldown'),
        ];
    }

    /** @return array<string,string> */
    public function generalAttributes(): array
    {
        $attributes = [
            'serverRates' => __('Server rates validation attribute'),
            'serverChronicle' => __('Chronicle validation attribute'),
            'serverMode' => __('server mode'),
            'statisticsLevelLimit' => __('Level ranking limit'),
            'statisticsPvpLimit' => __('PvP ranking limit'),
            'statisticsPkLimit' => __('PK ranking limit'),
            'statisticsPlayTimeLimit' => __('Play time ranking limit'),
        ];

        foreach ($this->languages->enabledCodes() as $locale) {
            $attributes['translations.'.$locale] = __('Server name validation attribute');
            $attributes['maintenanceMessages.'.$locale] = __('Maintenance message validation attribute');
        }

        return $attributes;
    }

    /** @return array<string,string> */
    public function connectionAttributes(): array
    {
        return [
            'loginServerId' => __('LoginServer'),
            'driver' => __('GameServer driver'),
            'useLoginServerConnection' => __('Use LoginServer database connection'),
            'databaseHost' => __('Database host'),
            'databasePort' => __('Database port'),
            'databaseName' => __('Database name'),
            'databaseUsername' => __('Database username'),
            'databasePassword' => __('Database password'),
            'databaseCharset' => __('Database charset'),
            'serviceHost' => __('Service host'),
            'servicePort' => __('Service port'),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function generalValues(array $validated, string $driver): array
    {
        $translations = [];
        foreach ((array) $validated['translations'] as $locale => $name) {
            $translations[(string) $locale] = trim((string) $name);
        }

        $maintenanceMessages = [];
        foreach ((array) $validated['maintenanceMessages'] as $locale => $message) {
            $maintenanceMessages[(string) $locale] = trim((string) $message);
        }

        return [
            'name' => $translations[$this->languages->default()] ?? '',
            'rates' => trim((string) ($validated['serverRates'] ?? '')),
            'chronicle' => trim((string) ($validated['serverChronicle'] ?? '')),
            'mode' => trim((string) ($validated['serverMode'] ?? '')),
            'translations' => $translations,
            'maintenance_enabled' => (bool) $validated['maintenanceEnabled'],
            'maintenance_messages' => $maintenanceMessages,
            'statistics_enabled' => $this->statisticsCapabilities($driver) !== [] && (bool) $validated['statisticsEnabled'],
            'statistics_level_enabled' => (bool) $validated['statisticsLevelEnabled'],
            'statistics_pvp_enabled' => (bool) $validated['statisticsPvpEnabled'],
            'statistics_pk_enabled' => (bool) $validated['statisticsPkEnabled'],
            'statistics_play_time_enabled' => (bool) $validated['statisticsPlayTimeEnabled'],
            'statistics_heroes_enabled' => (bool) $validated['statisticsHeroesEnabled'],
            'statistics_castles_enabled' => (bool) $validated['statisticsCastlesEnabled'],
            'statistics_level_limit' => (int) $validated['statisticsLevelLimit'],
            'statistics_pvp_limit' => (int) $validated['statisticsPvpLimit'],
            'statistics_pk_limit' => (int) $validated['statisticsPkLimit'],
            'statistics_play_time_limit' => (int) $validated['statisticsPlayTimeLimit'],
        ];
    }

    /**
     * @param  array<string,mixed>  $validated
     * @return array{enabled:bool,location_name:string,x:int,y:int,z:int,offline_delay_minutes:int,cooldown_hours:int}
     */
    public function featureValues(array $validated): array
    {
        return [
            'enabled' => (bool) $validated['characterRescueEnabled'],
            'location_name' => trim((string) $validated['characterRescueLocationName']),
            'x' => (int) $validated['characterRescueX'],
            'y' => (int) $validated['characterRescueY'],
            'z' => (int) $validated['characterRescueZ'],
            'offline_delay_minutes' => (int) $validated['characterRescueOfflineDelayMinutes'],
            'cooldown_hours' => (int) $validated['characterRescueCooldownHours'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function connectionValues(array $validated): array
    {
        return [
            'login_server_id' => (int) $validated['loginServerId'],
            'driver' => trim((string) $validated['driver']),
            'use_login_server_connection' => (bool) $validated['useLoginServerConnection'],
            'database_host' => trim((string) ($validated['databaseHost'] ?? '')),
            'database_port' => (int) ($validated['databasePort'] ?? 3306),
            'database_name' => trim((string) ($validated['databaseName'] ?? '')),
            'database_username' => trim((string) ($validated['databaseUsername'] ?? '')),
            'database_password' => (string) ($validated['databasePassword'] ?? ''),
            'database_charset' => trim((string) ($validated['databaseCharset'] ?? 'utf8mb4')),
            'service_host' => $this->nullableString($validated['serviceHost'] ?? null),
            'service_port' => (int) ($validated['servicePort'] ?? 7777),
        ];
    }

    /** @return list<string> */
    public function statisticsCapabilities(string $driver): array
    {
        $definition = $this->drivers->gameDriver($driver);

        return is_array($definition) ? ($definition['statistics'] ?? []) : [];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
