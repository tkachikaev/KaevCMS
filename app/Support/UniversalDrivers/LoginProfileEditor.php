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
        // Preserve the administrator's deliberately selected read/write features.
        $config['capabilities'] = array_values(array_intersect(
            ProfileFormat::LOGIN_CAPABILITIES, $config['capabilities'],
        ));
        if ($config['capabilities'] === []) {
            throw new ProfileValidationException('drivers.login_choose_capability');
        }
        $accounts = &$config['accounts'];
        $identity = $accounts['ownership']['identity'];
        $accounts['ownership']['column'] = $accounts['fields'][$identity] ?? '';
        $writes = in_array('account_creation', $config['capabilities'], true)
            || in_array('password_change', $config['capabilities'], true);
        $required = array_filter([
            $accounts['fields']['login'],
            $writes ? ($accounts['fields']['password'] ?? null) : null,
            $identity === 'id' ? ($accounts['fields']['id'] ?? null) : null,
        ], 'is_string');
        if (in_array('account_creation', $config['capabilities'], true)) {
            $required = array_merge($required, array_keys($accounts['create']['columns'] ?? []), array_keys($accounts['create']['fixed_values'] ?? []));
        }
        $mapped = array_values(array_unique(array_filter(
            array_merge(array_values($accounts['fields']), array_keys($accounts['create']['fixed_values'] ?? [])),
            static fn ($column) => is_string($column) && $column !== '',
        )));
        $required = array_values(array_unique($required));
        $optional = array_values(array_diff($mapped, $required));
        $primary = ['table' => $accounts['table'], 'columns' => $required, 'required' => true];
        if ($optional !== []) {
            $primary['optional_columns'] = $optional;
        }
        $otherRequirements = array_filter(
            $config['requirements'],
            static fn ($requirement) => ! in_array($requirement['table'], [$previousTable, $accounts['table']], true),
        );
        $config['requirements'] = [$primary, ...array_values($otherRequirements)];

        return $profile;
    }
}
