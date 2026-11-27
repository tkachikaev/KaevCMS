<?php

namespace KaevCMS\Modules\Voting\Services;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Services\AuditLogger;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\Voting\Models\VotingBinding;
use KaevCMS\Modules\Voting\Models\VotingEvent;
use KaevCMS\Modules\Voting\Models\VotingSetting;
use KaevCMS\Modules\Voting\Providers\KTreonProvider;
use RuntimeException;

final class VotingSettings
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly VotingProviderRegistry $providers,
        private readonly VotingAvailability $availability,
    ) {}

    /** @return array{enabled: bool, project_id: ?string, key_saved: bool, pending_policy: string, last_checked_at: ?Carbon, last_check_result: ?string, player_access_enabled: bool, last_sync_at: ?Carbon, last_sync_result: ?string, sync_retry_at: ?Carbon} */
    public function state(): array
    {
        $row = $this->row();

        return [
            'enabled' => $row->enabled,
            'project_id' => $row->project_id,
            'player_access_enabled' => $row->player_access_enabled,
            'last_sync_at' => $row->last_sync_at,
            'last_sync_result' => $row->last_sync_result,
            'sync_retry_at' => $row->sync_retry_at,
            'key_saved' => $row->api_key_ciphertext !== null,
            'pending_policy' => $row->pending_policy,
            'last_checked_at' => $row->last_checked_at,
            'last_check_result' => $row->last_check_result,
        ];
    }

    public function save(Admin $admin, bool $enabled, ?string $projectId, #[\SensitiveParameter] ?string $key, string $policy): void
    {
        $this->authorize($admin);
        $projectId = $projectId === null ? null : strtolower($projectId);
        if ($projectId !== null && ! KTreonProvider::validProjectId($projectId)) {
            throw ValidationException::withMessages(['project_id' => __('module-voting::messages.invalid_project')]);
        }
        if ($key !== null && $key !== '' && (! KTreonProvider::validKey($key) || $projectId === null)) {
            throw ValidationException::withMessages(['password' => __('module-voting::messages.invalid_key')]);
        }
        if (! in_array($policy, ['wait', 'immediate'], true)) {
            throw ValidationException::withMessages(['pending_policy' => __('module-voting::messages.invalid_policy')]);
        }

        DB::transaction(function () use ($admin, $enabled, $projectId, $key, $policy): void {
            $row = $this->row(true);
            $projectChanged = $row->project_id !== $projectId;
            if ($projectChanged && ($row->event_cursor !== '0' || VotingBinding::query()->exists() || VotingEvent::query()->exists())) {
                throw ValidationException::withMessages(['project_id' => __('module-voting::messages.project_locked')]);
            }
            $keyChanged = $key !== null && $key !== '';
            $ciphertext = $projectChanged ? null : $row->api_key_ciphertext;
            if ($keyChanged) {
                $ciphertext = Crypt::encryptString($key);
            }
            if ($enabled && ($projectId === null || $ciphertext === null)) {
                throw ValidationException::withMessages(['password' => __('module-voting::messages.credentials_required')]);
            }

            $row->forceFill([
                'enabled' => $enabled,
                'player_access_enabled' => $enabled && ! $projectChanged && ! $keyChanged && $row->player_access_enabled,
                'project_id' => $projectId,
                'api_key_ciphertext' => $ciphertext,
                'pending_policy' => $policy,
                'updated_by_admin_id' => $admin->id,
            ]);
            if ($projectChanged || $keyChanged) {
                $row->forceFill([
                    'credential_revision' => $row->credential_revision + 1,
                    'last_checked_at' => null,
                    'last_check_result' => null,
                    'intent_retry_at' => null,
                    'sync_retry_at' => null,
                ]);
            }
            $row->save();

            if ($this->audit->success('module', 'voting.settings_updated', $admin, 'ktreon', [
                'enabled' => $enabled,
                'project_id' => $projectId,
                'pending_policy' => $policy,
                'api_key_changed' => $projectChanged || $keyChanged,
            ]) === null) {
                throw new RuntimeException('Voting administrative audit could not be recorded.');
            }
        }, 3);
    }

    public function setPlayerAccess(Admin $admin, bool $enabled, bool $confirmed): void
    {
        $this->authorize($admin);
        DB::transaction(function () use ($admin, $enabled, $confirmed): void {
            $row = $this->row(true);
            if ($enabled && (! $confirmed || ! $row->enabled || $row->project_id === null
                || $row->api_key_ciphertext === null || $row->last_check_result !== 'ok'
                || $this->availability->rewards()->isEmpty())) {
                throw ValidationException::withMessages(['player_access_enabled' => __('module-voting::messages.access_requirements')]);
            }
            $row->forceFill(['player_access_enabled' => $enabled, 'updated_by_admin_id' => $admin->id])->save();
            if ($this->audit->success('module', 'voting.player_access_updated', $admin, 'ktreon', ['enabled' => $enabled]) === null) {
                throw new RuntimeException('Voting administrative audit could not be recorded.');
            }
        }, 3);
    }

    public function check(Admin $admin): string
    {
        $this->authorize($admin);
        $row = $this->row();
        $revision = $row->credential_revision;
        $project = $row->project_id;
        $ciphertext = $row->api_key_ciphertext;
        $result = 'not_configured';

        if ($project !== null && $ciphertext !== null) {
            try {
                $result = $this->providers->get('ktreon')->check($project, Crypt::decryptString($ciphertext));
            } catch (DecryptException) {
                $result = 'not_configured';
            }
        }

        return DB::transaction(function () use ($revision, $result): string {
            $current = $this->row(true);
            // Do not display a successful check for credentials changed during the HTTP request.
            if ($current->credential_revision !== $revision) {
                return 'changed';
            }
            $current->forceFill([
                'last_checked_at' => now(),
                'last_check_result' => $result,
            ])->save();

            return $result;
        }, 3);
    }

    private function row(bool $lock = false): VotingSetting
    {
        $query = VotingSetting::query()->where('provider_code', 'ktreon');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function authorize(Admin $admin): void
    {
        abort_unless(in_array($admin->role, [AdminRole::Owner, AdminRole::Administrator], true), 403);
    }
}
