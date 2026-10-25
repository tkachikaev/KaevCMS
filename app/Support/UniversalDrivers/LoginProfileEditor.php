<?php

namespace App\Support\UniversalDrivers;

/** Builds the LoginServer schema contract from the fields shown in the editor. */
final class LoginProfileEditor
{
    /** @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    public function prepare(array $profile, string $previousTable): array
    {
        $config = &$profile['config'];
        $config['capabilities'] = ProfileFormat::LOGIN_CAPABILITIES;
        $accounts = &$config['accounts'];
        $identity = $accounts['ownership']['identity'];
        $accounts['ownership']['column'] = $accounts['fields'][$identity] ?? '';

        $columns = array_values(array_unique(array_filter(
            array_merge(array_values($accounts['fields']), array_keys($accounts['create']['fixed_values'] ?? [])),
            static fn ($column) => is_string($column) && $column !== '',
        )));
        $primary = ['table' => $accounts['table'], 'columns' => $columns, 'required' => true];
        $otherRequirements = array_filter(
            $config['requirements'],
            static fn ($requirement) => ! in_array($requirement['table'], [$previousTable, $accounts['table']], true),
        );
        $config['requirements'] = [$primary, ...array_values($otherRequirements)];

        return $profile;
    }
}
