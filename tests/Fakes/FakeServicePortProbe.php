<?php

namespace Tests\Fakes;

use App\Contracts\ServicePortProbe;

final class FakeServicePortProbe implements ServicePortProbe
{
    /** @var array<string,list<bool>|bool> */
    public array $responses = [];

    public bool $default = false;

    public function isOpen(string $host, int $port, float $timeoutSeconds): bool
    {
        $key = $host.':'.$port;
        $response = $this->responses[$key] ?? $this->default;

        if (is_array($response)) {
            $value = array_shift($response);
            $this->responses[$key] = $response;

            return (bool) $value;
        }

        return (bool) $response;
    }
}
