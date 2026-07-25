<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $version
 * @property bool $enabled
 * @property Carbon|null $enabled_at
 * @property Carbon|null $disabled_at
 * @property string|null $last_error
 * @property Carbon|null $last_error_at
 * @property string|null $migration_error
 * @property Carbon|null $migration_error_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ModuleState extends Model
{
    protected $table = 'cms_modules';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'version',
        'enabled',
        'enabled_at',
        'disabled_at',
        'last_error',
        'last_error_at',
        'migration_error',
        'migration_error_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
            'last_error_at' => 'datetime',
            'migration_error_at' => 'datetime',
        ];
    }
}
