<?php

namespace App\Services\Servers;

use App\Contracts\CharacterRescueGateway;
use App\Models\GameServer;
use App\Models\GameServerTranslation;
use App\Services\GameServerFeatures\GameServerFeatureSettings;
use App\Services\Localization\LanguageManager;
use Throwable;

final class GameServerFormState
{
    public function __construct(
        private readonly LanguageManager $languages,
        private readonly GameServerFeatureSettings $features,
        private readonly CharacterRescueGateway $characterRescue,
    ) {}

    /** @return array<string,mixed> */
    public function defaults(): array
    {
        [$translations, $maintenanceMessages] = $this->localizedFields([], []);

        return [
            'editingId' => null,
            'translations' => $translations,
            'maintenanceMessages' => $maintenanceMessages,
            'maintenanceEnabled' => false,
            'characterRescueEnabled' => false,
            'characterRescueLocationName' => 'Giran',
            'characterRescueX' => '83400',
            'characterRescueY' => '148600',
            'characterRescueZ' => '-3400',
            'characterRescueOfflineDelayMinutes' => '5',
            'characterRescueCooldownHours' => '12',
            'characterRescueCapabilityState' => 'unavailable',
            'characterRescueMissingColumns' => [],
            'statisticsEnabled' => false,
            'statisticsLevelEnabled' => true,
            'statisticsPvpEnabled' => true,
            'statisticsPkEnabled' => true,
            'statisticsPlayTimeEnabled' => true,
            'statisticsHeroesEnabled' => true,
            'statisticsCastlesEnabled' => true,
            'statisticsLevelLimit' => '10',
            'statisticsPvpLimit' => '10',
            'statisticsPkLimit' => '10',
            'statisticsPlayTimeLimit' => '10',
            'serverRates' => '',
            'serverChronicle' => '',
            'serverMode' => '',
            'connectionEnabled' => false,
            'loginServerId' => '',
            'driver' => ServerDriverRegistry::MOBIUS_DRIVER,
            'useLoginServerConnection' => true,
            'databaseHost' => '127.0.0.1',
            'databasePort' => '3306',
            'databaseName' => '',
            'databaseUsername' => '',
            'databasePassword' => '',
            'databaseCharset' => 'utf8mb4',
            'serviceHost' => '',
            'servicePort' => '7777',
            'connectionReport' => null,
            'status' => null,
            'showChecks' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function fromServer(GameServer $server): array
    {
        $server->loadMissing(['translations', 'loginServer', 'features']);
        $translations = [];
        $maintenanceMessages = [];

        foreach ($this->languages->enabledCodes() as $locale) {
            $translation = $server->translations->firstWhere('locale', $locale);
            $translations[$locale] = $translation instanceof GameServerTranslation
                ? trim((string) $translation->name)
                : ($locale === $this->languages->default() ? trim((string) $server->name) : '');
            $maintenanceMessages[$locale] = $translation instanceof GameServerTranslation
                ? trim((string) $translation->maintenance_message)
                : '';
        }

        return [
            'editingId' => $server->id,
            'translations' => $translations,
            'maintenanceMessages' => $maintenanceMessages,
            'maintenanceEnabled' => (bool) $server->maintenance_enabled,
            'statisticsEnabled' => (bool) $server->statistics_enabled,
            'statisticsLevelEnabled' => (bool) $server->statistics_level_enabled,
            'statisticsPvpEnabled' => (bool) $server->statistics_pvp_enabled,
            'statisticsPkEnabled' => (bool) $server->statistics_pk_enabled,
            'statisticsPlayTimeEnabled' => (bool) $server->statistics_play_time_enabled,
            'statisticsHeroesEnabled' => (bool) $server->statistics_heroes_enabled,
            'statisticsCastlesEnabled' => (bool) $server->statistics_castles_enabled,
            'statisticsLevelLimit' => (string) $server->statistics_level_limit,
            'statisticsPvpLimit' => (string) $server->statistics_pvp_limit,
            'statisticsPkLimit' => (string) $server->statistics_pk_limit,
            'statisticsPlayTimeLimit' => (string) $server->statistics_play_time_limit,
            'serverRates' => trim((string) $server->rates),
            'serverChronicle' => trim((string) $server->chronicle),
            'serverMode' => trim((string) $server->mode),
            'connectionEnabled' => $server->connectionConfigured(),
            'loginServerId' => $server->login_server_id !== null ? (string) $server->login_server_id : '',
            'driver' => $server->driver ?? ServerDriverRegistry::MOBIUS_DRIVER,
            'useLoginServerConnection' => $server->connectionConfigured()
                ? (bool) $server->use_login_server_connection
                : true,
            'databaseHost' => trim((string) $server->database_host) !== '' ? trim((string) $server->database_host) : '127.0.0.1',
            'databasePort' => (string) ($server->database_port ?? 3306),
            'databaseName' => trim((string) $server->database_name),
            'databaseUsername' => trim((string) $server->database_username),
            'databasePassword' => '',
            'databaseCharset' => trim((string) $server->database_charset) !== '' ? trim((string) $server->database_charset) : 'utf8mb4',
            'serviceHost' => trim((string) $server->service_host),
            'servicePort' => (string) ($server->service_port ?? 7777),
            ...$this->characterRescueState($server),
            'connectionReport' => null,
            'status' => null,
            'showChecks' => false,
        ];
    }

    /**
     * @param  array<string,string>  $translations
     * @param  array<string,string>  $maintenanceMessages
     * @return array{0:array<string,string>,1:array<string,string>}
     */
    public function localizedFields(array $translations, array $maintenanceMessages): array
    {
        $enabled = array_fill_keys($this->languages->enabledCodes(), true);
        $translations = array_intersect_key($translations, $enabled);
        $maintenanceMessages = array_intersect_key($maintenanceMessages, $enabled);

        foreach (array_keys($enabled) as $locale) {
            $translations[$locale] ??= '';
            $maintenanceMessages[$locale] ??= '';
        }

        return [$translations, $maintenanceMessages];
    }

    /** @return array<string,mixed> */
    public function characterRescueState(GameServer $server): array
    {
        $rescue = $this->features->characterRescue($server);
        $capabilityState = 'unavailable';
        $missingColumns = [];

        if ($this->characterRescue->supports($server)) {
            try {
                $capabilities = $this->characterRescue->capabilities($server);
                $capabilityState = $capabilities->supported ? 'supported' : 'unavailable';
                $missingColumns = $capabilities->missingColumns;
            } catch (Throwable) {
                $capabilityState = 'error';
            }
        }

        return [
            'characterRescueEnabled' => $rescue['enabled'],
            'characterRescueLocationName' => $rescue['location_name'],
            'characterRescueX' => (string) $rescue['x'],
            'characterRescueY' => (string) $rescue['y'],
            'characterRescueZ' => (string) $rescue['z'],
            'characterRescueOfflineDelayMinutes' => (string) $rescue['offline_delay_minutes'],
            'characterRescueCooldownHours' => (string) $rescue['cooldown_hours'],
            'characterRescueCapabilityState' => $capabilityState,
            'characterRescueMissingColumns' => $missingColumns,
        ];
    }
}
