<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class FailedJob extends Model
{
    public $timestamps = false;

    protected $table = 'failed_jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'failed_at' => 'datetime',
        ];
    }

    public function displayName(): string
    {
        $payload = json_decode((string) $this->payload, true);
        $name = is_array($payload) && is_string($payload['displayName'] ?? null)
            ? trim($payload['displayName'])
            : '';

        return $name !== '' ? Str::limit($name, 190, '') : __('Unknown job');
    }

    public function exceptionClass(): string
    {
        $exception = ltrim((string) $this->exception);

        if (preg_match('/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $exception, $matches) === 1) {
            return Str::limit($matches[1], 190, '');
        }

        return __('Unknown error');
    }
}
