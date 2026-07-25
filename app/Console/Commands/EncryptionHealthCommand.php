<?php

namespace App\Console\Commands;

use App\Services\Security\EncryptionHealth;
use Illuminate\Console\Command;

final class EncryptionHealthCommand extends Command
{
    protected $signature = 'kaevcms:encryption-health';

    protected $description = 'Check that values protected by APP_KEY can still be decrypted';

    public function handle(EncryptionHealth $health): int
    {
        $result = $health->inspect();

        foreach ($result['categories'] as $category) {
            $this->line(sprintf(
                '%s: %d saved, %d unavailable',
                $category['label'],
                $category['saved'],
                $category['invalid'],
            ));
        }

        if ($result['state'] === 'danger') {
            $this->error($result['details']);

            return self::FAILURE;
        }

        $this->info($result['details']);

        return self::SUCCESS;
    }
}
