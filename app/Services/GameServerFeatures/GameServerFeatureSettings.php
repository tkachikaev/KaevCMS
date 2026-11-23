<?php

namespace App\Services\GameServerFeatures;

use App\Models\GameServer;
use App\Models\GameServerFeature;

final class GameServerFeatureSettings
{
    public const CHARACTER_RESCUE = 'character_rescue';

    /** Extra players added to the online count that website visitors see. */
    public const ONLINE_BOOST = 'online_boost';

    public const ONLINE_BOOST_MODES = ['range', 'percent'];

    public const ONLINE_BOOST_MAX_PLAYERS = 100000;

    public const ONLINE_BOOST_MAX_PERCENT = 1000;

    /** @return array{enabled:bool,location_name:string,x:int,y:int,z:int,offline_delay_minutes:int,cooldown_hours:int} */
    public function characterRescue(GameServer $server): array
    {
        $feature = $server->relationLoaded('features')
            ? $server->features->firstWhere('feature_key', self::CHARACTER_RESCUE)
            : GameServerFeature::query()
                ->where('game_server_id', $server->id)
                ->where('feature_key', self::CHARACTER_RESCUE)
                ->first();

        $settings = is_array($feature?->settings) ? $feature->settings : [];

        return [
            'enabled' => (bool) ($feature->enabled ?? false),
            'location_name' => $this->string($settings['location_name'] ?? null, 'Giran', 100),
            'x' => $this->integer($settings['x'] ?? null, 83400),
            'y' => $this->integer($settings['y'] ?? null, 148600),
            'z' => $this->integer($settings['z'] ?? null, -3400),
            'offline_delay_minutes' => $this->boundedInteger($settings['offline_delay_minutes'] ?? null, 5, 0, 1440),
            'cooldown_hours' => $this->boundedInteger($settings['cooldown_hours'] ?? null, 12, 0, 720),
        ];
    }

    /**
     * @param  array{enabled:bool,location_name:string,x:int,y:int,z:int,offline_delay_minutes:int,cooldown_hours:int}  $values
     */
    public function updateCharacterRescue(GameServer $server, array $values): GameServerFeature
    {
        return GameServerFeature::query()->updateOrCreate(
            [
                'game_server_id' => $server->id,
                'feature_key' => self::CHARACTER_RESCUE,
            ],
            [
                'enabled' => $values['enabled'],
                'settings' => [
                    'location_name' => trim($values['location_name']),
                    'x' => $values['x'],
                    'y' => $values['y'],
                    'z' => $values['z'],
                    'offline_delay_minutes' => $values['offline_delay_minutes'],
                    'cooldown_hours' => $values['cooldown_hours'],
                ],
            ],
        );
    }

    /** @return array{enabled:bool,mode:string,min:int,max:int,percent:int} */
    public function onlineBoost(GameServer $server): array
    {
        $feature = $server->relationLoaded('features')
            ? $server->features->firstWhere('feature_key', self::ONLINE_BOOST)
            : GameServerFeature::query()
                ->where('game_server_id', $server->id)
                ->where('feature_key', self::ONLINE_BOOST)
                ->first();

        $settings = is_array($feature?->settings) ? $feature->settings : [];
        $mode = in_array($settings['mode'] ?? null, self::ONLINE_BOOST_MODES, true) ? (string) $settings['mode'] : 'range';
        $min = $this->boundedInteger($settings['min'] ?? null, 0, 0, self::ONLINE_BOOST_MAX_PLAYERS);

        return [
            'enabled' => (bool) ($feature->enabled ?? false),
            'mode' => $mode,
            'min' => $min,
            'max' => $this->boundedInteger($settings['max'] ?? null, $min, $min, self::ONLINE_BOOST_MAX_PLAYERS),
            'percent' => $this->boundedInteger($settings['percent'] ?? null, 0, 0, self::ONLINE_BOOST_MAX_PERCENT),
        ];
    }

    /** @param array{enabled:bool,mode:string,min:int,max:int,percent:int} $values */
    public function updateOnlineBoost(GameServer $server, array $values): GameServerFeature
    {
        return GameServerFeature::query()->updateOrCreate(
            [
                'game_server_id' => $server->id,
                'feature_key' => self::ONLINE_BOOST,
            ],
            [
                'enabled' => $values['enabled'],
                'settings' => [
                    'mode' => $values['mode'],
                    'min' => $values['min'],
                    'max' => $values['max'],
                    'percent' => $values['percent'],
                ],
            ],
        );
    }

    private function string(mixed $value, string $default, int $maxLength): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' ? mb_substr($value, 0, $maxLength) : $default;
    }

    private function integer(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private function boundedInteger(mixed $value, int $default, int $minimum, int $maximum): int
    {
        return min($maximum, max($minimum, $this->integer($value, $default)));
    }
}
