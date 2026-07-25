@extends('admin.layouts.panel')

@section('title', __('Security'))
@section('description', __('Administrator sign-in protection and security log retention.'))

@section('content')
@include('admin.settings._system_tabs')

<div class="notice notice-warning security-settings-notice">
    <p><strong>{{ __('Protection cannot be disabled from the control panel.') }}</strong> {{ __('Only safe values within the allowed ranges can be saved.') }}</p>
</div>

<div class="security-settings-grid">
    <form class="settings-form" method="POST" action="{{ route('admin.settings.security.update') }}">
        @csrf
        @method('PUT')

        <section class="form-card">
            <div class="settings-card-heading">
                <div>
                    <h2>{{ __('Administrator sign-in protection') }}</h2>
                    <p>{{ __('The IP limits stop mass requests before the login controller writes anything to the database.') }}</p>
                </div>
            </div>

            <div class="security-field-grid">
                <div class="form-group">
                    <label for="login_ip_per_minute">{{ __('Requests from one IP per minute') }}</label>
                    <input id="login_ip_per_minute" name="login_ip_per_minute" type="number" min="5" max="60" required value="{{ old('login_ip_per_minute', $settings['login_ip_per_minute']) }}">
                    <small>{{ __('Allowed range: 5 to 60. Default: 10.') }}</small>
                </div>
                <div class="form-group">
                    <label for="login_ip_per_hour">{{ __('Requests from one IP per hour') }}</label>
                    <input id="login_ip_per_hour" name="login_ip_per_hour" type="number" min="30" max="1000" required value="{{ old('login_ip_per_hour', $settings['login_ip_per_hour']) }}">
                    <small>{{ __('Allowed range: 30 to 1000. Default: 100.') }}</small>
                </div>
                <div class="form-group">
                    <label for="login_max_attempts">{{ __('Attempts for one email and IP') }}</label>
                    <input id="login_max_attempts" name="login_max_attempts" type="number" min="3" max="20" required value="{{ old('login_max_attempts', $settings['login_max_attempts']) }}">
                    <small>{{ __('Allowed range: 3 to 20. Default: 5.') }}</small>
                </div>
                <div class="form-group">
                    <label for="login_decay_minutes">{{ __('Account attempt block duration, minutes') }}</label>
                    <input id="login_decay_minutes" name="login_decay_minutes" type="number" min="1" max="60" required value="{{ old('login_decay_minutes', $settings['login_decay_minutes']) }}">
                    <small>{{ __('Allowed range: 1 to 60 minutes. Default: 1 minute.') }}</small>
                </div>
            </div>
        </section>

        <section class="form-card">
            <div class="settings-card-heading">
                <div>
                    <h2>{{ __('Log retention') }}</h2>
                    <p>{{ __('Only records older than these periods are eligible for automatic or manual cleanup.') }}</p>
                </div>
            </div>

            <div class="security-field-grid">
                <div class="form-group">
                    <label for="audit_retention_days">{{ __('Audit log retention, days') }}</label>
                    <input id="audit_retention_days" name="audit_retention_days" type="number" min="30" max="730" required value="{{ old('audit_retention_days', $settings['audit_retention_days']) }}">
                    <small>{{ __('Allowed range: 30 to 730 days. Default: 90.') }}</small>
                </div>
                <div class="form-group">
                    <label for="admin_login_retention_days">{{ __('Administrator login log retention, days') }}</label>
                    <input id="admin_login_retention_days" name="admin_login_retention_days" type="number" min="7" max="365" required value="{{ old('admin_login_retention_days', $settings['admin_login_retention_days']) }}">
                    <small>{{ __('Allowed range: 7 to 365 days. Default: 30.') }}</small>
                </div>
            </div>
        </section>

        <div class="admin-actions-panel settings-actions">
            <button class="button button-primary" type="submit">{{ __('Save security settings') }}</button>
        </div>
    </form>

    <aside class="security-settings-sidebar">
        <section class="form-card security-log-summary">
            <div class="settings-card-heading">
                <div>
                    <h2>{{ __('Security log status') }}</h2>
                    <p>{{ __('Counts are recalculated whenever this page is opened.') }}</p>
                </div>
            </div>

            <dl class="security-stat-list">
                <div>
                    <dt>{{ __('Audit log') }}</dt>
                    <dd>{{ number_format($statistics['audit_total'], 0, ',', ' ') }}</dd>
                    <small>{{ __('Expired: :count', ['count' => number_format($statistics['audit_expired'], 0, ',', ' ')]) }}</small>
                </div>
                <div>
                    <dt>{{ __('Administrator login log') }}</dt>
                    <dd>{{ number_format($statistics['admin_login_total'], 0, ',', ' ') }}</dd>
                    <small>{{ __('Expired: :count', ['count' => number_format($statistics['admin_login_expired'], 0, ',', ' ')]) }}</small>
                </div>
                <div>
                    <dt>{{ __('Last cleanup') }}</dt>
                    <dd class="security-stat-text">{{ $statistics['last_cleaned_at']?->format('d.m.Y H:i:s') ?? __('Never') }}</dd>
                    <small>{{ __('Automatic schedule: daily at 03:30.') }}</small>
                </div>
            </dl>

            <div class="notice notice-warning security-scheduler-note">
                <p>{{ __('Automatic cleanup requires the server scheduler to run php artisan schedule:run.') }}</p>
            </div>
        </section>

        <section class="form-card security-cleanup-card">
            <div class="settings-card-heading">
                <div>
                    <h2>{{ __('Expired record cleanup') }}</h2>
                    <p>{{ __('Current records and entries inside the retention period will not be deleted.') }}</p>
                </div>
            </div>

            <div class="security-cleanup-estimate">
                <span>{{ __('Will be deleted now') }}</span>
                <strong>{{ number_format($statistics['audit_expired'] + $statistics['admin_login_expired'], 0, ',', ' ') }}</strong>
                <small>{{ __(':audit audit entries and :login sign-in entries.', [
                    'audit' => number_format($statistics['audit_expired'], 0, ',', ' '),
                    'login' => number_format($statistics['admin_login_expired'], 0, ',', ' '),
                ]) }}</small>
            </div>

            @if(($statistics['audit_expired'] + $statistics['admin_login_expired']) === 0)
                <div class="notice notice-info security-cleanup-empty" role="status">
                    <p>{{ __('There is nothing to delete yet. Audit entries become eligible after :audit days, and administrator sign-in entries after :login days.', [
                        'audit' => $statistics['audit_retention_days'],
                        'login' => $statistics['admin_login_retention_days'],
                    ]) }}</p>
                </div>
            @endif

            <div class="security-cleanup-actions">
                <button class="button button-danger" type="button" data-security-cleanup-open data-security-cleanup-disabled="{{ ($statistics['audit_expired'] + $statistics['admin_login_expired']) === 0 ? '1' : '0' }}" @disabled(($statistics['audit_expired'] + $statistics['admin_login_expired']) === 0) title="{{ ($statistics['audit_expired'] + $statistics['admin_login_expired']) === 0 ? __('There are no expired records to delete.') : __('Delete expired records') }}">
                    {{ __('Delete expired records') }}
                </button>
            </div>
        </section>
    </aside>
