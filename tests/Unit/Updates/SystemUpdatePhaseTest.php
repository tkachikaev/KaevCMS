<?php

namespace Tests\Unit\Updates;

use App\Models\SystemUpdate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SystemUpdatePhaseTest extends TestCase
{
    #[DataProvider('phaseRequirements')]
    public function test_phase_controls_required_recovery_backups(
        ?string $phase,
        bool $migrate,
        bool $filesRequired,
        bool $databaseRequired,
    ): void {
        $update = new SystemUpdate([
            'phase' => $phase,
            'manifest' => ['migrate' => $migrate],
        ]);

        $this->assertSame($filesRequired, $update->filesMayHaveChanged());
        $this->assertSame($databaseRequired, $update->databaseMayHaveChanged());
    }

    /** @return array<string, array{string|null, bool, bool, bool}> */
    public static function phaseRequirements(): array
    {
        return [
            'preparing' => [SystemUpdate::PHASE_PREPARING, true, false, false],
            'files' => [SystemUpdate::PHASE_FILES, true, true, false],
            'migrations' => [SystemUpdate::PHASE_MIGRATIONS, true, true, true],
            'finalizing with migrations' => [SystemUpdate::PHASE_FINALIZING, true, true, true],
            'finalizing without migrations' => [SystemUpdate::PHASE_FINALIZING, false, true, false],
            'legacy unknown state' => [null, true, true, true],
        ];
    }
}
