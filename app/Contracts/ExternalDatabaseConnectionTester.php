<?php

namespace App\Contracts;

interface ExternalDatabaseConnectionTester
{
    /**
     * @param  array{host:string,port:int,database:string,username:string,password:string,charset:string}  $connection
     * @param  list<array{table:string,columns:list<string>,any_columns?:list<string>,required:bool}>  $requirements
     * @return array{
     *     connected:bool,
     *     compatible:bool|null,
     *     server_version:string|null,
     *     error:string|null,
     *     checks:list<array{table:string,required:bool,table_exists:bool,missing_columns:list<string>}>
     * }
     */
    public function test(array $connection, array $requirements, bool $driverReady): array;
}
