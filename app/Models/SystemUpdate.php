<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $admin_id
 * @property string $package_id
 * @property string $from_version
 * @property string $target_version
 * @property string $status
 * @property string|null $phase
 * @property string $installation_type
 * @property string $execution_mode
 * @property string $package_path
 * @property string|null $package_sha256
 * @property string|null $maintenance_secret
 * @property string|null $backup_path
 * @property string|null $log_path
 * @property int $file_count
 * @property int $delete_count
 * @property array<string, mixed> $manifest
 * @property string|null $error_summary
 * @property Carbon|null $started_at
 * @property Carbon|null $agent_requested_at
 * @property Carbon|null $agent_seen_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SystemUpdate extends Model
{
    use HasFactory;

    public const STATUS_STAGED = 'staged';

    public const STATUS_APPLYING = 'applying';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DISCARDED = 'discarded';

    public const PHASE_PREPARING = 'preparing';

    public const PHASE_FILES = 'files';

    public const PHASE_MIGRATIONS = 'migrations';

    public const PHASE_FINALIZING = 'finalizing';

    public const PHASE_COMPLETED = 'completed';

    public const EXECUTION_WEB = 'web';

    public const EXECUTION_VDS_AGENT = 'vds_agent';

    protected $fillable = [
        'uuid',
        'admin_id',
        'package_id',
        'from_version',
        'target_version',
        'status',
        'phase',
        'installation_type',
        'execution_mode',
        'package_path',
        'package_sha256',
        'maintenance_secret',
        'backup_path',
        'log_path',
        'file_count',
        'delete_count',
        'manifest',
        'error_summary',
        'started_at',
        'agent_requested_at',
        'agent_seen_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'file_count' => 'integer',
            'delete_count' => 'integer',
            'started_at' => 'datetime',
            'agent_requested_at' => 'datetime',
            'agent_seen_at' => 'datetime',
            'maintenance_secret' => 'encrypted',
            'completed_at' => 'datetime',
        ];
    }

    public function administrator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function isStaged(): bool
    {
        return $this->status === self::STATUS_STAGED;
    }

    public function isQueuedForAgent(): bool
    {
        return $this->isStaged()
            && $this->execution_mode === self::EXECUTION_VDS_AGENT
            && $this->agent_requested_at !== null;
    }

    public function isAgentExecution(): bool
    {
        return $this->execution_mode === self::EXECUTION_VDS_AGENT;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_STAGED => $this->isQueuedForAgent() ? __('Waiting for VDS agent') : __('Ready for verification'),
            self::STATUS_APPLYING => __('Updating'),
            self::STATUS_SUCCEEDED => __('Installed'),
            self::STATUS_FAILED => __('Failed'),
            self::STATUS_DISCARDED => __('Discarded'),
            default => __('Unknown'),
        };
    }

    public function phaseLabel(): string
    {
        return match ($this->phase) {
            self::PHASE_PREPARING => __('Preparing backups'),
            self::PHASE_FILES => __('Replacing files'),
            self::PHASE_MIGRATIONS => __('Applying migrations'),
            self::PHASE_FINALIZING => __('Finalizing update'),
            self::PHASE_COMPLETED => __('Completed'),
            default => __('Not started'),
        };
    }

    public function filesMayHaveChanged(): bool
    {
        return $this->phase === null || in_array($this->phase, [
            self::PHASE_FILES,
            self::PHASE_MIGRATIONS,
            self::PHASE_FINALIZING,
        ], true);
    }

    public function databaseMayHaveChanged(): bool
    {
        $migrate = (bool) ($this->manifest['migrate'] ?? true);

        return $migrate && ($this->phase === null || in_array($this->phase, [
            self::PHASE_MIGRATIONS,
            self::PHASE_FINALIZING,
        ], true));
    }
}
