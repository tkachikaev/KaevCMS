<?php

namespace App\Support\Modules;

enum ModuleAdminAccessLevel: string
{
    case Denied = 'denied';
    case Read = 'read';
    case Manage = 'manage';
}
