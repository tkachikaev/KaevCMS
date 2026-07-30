<?php

namespace App\Support\Modules;

use Livewire\Component;

abstract class ModuleAdminComponent extends Component
{
    use AuthorizesModuleAdminAccess;
}
