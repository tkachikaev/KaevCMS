<?php

namespace Tests\Fakes;

use App\Contracts\ExternalDatabaseConnectionTester;

class FakeExternalDatabaseConnectionTester implements ExternalDatabaseConnectionTester
{
    public int $calls = 0;

    /** @var list<array{connection:array<string,mixed>,requirements:list<array{table:string,columns:list<string>,any_columns?:list<string>,required:bool}>,driver_ready:bool}> */
    public array $callLog = [];

    /** @var array{host:string,port:int,database:string,username:string,password:string,charset:string}|null */
    public ?array $connection = null;

    /** @var list<array{table:string,columns:list<string>,any_columns?:list<string>,required:bool}> */
    public array $requirements = [];

    public ?bool $driverReady = null;

    /** @var list<array<string,mixed>> */
    public array $reports = [];

    /** @var array<string,mixed> */
    public array $report = [
        'connected' => true,
        'compatible' => true,
        'server_version' => '10.4.32-MariaDB',
        'error' => null,
        'error_class' => null,
        'latency_ms' => 12,
        'checks' => [],
    ];

    public function test(array $connection, array $requirements, bool $driverReady): array
    {
        $this->calls++;
        $this->callLog[] = [
            'connection' => $connection,
            'requirements' => $requirements,
            'driver_ready' => $driverReady,
        ];
        $this->connection = $connection;
        $this->requirements = $requirements;
        $this->driverReady = $driverReady;

        if ($this->reports !== []) {
            $report = array_shift($this->reports);

            return is_array($report) ? $report : $this->report;
        }

        return $this->report;
    }
}