</div>

<section id="administrator-sign-ins" class="form-card security-login-history">
    <div class="settings-card-heading">
        <div>
            <h2>{{ __('Recent administrator sign-ins') }}</h2>
            <p>{{ __('The log stores the entered email address, result, IP address and browser. Passwords and authentication codes are never recorded.') }}</p>
        </div>
    </div>

    @if($loginAttempts->isEmpty())
        <div class="admin-empty-state empty-state security-login-empty">
            <div class="empty-state-mark" aria-hidden="true">L</div>
            <h3>{{ __('No sign-in attempts yet') }}</h3>
        </div>
    @else
        <div class="admin-table-wrap audit-table-wrap security-login-table-wrap">
            <table class="admin-table audit-table security-login-table">
                <thead>
                    <tr>
                        <th>{{ __('Date and time') }}</th>
                        <th>{{ __('Entered email') }}</th>
                        <th>{{ __('Administrator') }}</th>
                        <th>{{ __('Result') }}</th>
                        <th>{{ __('Reason') }}</th>
                        <th>{{ __('IP address') }}</th>
                        <th>{{ __('Browser') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($loginAttempts as $attempt)
                        <tr>
                            <td class="audit-date"><strong>{{ $attempt->created_at?->format('d.m.Y') }}</strong><span>{{ $attempt->created_at?->format('H:i:s') }}</span></td>
                            <td class="audit-monospace">{{ $attempt->email }}</td>
                            <td>
                                <strong>{{ $attempt->admin?->name ?? '—' }}</strong>
                                @if($attempt->admin)<span class="audit-muted">{{ $attempt->admin->email }}</span>@endif
                            </td>
                            <td><span @class(['status-badge', 'status-badge-success' => $attempt->successful, 'status-badge-danger' => ! $attempt->successful])>{{ $attempt->resultLabel() }}</span></td>
                            <td>{{ $attempt->failureReasonLabel() }}</td>
                            <td class="audit-monospace">{{ $attempt->ip_address ?: '—' }}</td>
                            <td class="security-login-browser" title="{{ $attempt->user_agent }}">{{ \Illuminate\Support\Str::limit($attempt->user_agent ?: '—', 80) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($loginAttempts->hasPages())
            @php($firstPage = max(1, $loginAttempts->currentPage() - 2))
            @php($lastPage = min($loginAttempts->lastPage(), $loginAttempts->currentPage() + 2))
            <nav class="simple-pagination" aria-label="{{ __('Administrator sign-in page navigation') }}">
                @if($loginAttempts->onFirstPage())<span class="button button-secondary disabled">← {{ __('Back') }}</span>@else<a wire:navigate class="button button-secondary" href="{{ $loginAttempts->previousPageUrl() }}#administrator-sign-ins" rel="prev">← {{ __('Back') }}</a>@endif
                <div class="pagination-pages" aria-label="{{ __('Pages') }}">@foreach($loginAttempts->getUrlRange($firstPage, $lastPage) as $page => $url) @if($page === $loginAttempts->currentPage())<span class="pagination-page active" aria-current="page">{{ $page }}</span>@else<a wire:navigate class="pagination-page" href="{{ $url }}#administrator-sign-ins">{{ $page }}</a>@endif @endforeach</div>
                @if($loginAttempts->hasMorePages())<a wire:navigate class="button button-secondary" href="{{ $loginAttempts->nextPageUrl() }}#administrator-sign-ins" rel="next">{{ __('Next') }} →</a>@else<span class="button button-secondary disabled">{{ __('Next') }} →</span>@endif
            </nav>
        @endif
    @endif
</section>

<dialog class="confirm-dialog security-cleanup-dialog" data-security-cleanup-dialog data-open-on-error="{{ $errors->has('current_password') ? '1' : '0' }}" aria-labelledby="security-cleanup-title">
    <div class="confirm-dialog-card">
        <div class="confirm-dialog-copy">
            <span class="confirm-dialog-mark" aria-hidden="true">!</span>
            <div>
                <h2 id="security-cleanup-title">{{ __('Delete expired log records?') }}</h2>
                <p>{{ __('This operation deletes only records older than the configured retention periods and cannot be undone.') }}</p>
                <strong class="confirm-dialog-target">{{ __(':audit audit entries and :login sign-in entries.', [
                    'audit' => number_format($statistics['audit_expired'], 0, ',', ' '),
                    'login' => number_format($statistics['admin_login_expired'], 0, ',', ' '),
                ]) }}</strong>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.settings.security.logs.cleanup') }}" data-security-cleanup-form>
            @csrf
            <div class="form-group security-confirm-password">
                <label for="current_password">{{ __('Current administrator password') }}</label>
                <input id="current_password" name="current_password" type="password" maxlength="4096" required autocomplete="current-password">
                <small>{{ __('Password confirmation protects against accidental or unattended cleanup.') }}</small>
            </div>
            <div class="confirm-dialog-actions">
                <button class="button button-secondary" type="button" data-security-cleanup-cancel>{{ __('Cancel') }}</button>
                <button class="button button-danger" type="submit">{{ __('Delete expired records') }}</button>
            </div>
        </form>
    </div>
</dialog>
@endsection

@push('scripts')
<script src="{{ asset('assets/admin/js/security.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
