@extends('admin.layouts.panel')
@section('title', __('rewards.queue.journal.title'))
@section('description', __('rewards.queue.journal.description'))
@section('content')
<div class="admin-overview audit-summary reward-queue-summary" data-testid="reward-queue-summary">
    <div class="admin-overview-stat"><span>{{ __('rewards.queue.journal.total') }}</span><strong>{{ $totalCount }}</strong></div>
    @foreach(\App\Models\RewardDelivery::STATUSES as $summaryStatus)
        <div class="admin-overview-stat">
            <span>{{ \App\Models\RewardDelivery::statusLabelFor($summaryStatus) }}</span>
            <strong>{{ $statusCounts[$summaryStatus] ?? 0 }}</strong>
        </div>
    @endforeach
    <p class="admin-overview-copy">{{ __('rewards.queue.journal.boundary') }}</p>
</div>

<form class="admin-filter-form" method="GET" action="{{ route('admin.rewards.index') }}">
    <label><span>{{ __('rewards.queue.journal.server') }}</span><select name="server"><option value="0">{{ __('All servers') }}</option>@foreach($servers as $server)<option value="{{ $server->id }}" @selected($activeServerId === $server->id)>{{ $server->nameFor() }}</option>@endforeach</select></label>
    <label><span>{{ __('rewards.queue.journal.status') }}</span><select name="status"><option value="">{{ __('All statuses') }}</option>@foreach(\App\Models\RewardDelivery::STATUSES as $filterStatus)<option value="{{ $filterStatus }}" @selected($activeStatus === $filterStatus)>{{ \App\Models\RewardDelivery::statusLabelFor($filterStatus) }}</option>@endforeach</select></label>
    <button class="button button-secondary" type="submit">{{ __('Apply') }}</button>
</form>

<section class="reward-queue-capability" data-testid="reward-queue-capability">
    <strong>{{ __('rewards.queue.journal.queue_check_title') }}</strong>
    @if($selectedServer && $queueCapability)
        <p><span class="status-badge {{ $queueCapability->supported ? 'status-badge-success' : 'status-badge-danger' }}">{{ $selectedServer->nameFor() }} · ID {{ $selectedServer->id }}</span></p>
        @if($queueCapability->supported)
            <p>{{ __('rewards.queue.journal.queue_ready') }}</p>
        @else
            <p>{{ \App\Support\Rewards\RewardQueueDiagnostic::messageFor($queueCapability->reasonCode) }}</p>
            <small>{{ \App\Support\Rewards\RewardQueueDiagnostic::actionFor($queueCapability->reasonCode) }}</small>
        @endif
    @else
        <p>{{ __('rewards.queue.journal.all_servers_hint') }}</p>
    @endif
</section>

@if($deliveries->isEmpty())
    <div class="admin-empty-state empty-state"><div class="empty-state-mark" aria-hidden="true">Q</div><h2>{{ __('rewards.queue.journal.no_operations_title') }}</h2><p>{{ __('rewards.queue.journal.no_operations_description') }}</p></div>
@else
    <div class="admin-table-wrap audit-table-wrap reward-queue-table-wrap">
        <table class="admin-table audit-table reward-queue-table">
            <thead>
                <tr>
                    <th>{{ __('rewards.queue.journal.date') }}</th>
                    <th>{{ __('rewards.queue.journal.operation_uuid') }}</th>
                    <th>{{ __('rewards.queue.journal.player') }}</th>
                    <th>{{ __('rewards.queue.journal.server') }}</th>
                    <th>{{ __('rewards.queue.journal.character') }}</th>
                    <th>{{ __('rewards.queue.journal.rewards') }}</th>
                    <th>{{ __('rewards.queue.journal.status') }}</th>
                    <th>{{ __('rewards.queue.journal.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($deliveries as $delivery)
                    <tr data-testid="reward-delivery-row" data-status="{{ $delivery->status }}" data-operation-uuid="{{ $delivery->operation_uuid }}">
                        <td class="audit-date" data-label="{{ __('rewards.queue.journal.date') }}"><strong>{{ $delivery->requested_at?->format('d.m.Y') }}</strong><span>{{ $delivery->requested_at?->format('H:i:s') }}</span></td>
                        <td data-label="{{ __('rewards.queue.journal.operation_uuid') }}"><code class="reward-operation-uuid">{{ $delivery->operation_uuid }}</code></td>
                        <td data-label="{{ __('rewards.queue.journal.player') }}"><strong>{{ $delivery->user?->name ?? '—' }}</strong><span class="audit-muted">{{ $delivery->user?->email ?? '—' }}</span></td>
                        <td data-label="{{ __('rewards.queue.journal.server') }}"><strong>{{ $delivery->gameServer->nameFor() }}</strong><span class="audit-muted">ID {{ $delivery->game_server_id }}</span></td>
                        <td data-label="{{ __('rewards.queue.journal.character') }}"><strong>{{ $delivery->character_name }}</strong><span class="audit-muted">{{ $delivery->account_login }}</span></td>
                        <td data-label="{{ __('rewards.queue.journal.rewards') }}">
                            <div class="reward-queue-items">
                                @foreach($delivery->items as $item)
                                    <div class="reward-queue-item">
                                        <span class="reward-queue-item-icon" aria-hidden="true">
                                            @if($itemIconUrls[$item->id] ?? null)
                                                <img src="{{ $itemIconUrls[$item->id] }}" alt="" width="32" height="32">
                                            @else
                                                {{ mb_strtoupper(mb_substr($item->displayName($delivery->game_server_id), 0, 1)) }}
                                            @endif
                                        </span>
                                        <span class="reward-queue-item-copy">
                                            <strong>{{ $item->displayName($delivery->game_server_id) }}</strong>
                                            <small>ID {{ $item->item_id }} · × {{ number_format($item->amount, 0, '.', ' ') }}</small>
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </td>
                        <td data-label="{{ __('rewards.queue.journal.status') }}">
                            <span @class(['status-badge','status-badge-success'=>$delivery->status===\App\Models\RewardDelivery::STATUS_QUEUED,'status-badge-danger'=>$delivery->status===\App\Models\RewardDelivery::STATUS_FAILED,'status-badge-warning'=>$delivery->status===\App\Models\RewardDelivery::STATUS_REVIEW])>{{ $delivery->statusLabel() }}</span>
                            @if($delivery->failure_code)
                                <div class="reward-queue-diagnostic">
                                    <strong>{{ __('rewards.queue.journal.diagnostic') }}</strong>
                                    <p>{{ $delivery->failureMessage() }}</p>
                                    <strong>{{ __('rewards.queue.journal.recommended_action') }}</strong>
                                    <p>{{ $delivery->failureAction() }}</p>
                                    <code>{{ $delivery->failure_code }}</code>
                                </div>
                            @endif
                        </td>
                        <td class="audit-details-link" data-label="{{ __('rewards.queue.journal.actions') }}">
                            @if($delivery->canReconcile() && ! request()->attributes->get('admin_read_only'))
                                <form method="POST" action="{{ route('admin.rewards.reconcile', $delivery) }}">
                                    @csrf
                                    <button class="button button-secondary" type="submit">{{ __('rewards.queue.journal.check_again') }}</button>
                                </form>
                            @else
                                <span>—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <x-admin.pagination
        :paginator="$deliveries"
        :aria-label="__('Reward queue page navigation')"
        :pages-label="__('Pages')"
        :previous-label="__('Back')"
        :next-label="__('Next')"
        numbered
    />
@endif
@endsection
