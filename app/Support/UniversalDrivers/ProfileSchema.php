<?php

namespace App\Support\UniversalDrivers;

use Illuminate\Database\Connection;

/** Shared schema checks for connection diagnostics and game readers. */
final class ProfileSchema
{
    /** @param list<array<string,mixed>> $requirements
     * @return array{columns:array<string,list<string>>,missing_required:list<string>,missing_optional:list<string>}
     */
    public static function inspect(Connection $database, array $requirements): array
    {
        $inspector = $database->getSchemaBuilder();
        $columns = [];
        $required = [];
        $optional = [];
        foreach ($requirements as $rule) {
            $table = $rule['table'];
            $available = $inspector->hasTable($table) ? $inspector->getColumnListing($table) : null;
            $columns[$table] = $available ?? [];
            $check = self::checkTable($rule, $available);
            $missing = [];
            if ($available === null) {
                $missing[] = 'table:'.$table;
            } else {
                $missingColumns = $check['missing_columns'];
                $any = $rule['any_columns'] ?? [];
                $missingChoice = $any !== [] && $check['matched_any_columns'] === [];
                if ($missingChoice) {
                    array_pop($missingColumns);
                }
                foreach ($missingColumns as $column) {
                    $missing[] = $table.'.'.$column;
                }
                foreach (array_diff($rule['optional_columns'] ?? [], $check['matched_optional_columns']) as $column) {
                    $optional[] = $table.'.'.$column;
                }
                if ($missingChoice) {
                    $missing[] = $table.'.{'.implode(',', $any).'}';
                }
            }
            if ($rule['required']) {
                array_push($required, ...$missing);
            } else {
                array_push($optional, ...$missing);
            }
        }

        return ['columns' => $columns, 'missing_required' => $required, 'missing_optional' => $optional];
    }

    /** @param array<string,mixed> $rule
     * @param  list<string>|null  $columns
     * @return array{table:string,required:bool,table_exists:bool,missing_columns:list<string>,matched_any_columns:list<string>,matched_optional_columns:list<string>}
     */
    public static function checkTable(array $rule, ?array $columns): array
    {
        $available = $columns ?? [];
        $any = $rule['any_columns'] ?? [];
        $matchedAny = self::matched($available, $any);
        $missing = $columns === null ? [] : array_values(array_diff($rule['columns'], self::matched($available, $rule['columns'])));
        if ($columns !== null && $any !== [] && $matchedAny === []) {
            $missing[] = implode(' / ', $any);
        }

        return [
            'table' => $rule['table'],
            'required' => $rule['required'],
            'table_exists' => $columns !== null,
            'missing_columns' => $missing,
            'matched_any_columns' => $matchedAny,
            'matched_optional_columns' => self::matched($available, $rule['optional_columns'] ?? []),
        ];
    }

    /** @param list<string> $available
     * @param  list<string>  $wanted
     * @return list<string>
     */
    private static function matched(array $available, array $wanted): array
    {
        $available = array_map('strtolower', $available);

        return array_values(array_filter($wanted, static fn (string $column): bool => in_array(strtolower($column), $available, true)));
    }

    /** @param list<string> $available
     * @param  list<string>  $wanted
     */
    public static function hasAll(array $available, array $wanted): bool
    {
        return count(self::matched($available, $wanted)) === count($wanted);
    }

    /** @param array<string,mixed> $field
     * @param  list<string>  $columns
     */
    public static function chosenColumn(array $field, array $columns, ?string $chronicle): ?string
    {
        $present = self::matched($columns, $field['columns']);
        if ($present === []) {
            return null;
        }
        if ($field['preference'] === 'mobius_chronicle' && count($present) > 1) {
            $normalized = mb_strtolower(trim((string) $chronicle));
            foreach (['c1', 'c4', 'interlude', 'epilogue', 'high five', 'highfive'] as $legacy) {
                if ($normalized !== '' && str_contains($normalized, $legacy)) {
                    return in_array('karma', $present, true) ? 'karma' : $present[0];
                }
            }

            return in_array('reputation', $present, true) ? 'reputation' : $present[0];
        }

        return $present[0];
    }
}
