<?php

namespace Tests\Fakes;

use App\Contracts\ExternalDatabaseConnectionTester;

class FakeExternalDatabaseConnectionTester implements ExternalDatabaseConnectionTester
{
    /** @var array{host:string,port:int,database:string,username:string,password:string,charset:string}|null */
    public ?array $connection = null;

    /** @var list<array{table:string,columns:list<string>,required:bool}> */
    public array $requirements = [];

    public ?bool $driverReady = null;

    /** @var array{connected:bool,compatible:bool|null,server_version:string|null,error:string|null,checks:list<array{table:string,required:bool,table_exists:bool,missing_columns:list<string>}>} */
    public array $report = [
        'connected' => true,
        'compatible' => true,
        'server_version' => '10.4.32-MariaDB',
        'error' => null,
        'checks' => [],
    ];

    public function test(array $connection, array $requirements, bool $driverReady): array
    {
        $this->connection = $connection;
        $this->requirements = $requirements;
        $this->driverReady = $driverReady;

        return $this->report;
    }
}
