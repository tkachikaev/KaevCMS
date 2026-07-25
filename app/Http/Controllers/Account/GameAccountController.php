<?php

namespace App\Http\Controllers\Account;

use App\Contracts\GameAccountGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\CreateGameAccountRequest;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\AuditLogger;
use App\Services\GameAccounts\GameAccountQuota;
use App\Services\GameAccountSettings;
use App\Services\GameAssets\CharacterAppearanceResolver;
use App\Services\GameWorld\MobiusCharacterLabels;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class GameAccountController extends Controller
{
    private const ERROR_LIMIT_REACHED = 'game_account_limit_reached';

    private const ERROR_LINK_CONFLICT = 'game_account_link_conflict';

    private const ERROR_SERVER_UNAVAILABLE = 'game_server_unavailable';

    public function __construct(
        private readonly GameAccountGateway $gateway,
        private readonly AuditLogger $auditLogger,
        private readonly GameAccountQuota $quota,
        private readonly MobiusCharacterLabels $labels,
        private readonly CharacterAppearanceResolver $appearances,
    ) {}

    public function index(Request $request, GameAccountSettings $settings): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $accounts = $user->availableGameAccounts()
            ->with(['loginServer.gameServers.translations', 'registrationGameServer.translations'])
            ->latest('id')
            ->get();
        $quotaAccountCount = $this->quota->count($user);
        $values = $settings->values();

        return view('account-theme::game-accounts.index', [
            'user' => $user,
            'accounts' => $accounts,
            'quotaAccountCount' => $quotaAccountCount,
            'hiddenAccountCount' => max(0, $quotaAccountCount - $accounts->count()),
            'settings' => $values,
            'availableServers' => $this->availableGameServers()->count(),
        ]);
    }

    public function create(Request $request, GameAccountSettings $settings): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $values = $settings->values();
        if (! $values['enabled']) {
            return redirect()->to(public_route('account'))->with('warning', __('Creating game accounts is disabled.'));
        }

        if ($this->quota->reached($user, $values['max_accounts'])) {
            return redirect()->to(public_route('account'))->with('warning', __('You have reached the game account limit.'));
        }

        return view('account-theme::game-accounts.create', [
            'user' => $user,
            'settings' => $values,
            'gameServers' => $this->availableGameServers(),
        ]);
    }

    public function store(CreateGameAccountRequest $request, GameAccountSettings $settings): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $values = $settings->values();
        if (! $values['enabled']) {
            return back()->withErrors(['game_login' => __('Creating game accounts is disabled.')]);
        }

        $gameServer = $this->availableGameServers()->firstWhere('id', $request->integer('game_server_id'));
        if (! $gameServer instanceof GameServer || ! $gameServer->loginServer instanceof LoginServer) {
            return back()->withInput($request->except(['game_password', 'game_password_confirmation']))
                ->withErrors(['game_server_id' => __('The selected game server is unavailable.')]);
        }

        $loginServer = $gameServer->loginServer;
        $login = trim((string) $request->validated('game_login'));
        $normalized = Str::lower($login);

        if ($this->quota->reached($user, $values['max_accounts'])) {
            return back()->withErrors(['game_login' => __('You have reached the game account limit.')]);
        }

        if (UserGameAccount::query()
            ->where('login_server_id', $loginServer->id)
            ->where('normalized_login', $normalized)
            ->exists()) {
            return back()->withInput($request->except(['game_password', 'game_password_confirmation']))
                ->withErrors(['game_login' => __('This game login is already linked to a CMS account.')]);
        }

        try {
            if ($this->gateway->accountExists($loginServer, $login)) {
                return back()->withInput($request->except(['game_password', 'game_password_confirmation']))
                    ->withErrors(['game_login' => __('This game login already exists.')]);
            }

            $link = DB::transaction(function () use ($user, $loginServer, $gameServer, $login, $normalized, $values): UserGameAccount {
                $lockedLoginServer = LoginServer::query()->lockForUpdate()->findOrFail($loginServer->id);
                $lockedGameServer = GameServer::query()->lockForUpdate()->findOrFail($gameServer->id);
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

                if ($lockedGameServer->login_server_id !== $lockedLoginServer->id
                    || ! $lockedGameServer->connectionConfigured()
                    || ! $this->gateway->supportsLoginServer($lockedLoginServer)) {
                    throw new RuntimeException(self::ERROR_SERVER_UNAVAILABLE);
                }

                if ($this->quota->reached($lockedUser, $values['max_accounts'])) {
                    throw new RuntimeException(self::ERROR_LIMIT_REACHED);
                }

                if (UserGameAccount::query()
                    ->where('login_server_id', $lockedLoginServer->id)
                    ->where('normalized_login', $normalized)
                    ->exists()) {
                    throw new RuntimeException(self::ERROR_LINK_CONFLICT);
                }

                return UserGameAccount::query()->create([
                    'user_id' => $lockedUser->id,
                    'login_server_id' => $lockedLoginServer->id,
                    'registration_game_server_id' => $lockedGameServer->id,
                    'game_login' => $login,
                    'normalized_login' => $normalized,
                    'created_via_cms' => true,
                ]);
            });

            try {
                $this->gateway->createAccount(
                    $loginServer,
                    $login,
                    (string) $request->validated('game_password'),
                    $user->email,
                );
            } catch (Throwable $exception) {
                $link->delete();
                throw $exception;
            }

            $this->auditLogger->success(
                category: 'game_account',
                action: 'user.game_account_created',
                actor: $user,
                target: $link,
                details: [
                    'login_server_id' => $loginServer->id,
                    'game_server_id' => $gameServer->id,
                    'game_login' => $login,
                ],
            );

            return redirect()->to(public_route('game-accounts.show', ['gameAccount' => $link]))
                ->with('status', __('Game account created.'));
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                $knownError = $this->knownCreationError($exception->getMessage(), $request);
                if ($knownError instanceof RedirectResponse) {
                    return $knownError;
                }
            }

            Log::warning('Game account creation failed.', [
                'exception' => $exception::class,
                'login_server_id' => $loginServer->id,
            ]);
            $this->auditLogger->failed(
                category: 'game_account',
                action: 'user.game_account_creation_failed',
                actor: $user,
                target: $login,
                details: ['login_server_id' => $loginServer->id, 'exception_class' => $exception::class],
            );

            return back()->withInput($request->except(['game_password', 'game_password_confirmation']))
                ->withErrors(['game_login' => __('The game account could not be created. Check the server connection or try again later.')]);
        }
    }

    public function show(
        Request $request,
        GameAccountSettings $settings,
    ): View {
        $gameAccount = $this->gameAccountId($request);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $account = $user->availableGameAccounts()
            ->with(['loginServer.gameServers.translations', 'registrationGameServer.translations'])
            ->findOrFail($gameAccount);
        $accountCount = $user->availableGameAccounts()->count();
        $quotaAccountCount = $this->quota->count($user);
        $settingsValues = $settings->values();
        $canCreateAccount = $settingsValues['enabled']
            && $quotaAccountCount < $settingsValues['max_accounts']
            && $this->availableGameServers()->isNotEmpty();
        $summary = null;
        $summaryUnavailable = false;

        try {
            $summary = $this->gateway->accountSummary($account->loginServer, $account->game_login);
        } catch (Throwable $exception) {
            $summaryUnavailable = true;
            Log::warning('Game account summary loading failed.', [
                'exception' => $exception::class,
                'login_server_id' => $account->login_server_id,
            ]);
        }

        $worlds = [];
        foreach ($account->loginServer->gameServers as $gameServer) {
            if (! $gameServer->connectionConfigured() || ! $this->gateway->supportsGameServer($gameServer)) {
                continue;
            }

            try {
                $characters = array_map(function (array $character) use ($gameServer): array {
                    $classId = $character['class_id'];
                    $race = $character['race'];
                    $gender = $character['gender'];

                    return array_merge($character, $this->appearances->resolve($gameServer, $race, $gender, $classId), [
                        'class_name' => $this->labels->className($classId),
                        'race_name' => $this->labels->raceName($race),
                        'gender_name' => $this->labels->genderName($gender),
                    ]);
                }, $this->gateway->characters($gameServer, $account->game_login));
                $worlds[] = ['server' => $gameServer, 'characters' => $characters, 'available' => true];
            } catch (Throwable $exception) {
                Log::warning('Game characters loading failed.', [
                    'exception' => $exception::class,
                    'game_server_id' => $gameServer->id,
                ]);
                $worlds[] = ['server' => $gameServer, 'characters' => [], 'available' => false];
            }
        }

        return view('account-theme::game-accounts.show', [
            'user' => $user,
            'account' => $account,
            'summary' => $summary,
            'summaryUnavailable' => $summaryUnavailable,
            'worlds' => $worlds,
            'accountCount' => $accountCount,
            'canCreateAccount' => $canCreateAccount,
        ]);
    }

    private function knownCreationError(string $code, Request $request): ?RedirectResponse
    {
        $response = back()->withInput($request->except(['game_password', 'game_password_confirmation']));

        return match ($code) {
            self::ERROR_LIMIT_REACHED => $response->withErrors([
                'game_login' => __('You have reached the game account limit.'),
            ]),
            self::ERROR_LINK_CONFLICT => $response->withErrors([
                'game_login' => __('This game login is already linked to a CMS account.'),
            ]),
            self::ERROR_SERVER_UNAVAILABLE => $response->withErrors([
                'game_server_id' => __('The selected game server is unavailable.'),
            ]),
            default => null,
        };
    }

    private function gameAccountId(Request $request): int
    {
        $value = $request->route('gameAccount');

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        abort(404);
    }

    /** @return Collection<int, GameServer> */
    private function availableGameServers(): Collection
    {
        return GameServer::query()
            ->with(['loginServer', 'translations'])
            ->whereNotNull('login_server_id')
            ->where('driver', 'l2j_mobius_ct0_interlude')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (GameServer $server): bool => $server->connectionConfigured()
                && $server->loginServer instanceof LoginServer
                && $this->gateway->supportsLoginServer($server->loginServer));
    }
}
