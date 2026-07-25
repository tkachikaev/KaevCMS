<?php

namespace App\Console\Commands;

use App\Services\Admin\AdminPathSettings;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AdminPathCommand extends Command
{
    protected $signature = 'kaevcms:admin-path
        {suffix? : New suffix after the fixed admin- prefix}
        {--reset : Reset the administrator panel address to /admin}';

    protected $description = 'Show, change, or reset the KaevCMS administrator panel address';

    public function handle(AdminPathSettings $settings): int
    {
        $suffix = $this->argument('suffix');
        $reset = (bool) $this->option('reset');

        if ($reset && is_string($suffix) && $suffix !== '') {
            $this->error('Use either a suffix or --reset, not both.');

            return self::INVALID;
        }

        if ($reset) {
            $settings->reset();
            $this->info('Administrator panel address reset.');
            $this->line('Current address: '.$settings->displayPath());

            return self::SUCCESS;
        }

        if (is_string($suffix) && $suffix !== '') {
            try {
                $settings->updateSuffix($suffix);
            } catch (InvalidArgumentException $exception) {
                $this->error($exception->getMessage());

                return self::INVALID;
            }

            $this->info('Administrator panel address changed.');
            $this->line('Current address: '.$settings->displayPath());

            return self::SUCCESS;
        }

        $this->line('Current administrator panel address: '.$settings->displayPath());
        $this->newLine();
        $this->comment('Reset command: php artisan kaevcms:admin-path --reset');

        return self::SUCCESS;
    }
}
