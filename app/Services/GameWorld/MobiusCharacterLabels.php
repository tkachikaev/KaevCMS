<?php

namespace App\Services\GameWorld;

use App\Services\GameAccounts\MobiusClassNames;

final class MobiusCharacterLabels
{
    public function __construct(private readonly MobiusClassNames $classes) {}

    public function className(int $classId): string
    {
        return $this->classes->name($classId);
    }

    public function raceName(int $race): string
    {
        return match ($race) {
            0 => __('Human'),
            1 => __('Elf'),
            2 => __('Dark Elf'),
            3 => __('Orc'),
            4 => __('Dwarf'),
            5 => __('Kamael'),
            6 => __('Ertheia'),
            7 => __('Sylph'),
            default => __('Unknown race'),
        };
    }

    public function genderName(int $gender): string
    {
        return match ($gender) {
            0 => __('Male'),
            1 => __('Female'),
            default => __('Unknown gender'),
        };
    }
}
