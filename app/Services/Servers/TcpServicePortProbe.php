<?php

namespace App\Services\Servers;

use App\Contracts\ServicePortProbe;

final class TcpServicePortProbe implements ServicePortProbe
{
    public function isOpen(string $host, int $port, float $timeoutSeconds): bool
    {
        $errorCode = 0;
        $errorMessage = '';
        $socket = @fsockopen(
            $host,
            $port,
            $errorCode,
            $errorMessage,
            max(0.2, min(10.0, $timeoutSeconds)),
        );

        if (! is_resource($socket)) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
