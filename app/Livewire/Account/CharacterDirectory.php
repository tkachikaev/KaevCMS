<?php

namespace App\Livewire\Account;

use App\Models\User;
use App\Models\UserCharacterPreference;
use App\Services\GameAccounts\AccountCharacterDirectory;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CharacterDirectory extends Component
{
    private const PREFERENCE_SCHEMA_VERSION = 2;

    /** @var array<string,mixed> */
    #[Locked]
    public array $directory = [];

    /** @var list<int> */
    #[Locked]
    public array $hiddenServerIds = [];

    /** @var list<int> */
    #[Locked]
    public array $hiddenAccountIds = [];

    /** @var list<int> */
    public array $expandedServerIds = [];

    /** @var list<int> */
    public array $expandedAccountIds = [];

    public string $viewMode = 'all';

    public string $search = '';

    public string $serverFilter = 'all';

    public string $accountFilter = 'all';

    public bool $onlineOnly = false;

    public string $sortMode = 'priority';

    public bool $showHiddenInAll = true;

    public function mount(AccountCharacterDirectory $characters): void
    {
        $user = $this->user();
        $preference = $this->preference($user);
        $this->upgradePreference($preference);
        $this->directory = $characters->for($user);
        $this->viewMode = in_array($preference->view_mode, ['grouped', 'all'], true)
            ? $preference->view_mode
            : 'all';
        $this->hiddenServerIds = $this->integerList($preference->hidden_game_server_ids);
        $this->hiddenAccountIds = $this->integerList($preference->hidden_game_account_ids);
        $this->initializeExpandedGroups();
    }

    public function setViewMode(string $mode): void
    {
        abort_unless(in_array($mode, ['grouped', 'all'], true), 404);

        $this->viewMode = $mode;
        $preference = $this->preference($this->user());
        $preference->view_mode = $mode;
        $preference->schema_version = self::PREFERENCE_SCHEMA_VERSION;
        $preference->save();
    }

    public function toggleServer(int $serverId): void
    {
        $this->assertServerAvailable($serverId);
        $this->expandedServerIds = $this->toggleId($this->expandedServerIds, $serverId);
    }

    public function toggleAccount(int $accountId): void
    {
        $this->assertAccountAvailable($accountId);
        $this->expandedAccountIds = $this->toggleId($this->expandedAccountIds, $accountId);
    }

    public function hideServer(int $serverId): void
    {
        $this->assertServerAvailable($serverId);
        $this->hiddenServerIds = $this->appendId($this->hiddenServerIds, $serverId);
        $this->expandedServerIds = $this->removeId($this->expandedServerIds, $serverId);
        $this->saveHiddenGroups();
    }

    public function restoreServer(int $serverId): void
    {
        $this->assertServerAvailable($serverId);
        $this->hiddenServerIds = $this->removeId($this->hiddenServerIds, $serverId);
        $this->expandedServerIds = $this->appendId($this->expandedServerIds, $serverId);
        $this->saveHiddenGroups();
    }

    public function hideAccount(int $accountId): void
    {
        $this->assertAccountAvailable($accountId);
        $this->hiddenAccountIds = $this->appendId($this->hiddenAccountIds, $accountId);
        $this->expandedAccountIds = $this->removeId($this->expandedAccountIds, $accountId);
        $this->saveHiddenGroups();
    }

    public function restoreAccount(int $accountId): void
    {
        $this->assertAccountAvailable($accountId);
        $this->hiddenAccountIds = $this->removeId($this->hiddenAccountIds, $accountId);
        $this->expandedAccountIds = $this->appendId($this->expandedAccountIds, $accountId);
        $this->saveHiddenGroups();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'serverFilter', 'accountFilter', 'onlineOnly', 'sortMode']);
    }

    public function render(): View
    {
        return view('account-theme::livewire.character-directory', [
            'visibleServers' => $this->visibleServers(),
            'hiddenServers' => $this->hiddenServers(),
            'hiddenAccounts' => $this->hiddenAccounts(),
            'allCharacters' => $this->filteredCharacters(),
            'serverOptions' => $this->serverOptions(),
            'accountOptions' => $this->accountOptions(),
            'counts' => $this->directoryCounts(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function directoryServers(): array
    {
        $servers = $this->directory['servers'] ?? null;
        if (! is_array($servers)) {
            return [];
        }

        $rows = [];
        foreach ($servers as $server) {
            if (is_array($server)) {
                $rows[] = $server;
            }
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function directoryCharacters(): array
    {
        $characters = $this->directory['characters'] ?? null;
        if (! is_array($characters)) {
            return [];
        }

        $rows = [];
        foreach ($characters as $character) {
            if (is_array($character)) {
                $rows[] = $character;
            }
        }

        return $rows;
    }

    /** @return array{servers:int,accounts:int,characters:int,online:int} */
    private function directoryCounts(): array
    {
        $counts = $this->directory['counts'] ?? null;
        if (! is_array($counts)) {
            return ['servers' => 0, 'accounts' => 0, 'characters' => 0, 'online' => 0];
        }

        return [
            'servers' => max(0, (int) ($counts['servers'] ?? 0)),
            'accounts' => max(0, (int) ($counts['accounts'] ?? 0)),
            'characters' => max(0, (int) ($counts['characters'] ?? 0)),
            'online' => max(0, (int) ($counts['online'] ?? 0)),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function visibleServers(): array
    {
        $servers = $this->directoryServers();

        $visible = [];
        foreach ($servers as $server) {
            if (in_array((int) ($server['id'] ?? 0), $this->hiddenServerIds, true)) {
                continue;
            }

            $server['accounts'] = array_values(array_filter(
                is_array($server['accounts'] ?? null) ? $server['accounts'] : [],
                fn (mixed $account): bool => is_array($account)
                    && ! in_array((int) ($account['id'] ?? 0), $this->hiddenAccountIds, true),
            ));
            $visible[] = $server;
        }

        return $visible;
    }

    /** @return list<array<string,mixed>> */
    private function hiddenServers(): array
    {
        return array_values(array_filter(
            $this->directoryServers(),
            fn (array $server): bool => in_array((int) ($server['id'] ?? 0), $this->hiddenServerIds, true),
        ));
    }

    /** @return list<array{id:int,login:string,server_name:string}> */
    private function hiddenAccounts(): array
    {
        $rows = [];
        foreach ($this->directoryServers() as $server) {
            $accounts = is_array($server['accounts'] ?? null) ? $server['accounts'] : [];
            foreach ($accounts as $account) {
                if (! is_array($account) || ! in_array((int) ($account['id'] ?? 0), $this->hiddenAccountIds, true)) {
                    continue;
                }

                $rows[(int) $account['id']] = [
                    'id' => (int) $account['id'],
                    'login' => (string) $account['login'],
                    'server_name' => (string) $server['name'],
                ];
            }
        }

        return array_values($rows);
    }

    /** @return list<array<string,mixed>> */
    private function filteredCharacters(): array
    {
        $characters = $this->directoryCharacters();
        $search = mb_strtolower(trim($this->search));

        $filtered = array_filter($characters, function (array $character) use ($search): bool {
            if (! $this->showHiddenInAll && (
                in_array((int) ($character['server_id'] ?? 0), $this->hiddenServerIds, true)
                || in_array((int) ($character['account_id'] ?? 0), $this->hiddenAccountIds, true)
            )) {
                return false;
            }

            if ($this->serverFilter !== 'all' && (int) $this->serverFilter !== (int) ($character['server_id'] ?? 0)) {
                return false;
            }

            if ($this->accountFilter !== 'all' && (int) $this->accountFilter !== (int) ($character['account_id'] ?? 0)) {
                return false;
            }

            if ($this->onlineOnly && ! (bool) ($character['online'] ?? false)) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            $haystack = mb_strtolower(implode(' ', [
                (string) ($character['name'] ?? ''),
                (string) ($character['class_name'] ?? ''),
                (string) ($character['clan'] ?? ''),
                (string) ($character['server_name'] ?? ''),
                (string) ($character['account_login'] ?? ''),
            ]));

            return str_contains($haystack, $search);
        });

        $rows = array_values($filtered);
        usort($rows, fn (array $left, array $right): int => $this->compareCharacters($left, $right));

        return $rows;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function compareCharacters(array $left, array $right): int
    {
        if ($this->sortMode === 'name') {
            return strnatcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        }

        if ($this->sortMode === 'level') {
            $levelComparison = (int) ($right['level'] ?? 0) <=> (int) ($left['level'] ?? 0);

            return $levelComparison !== 0
                ? $levelComparison
                : strnatcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        }

        if ($this->sortMode === 'server') {
            return [
                mb_strtolower((string) ($left['server_name'] ?? '')),
                mb_strtolower((string) ($left['account_login'] ?? '')),
                mb_strtolower((string) ($left['name'] ?? '')),
            ] <=> [
                mb_strtolower((string) ($right['server_name'] ?? '')),
                mb_strtolower((string) ($right['account_login'] ?? '')),
                mb_strtolower((string) ($right['name'] ?? '')),
            ];
        }

        return [
            ! (bool) ($left['online'] ?? false),
            -(int) ($left['level'] ?? 0),
            mb_strtolower((string) ($left['name'] ?? '')),
        ] <=> [
            ! (bool) ($right['online'] ?? false),
            -(int) ($right['level'] ?? 0),
            mb_strtolower((string) ($right['name'] ?? '')),
        ];
    }

    /** @return array<int,string> */
    private function serverOptions(): array
    {
        $options = [];
        foreach ($this->directoryServers() as $server) {
            $options[(int) $server['id']] = (string) $server['name'];
        }

        return $options;
    }

    /** @return array<int,string> */
    private function accountOptions(): array
    {
        $options = [];
        foreach ($this->directoryServers() as $server) {
            $accounts = is_array($server['accounts'] ?? null) ? $server['accounts'] : [];
            foreach ($accounts as $account) {
                if (is_array($account)) {
                    $options[(int) $account['id']] = (string) $account['login'];
                }
            }
        }

        return $options;
    }

    private function initializeExpandedGroups(): void
    {
        $servers = $this->visibleServers();
        if ($servers === []) {
            return;
        }

        $server = $servers[0];
        $serverId = (int) $server['id'];
        $this->expandedServerIds = [$serverId];

        $accounts = is_array($server['accounts'] ?? null) ? $server['accounts'] : [];
        if ($accounts !== []) {
            $this->expandedAccountIds = [(int) $accounts[0]['id']];
        }

        if (count($servers) === 1) {
            $this->expandedServerIds = [$serverId];
        }

        if (count($accounts) === 1) {
            $this->expandedAccountIds = [(int) $accounts[0]['id']];
        }
    }

    private function saveHiddenGroups(): void
    {
        $preference = $this->preference($this->user());
        $preference->hidden_game_server_ids = $this->hiddenServerIds;
        $preference->hidden_game_account_ids = $this->hiddenAccountIds;
        $preference->save();
    }

    private function assertServerAvailable(int $serverId): void
    {
        abort_unless(array_key_exists($serverId, $this->serverOptions()), 404);
    }

    private function assertAccountAvailable(int $accountId): void
    {
        abort_unless(array_key_exists($accountId, $this->accountOptions()), 404);
    }

    private function preference(User $user): UserCharacterPreference
    {
        return UserCharacterPreference::query()->firstOrCreate(['user_id' => $user->id]);
    }

    private function upgradePreference(UserCharacterPreference $preference): void
    {
        if ($preference->schema_version >= self::PREFERENCE_SCHEMA_VERSION) {
            return;
        }

        $preference->view_mode = 'all';
        $preference->schema_version = self::PREFERENCE_SCHEMA_VERSION;
        $preference->save();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** @param mixed $values @return list<int> */
    private function integerList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $ids = array_map(static fn (mixed $value): int => (int) $value, $values);
        $ids = array_filter($ids, static fn (int $value): bool => $value > 0);

        return array_values(array_unique($ids));
    }

    /** @param list<int> $values @return list<int> */
    private function appendId(array $values, int $id): array
    {
        return $this->integerList([...$values, $id]);
    }

    /** @param list<int> $values @return list<int> */
    private function removeId(array $values, int $id): array
    {
        return array_values(array_filter($values, static fn (int $value): bool => $value !== $id));
    }

    /** @param list<int> $values @return list<int> */
    private function toggleId(array $values, int $id): array
    {
        return in_array($id, $values, true)
            ? $this->removeId($values, $id)
            : $this->appendId($values, $id);
    }
}
