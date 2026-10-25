<?php

namespace App\Support\UniversalDrivers;

/** Standard Lineage II castle names. More IDs can be added without changing any game profile. */
final class CastleNameCatalog
{
    private const NAMES = [
        1 => 'Gludio Castle',
        2 => 'Dion Castle',
        3 => 'Giran Castle',
        4 => 'Oren Castle',
        5 => 'Aden Castle',
        6 => 'Innadril Castle',
        7 => 'Goddard Castle',
        8 => 'Rune Castle',
        9 => 'Schuttgart Castle',
    ];

    public function name(int $castleId): string
    {
        return __(self::NAMES[$castleId] ?? 'Castle #:id', isset(self::NAMES[$castleId]) ? [] : ['id' => $castleId]);
    }
}
