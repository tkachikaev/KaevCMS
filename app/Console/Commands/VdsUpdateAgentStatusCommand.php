<?php

namespace App\Console\Commands;

use App\Services\Updates\VdsUpdateAgent;
use Illuminate\Console\Command;

final class VdsUpdateAgentStatusCommand extends Command
{
    protected $signature = 'kaevcms:update-agent:status {--json : Print machine-readable JSON}';

    protected $description = 'Show the local VDS update agent status';

    public function handle(VdsUpdateAgent $agent): int
    {
        $status = $agent->status();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'state' => $status->state,
                'ready' => $status->isReady(),
                'message' => $status->message,
                'metadata' => $status->metadata,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('State: '.$status->state);
            $this->line('Ready: '.($status->isReady() ? 'yes' : 'no'));
            $this->line('Message: '.$status->message);
            $this->line('Requests: '.$agent->requestDirectory());
        }

        return $status->isReady() ? self::SUCCESS : self::FAILURE;
    }
}
