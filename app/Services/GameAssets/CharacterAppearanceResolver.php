<?php

namespace App\Services\GameAssets;

use App\Models\GameServer;

final class CharacterAppearanceResolver
{
    public function __construct(private readonly GameAssetUrlResolver $assets) {}

    /**
     * @return array{
     *     race_key:string,
     *     gender_key:string,
     *     archetype:string,
     *     avatar_key:string,
     *     avatar_url:?string
     * }
     */
    public function resolve(GameServer|int|null $server, int $race, int $gender, int $classId): array
    {
        $raceDefinition = $this->raceDefinition($race);
        $raceKey = $raceDefinition['key'];
        $genderKey = $this->genderKey($gender);
        $archetype = $this->archetype($raceDefinition['archetypes'], $classId);
        $candidates = $this->candidateKeys($raceKey, $genderKey, $archetype);

        return [
            'race_key' => $raceKey,
            'gender_key' => $genderKey,
            'archetype' => $archetype,
            'avatar_key' => $candidates[0],
            'avatar_url' => $this->assets->firstCharacterAvatar($server, $candidates),
        ];
    }

    /** @return array{key:string,archetypes:list<string>} */
    private function raceDefinition(int $race): array
    {
        $definitions = config('character-appearances.races', []);
        $definition = is_array($definitions) ? ($definitions[$race] ?? null) : null;
        if (! is_array($definition)) {
            return ['key' => 'unknown', 'archetypes' => ['default']];
        }

        $key = $this->safeSegment($definition['key'] ?? null) ?? 'unknown';
        $archetypes = [];
        $configuredArchetypes = is_array($definition['archetypes'] ?? null)
            ? $definition['archetypes']
            : [];
        foreach ($configuredArchetypes as $value) {
            if (is_string($value) && in_array($value, ['warrior', 'mage', 'default'], true)) {
                $archetypes[] = $value;
            }
        }
        $archetypes = array_values(array_unique($archetypes));

        return [
            'key' => $key,
            'archetypes' => $archetypes !== [] ? $archetypes : ['default'],
        ];
    }

    private function genderKey(int $gender): string
    {
        $genders = config('character-appearances.genders', []);
        $key = is_array($genders) ? ($genders[$gender] ?? null) : null;

        return $this->safeSegment($key) ?? 'neutral';
    }

    /** @param list<string> $supported */
    private function archetype(array $supported, int $classId): string
    {
        if (count($supported) === 1) {
            return $supported[0];
        }

        $mapping = config('character-appearances.class_archetypes', []);
        $configured = is_array($mapping) ? ($mapping[$classId] ?? null) : null;

        return is_string($configured) && in_array($configured, $supported, true)
            ? $configured
            : 'default';
    }

    /** @return list<string> */
    private function candidateKeys(string $race, string $gender, string $archetype): array
    {
        $keys = ["{$race}/{$gender}/{$archetype}"];

        if ($archetype !== 'default') {
            $keys[] = "{$race}/{$gender}/default";
        }

        if ($gender !== 'neutral') {
            $keys[] = "{$race}/neutral/{$archetype}";
            if ($archetype !== 'default') {
                $keys[] = "{$race}/neutral/default";
            }
        }

        $keys[] = "fallback/{$gender}/{$archetype}";
        if ($archetype !== 'default') {
            $keys[] = "fallback/{$gender}/default";
        }
        $keys[] = 'fallback/neutral/default';

        return array_values(array_unique($keys));
    }

    private function safeSegment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/\A[a-z0-9][a-z0-9_-]{0,62}\z/D', $value) === 1 ? $value : null;
    }
}
