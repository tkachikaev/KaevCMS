<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $module_id
 * @property string $migration
 * @property string $checksum
 * @property int $batch
 * @property Carbon|null $ran_at
 */
class ModuleMigration extends Model
{
    protected $table = 'cms_module_migrations';

    public $timestamps = false;

    protected $fillable = [
        'module_id',
        'migration',
        'checksum',
        'batch',
        'ran_at',
    ];

    protected function casts(): array
    {
        return [
            'batch' => 'integer',
            'ran_at' => 'datetime',
        ];
    }
}
