<?php

namespace App\Contracts;

interface ServicePortProbe
{
    public function isOpen(string $host, int $port, float $timeoutSeconds): bool;
}
