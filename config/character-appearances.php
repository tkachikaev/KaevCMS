<?php

return [
    /*
     * Player race values used by L2J Mobius character tables. Each race lists
     * the avatar variants that KaevCMS may select. Races with one visual branch
     * deliberately use only "default" and do not guess between warrior/mage.
     */
    'races' => [
        0 => ['key' => 'human', 'archetypes' => ['warrior', 'mage']],
        1 => ['key' => 'elf', 'archetypes' => ['warrior', 'mage']],
        2 => ['key' => 'dark_elf', 'archetypes' => ['warrior', 'mage']],
        3 => ['key' => 'orc', 'archetypes' => ['warrior', 'mage']],
        4 => ['key' => 'dwarf', 'archetypes' => ['default']],
        5 => ['key' => 'kamael', 'archetypes' => ['default']],
        6 => ['key' => 'ertheia', 'archetypes' => ['warrior', 'mage']],
        7 => ['key' => 'sylph', 'archetypes' => ['default']],
    ],

    'genders' => [
        0 => 'male',
        1 => 'female',
    ],

    /*
     * Only the broad visual archetype is stored here. Unknown class IDs are
     * resolved to "default" instead of receiving a potentially wrong avatar.
     */
    'class_archetypes' => [
        // Human.
        0 => 'warrior', 1 => 'warrior', 2 => 'warrior', 3 => 'warrior',
        4 => 'warrior', 5 => 'warrior', 6 => 'warrior', 7 => 'warrior',
        8 => 'warrior', 9 => 'warrior', 10 => 'mage', 11 => 'mage',
        12 => 'mage', 13 => 'mage', 14 => 'mage', 15 => 'mage',
        16 => 'mage', 17 => 'mage',

        // Elf.
        18 => 'warrior', 19 => 'warrior', 20 => 'warrior', 21 => 'warrior',
        22 => 'warrior', 23 => 'warrior', 24 => 'warrior', 25 => 'mage',
        26 => 'mage', 27 => 'mage', 28 => 'mage', 29 => 'mage', 30 => 'mage',

        // Dark Elf.
        31 => 'warrior', 32 => 'warrior', 33 => 'warrior', 34 => 'warrior',
        35 => 'warrior', 36 => 'warrior', 37 => 'warrior', 38 => 'mage',
        39 => 'mage', 40 => 'mage', 41 => 'mage', 42 => 'mage', 43 => 'mage',

        // Orc.
        44 => 'warrior', 45 => 'warrior', 46 => 'warrior', 47 => 'warrior',
        48 => 'warrior', 49 => 'mage', 50 => 'mage', 51 => 'mage', 52 => 'mage',

        // Dwarf. The race definition still collapses these to default.
        53 => 'warrior', 54 => 'warrior', 55 => 'warrior', 56 => 'warrior', 57 => 'warrior',

        // Third professions.
        88 => 'warrior', 89 => 'warrior', 90 => 'warrior', 91 => 'warrior',
        92 => 'warrior', 93 => 'warrior', 94 => 'mage', 95 => 'mage',
        96 => 'mage', 97 => 'mage', 98 => 'mage', 99 => 'warrior',
        100 => 'warrior', 101 => 'warrior', 102 => 'warrior', 103 => 'mage',
        104 => 'mage', 105 => 'mage', 106 => 'warrior', 107 => 'warrior',
        108 => 'warrior', 109 => 'warrior', 110 => 'mage', 111 => 'mage',
        112 => 'mage', 113 => 'warrior', 114 => 'warrior', 115 => 'mage',
        116 => 'mage', 117 => 'warrior', 118 => 'warrior',

        // Kamael. The race definition collapses all of them to default.
        123 => 'warrior', 124 => 'warrior', 125 => 'warrior', 126 => 'warrior',
        127 => 'warrior', 128 => 'warrior', 129 => 'warrior', 130 => 'warrior',
        131 => 'warrior', 132 => 'warrior', 133 => 'warrior', 134 => 'warrior',
        135 => 'warrior', 136 => 'warrior',

        // Awakening base classes.
        139 => 'warrior', 140 => 'warrior', 141 => 'warrior', 142 => 'warrior',
        143 => 'mage', 144 => 'mage', 145 => 'mage', 146 => 'mage',

        // Awakening branches.
        148 => 'warrior', 149 => 'warrior', 150 => 'warrior', 151 => 'warrior',
        152 => 'warrior', 153 => 'warrior', 154 => 'warrior', 155 => 'warrior',
        156 => 'warrior', 157 => 'warrior', 158 => 'warrior', 159 => 'warrior',
        160 => 'warrior', 161 => 'warrior', 162 => 'warrior', 163 => 'warrior',
        164 => 'warrior', 165 => 'warrior', 166 => 'mage', 167 => 'mage',
        168 => 'mage', 169 => 'mage', 170 => 'warrior', 171 => 'mage',
        172 => 'warrior', 173 => 'warrior', 174 => 'mage', 175 => 'mage',
        176 => 'mage', 177 => 'mage', 178 => 'mage', 179 => 'mage',
        180 => 'mage', 181 => 'mage',

        // Ertheia.
        182 => 'warrior', 183 => 'mage', 184 => 'warrior', 185 => 'mage',
        186 => 'warrior', 187 => 'mage', 188 => 'warrior', 189 => 'mage',
    ],
];
