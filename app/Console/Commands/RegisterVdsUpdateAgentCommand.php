<?php

namespace App\Console\Commands;

use App\Services\Updates\VdsUpdateAgent;
use Illuminate\Console\Command;
use Throwable;

final class RegisterVdsUpdateAgentCommand extends Command
{
    protected $signature = 'kaevcms:update-agent:register
        {--service-name= : Installed systemd service unit name}
        {--path-unit= : Installed systemd path unit name}
        {--php-binary= : Absolute PHP CLI binary path}
        {--installed-at= : Installation timestamp in ISO 8601 format}';

    protected $description = 'Register the local VDS update agent for this KaevCMS installation';

    public function handle(VdsUpdateAgent $agent): int
    {
        try {
            $agent->register([
                'service_name' => $this->optionString('service-name'),
                'path_unit' => $this->optionString('path-unit'),
                'php_binary' => $this->optionString('php-binary'),
                'installed_at' => $this->optionString('installed-at'),
            ]);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('KaevCMS VDS update agent registered.');

        return self::SUCCESS;
    }

    private function optionString(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
