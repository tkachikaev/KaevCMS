<?php

namespace App\Support\UniversalDrivers;

use Illuminate\Database\Query\Builder;

/** Applies validated filter data without multiplying characters through joins. */
final class GameCharacterFilters
{
    /** @param array<string,mixed> $config
     * @return array<string,list<string>>
     */
    public static function requirements(array $config): array
    {
        $base = $config['characters']['table'];
        $required = [];
        foreach ($config['filters'] as $filter) {
            $table = $filter['table'] ?? $base;
            $required[$table][] = $filter['column'];
            if ($table !== $base) {
                $required[$base][] = $filter['link']['local_column'];
                $required[$table][] = $filter['link']['foreign_column'];
            }
        }

        return $required;
    }

    /** @param array<string,mixed> $config
     * @param  array<string,list<string>>  $columns
     */
    public static function apply(Builder $query, array $config, array $columns, string $baseReference): void
    {
        foreach (self::requirements($config) as $table => $required) {
            foreach ($required as $column) {
                if (! in_array($column, $columns[$table] ?? [], true)) {
                    throw new ProfileValidationException('drivers.filter_schema_missing');
                }
            }
        }
        $base = $config['characters']['table'];
        $groups = [];
        foreach ($config['filters'] as $filter) {
            $table = $filter['table'] ?? $base;
            if ($table === $base) {
                self::condition($query, $baseReference.'.'.$filter['column'], $filter);
            } else {
                $key = $table.'|'.$filter['link']['local_column'].'|'.$filter['link']['foreign_column'];
                $groups[$key][] = $filter;
            }
        }
        // Conditions on the same source must match the same related row (also useful for EAV).
        foreach (array_values($groups) as $index => $filters) {
            $source = $filters[0];
            $alias = 'ud_filter_'.$index;
            while ($alias === $baseReference) {
                $alias .= '_sub';
            }
            $query->whereExists(static function (Builder $related) use ($source, $filters, $alias, $baseReference): void {
                $related->selectRaw('1')->from($source['table'].' as '.$alias)
                    ->whereColumn($alias.'.'.$source['link']['foreign_column'], $baseReference.'.'.$source['link']['local_column']);
                foreach ($filters as $filter) {
                    self::condition($related, $alias.'.'.$filter['column'], $filter);
                }
            });
        }
    }

    /** @param array<string,mixed> $filter */
    private static function condition(Builder $query, string $column, array $filter): void
    {
        match ($filter['op']) {
            'eq' => $query->where($column, '=', $filter['value']),
            'gt' => $query->where($column, '>', $filter['value']),
            'zero_or_null' => $query->where(static fn (Builder $nested): Builder => $nested->whereNull($column)->orWhere($column, 0)),
            default => throw new ProfileValidationException('drivers.filter_invalid_condition'),
        };
    }
}
