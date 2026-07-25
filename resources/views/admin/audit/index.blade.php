@extends('admin.layouts.panel')
@section('title', __('Audit log'))
@section('description', __('Administrator, user, mail and CMS system events.'))
@section('content')
<div class="admin-overview audit-summary"><div class="admin-overview-stat"><span>{{ __('Total records') }}</span><strong>{{ $totalCount }}</strong></div><p class="admin-overview-copy">{{ __('Records are retained for :days days. Passwords, tokens and other secrets are never written to the log.', ['days' => $retentionDays]) }}</p></div>
<nav class="admin-subtabs audit-tabs" aria-label="{{ __('Audit categories') }}">
    <a wire:navigate @class(['active' => $activeCategory === null]) href="{{ route('admin.logs.index') }}">{{ __('All') }} <span>{{ $totalCount }}</span></a>
    @foreach($categories as $category)
        @php($categoryLabel = \App\Models\AuditLog::categoryLabelFor($category))
        <a wire:navigate @class(['active' => $activeCategory === $category]) href="{{ route('admin.logs.index', ['category'=>$category]) }}">{{ $categoryLabel }} <span>{{ (int)($counts[$category]??0) }}</span></a>
    @endforeach
</nav>
@if($logs->isEmpty())
    <div class="admin-empty-state empty-state"><div class="empty-state-mark" aria-hidden="true">J</div><h2>{{ __('No records yet') }}</h2><p>{{ __('New events appear here after sign-ins, content changes or system operations.') }}</p></div>
@else
    <div class="admin-table-wrap audit-table-wrap"><table class="admin-table audit-table"><thead><tr><th>{{ __('Date and time') }}</th><th>{{ __('Actor') }}</th><th>{{ __('Action') }}</th><th>{{ __('Target') }}</th><th>{{ __('Result') }}</th><th>{{ __('IP address') }}</th><th></th></tr></thead><tbody>
        @foreach($logs as $log)<tr>
            <td class="audit-date"><strong>{{ $log->created_at?->format('d.m.Y') }}</strong><span>{{ $log->created_at?->format('H:i:s') }}</span></td>
            <td><strong>{{ $log->actorLabel() }}</strong><span class="audit-muted">{{ $log->actorTypeLabel() }}</span></td>
            <td><strong>{{ $log->actionLabel() }}</strong><code>{{ $log->action }}</code></td><td>{{ $log->targetLabel() }}</td>
            <td><span @class(['status-badge','status-badge-success'=>$log->result==='success','status-badge-danger'=>$log->result==='failed'])>{{ $log->resultLabel() }}</span></td>
            <td class="audit-monospace">{{ $log->ip_address ?: '—' }}</td><td class="audit-details-link"><a wire:navigate class="button button-secondary" href="{{ route('admin.logs.show', array_filter(['auditLog'=>$log,'category'=>$activeCategory])) }}">{{ __('Details') }}</a></td>
        </tr>@endforeach
    </tbody></table></div>
    @if($logs->hasPages())
        @php($firstPage=max(1,$logs->currentPage()-2))
        @php($lastPage=min($logs->lastPage(),$logs->currentPage()+2))
        <nav class="simple-pagination" aria-label="{{ __('Audit page navigation') }}">
            @if($logs->onFirstPage())<span class="button button-secondary disabled">← {{ __('Back') }}</span>@else<a wire:navigate class="button button-secondary" href="{{ $logs->previousPageUrl() }}" rel="prev">← {{ __('Back') }}</a>@endif
            <div class="pagination-pages" aria-label="{{ __('Pages') }}">@foreach($logs->getUrlRange($firstPage,$lastPage) as $page=>$url) @if($page===$logs->currentPage())<span class="pagination-page active" aria-current="page">{{ $page }}</span>@else<a wire:navigate class="pagination-page" href="{{ $url }}">{{ $page }}</a>@endif @endforeach</div>
            @if($logs->hasMorePages())<a wire:navigate class="button button-secondary" href="{{ $logs->nextPageUrl() }}" rel="next">{{ __('Next') }} →</a>@else<span class="button button-secondary disabled">{{ __('Next') }} →</span>@endif
        </nav>
    @endif
@endif
@endsection
