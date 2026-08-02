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
        {--deployment-user= : Project owner and systemd service account}
        {--deployment-uid= : Numeric project owner user id}
        {--web-group= : PHP-FPM/nginx group}
        {--web-gid= : Numeric PHP-FPM/nginx group id}
        {--installed-at= : Installation timestamp in ISO 8601 format}';

    protected $description = 'Register the local VDS update agent for this KaevCMS installation';

    public function handle(VdsUpdateAgent $agent): int
    {
        try {
            $agent->register([
                'service_name' => $this->optionString('service-name'),
                'path_unit' => $this->optionString('path-unit'),
                'php_binary' => $this->optionString('php-binary'),
                'deployment_user' => $this->optionString('deployment-user'),
                'deployment_uid' => $this->optionInteger('deployment-uid'),
                'web_group' => $this->optionString('web-group'),
                'web_gid' => $this->optionInteger('web-gid'),
                'installed_at' => $this->optionString('installed-at'),
            ]);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('KaevCMS VDS update agent registered.');

        return self::SUCCESS;
    }

    private function optionInteger(string $name): ?int
    {
        $value = $this->option($name);
        if (! is_string($value) || preg_match('/\A\d+\z/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function optionString(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
