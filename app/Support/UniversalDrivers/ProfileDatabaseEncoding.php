<?php

namespace App\Support\UniversalDrivers;

/** Keep verification and runtime sessions on the encoding in the checked profile. */
final class ProfileDatabaseEncoding
{
    /**
     * @param  array{host:string,port:int,database:string,username:string,password:string,charset:string,collation?:string}  $connection
     * @param  array<string,mixed>|null  $profile
     * @return array{host:string,port:int,database:string,username:string,password:string,charset:string,collation?:string}
     */
    public static function apply(array $connection, ?array $profile): array
    {
        if ($profile === null) {
            return $connection;
        }

        (new ProfileValidator)->validate($profile);
        $connection['charset'] = (string) $profile['config']['database']['charset'];
        $connection['collation'] = (string) $profile['config']['database']['collation'];

        return $connection;
    }
}
