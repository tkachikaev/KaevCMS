{{-- "Latest activity" table: tabs work as links without JavaScript and switch in place with it. --}}
@php($activityLabels = ['latest' => 'activity_latest', 'new' => 'activity_new', 'popular' => 'activity_popular', 'mine' => 'activity_mine'])
@php($activityTab = array_key_exists($activityTab, $activityTabs) ? $activityTab : 'latest')
<section class="forum-panel forum-activity" data-forum-activity>
    <div class="forum-activity-tabs" role="tablist" aria-label="{{ __('module-forum::messages.activity_title') }}">
        @foreach($activityTabs as $tab => $topics)
            <a role="tab" id="forum-activity-tab-{{ $tab }}" href="{{ route('modules.forum.index', ['activity' => $tab]) }}"
               aria-controls="forum-activity-{{ $tab }}" aria-selected="{{ $tab === $activityTab ? 'true' : 'false' }}"
               data-forum-activity-tab="{{ $tab }}">{{ __('module-forum::messages.'.$activityLabels[$tab]) }}</a>
        @endforeach
    </div>
    @foreach($activityTabs as $tab => $topics)
        <div class="forum-activity-list" role="tabpanel" id="forum-activity-{{ $tab }}" aria-labelledby="forum-activity-tab-{{ $tab }}"
             data-forum-activity-panel="{{ $tab }}" @if($tab !== $activityTab) hidden @endif>
            @forelse($topics as $topic)
                @php($byAuthor = $tab === 'new')
                <div class="forum-activity-row">
                    <a class="forum-activity-title" href="{{ $byAuthor ? route('modules.forum.topics.show', $topic) : route('modules.forum.topics.show', ['topic' => $topic->id, 'page' => intdiv(max(0, (int) $topic->reply_count), 15) + 1]) }}" title="{{ $topic->title }}">{{ $topic->title }}</a>
                    <span class="forum-activity-marks">
                        @if($topic->is_pinned)<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-label="{{ __('module-forum::messages.pinned') }}" role="img"><path d="M12 17v5M9 3h6l-1 6 4 3v2H6v-2l4-3z"/></svg>@endif
                        @if($topic->status === 'closed')<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-label="{{ __('module-forum::messages.closed') }}" role="img"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>@endif
                    </span>
                    <span class="forum-activity-section">{{ $topic->section?->label() }}</span>
                    <time class="forum-activity-time">{{ \KaevCMS\Modules\Forum\Support\ForumFormat::when($byAuthor ? $topic->created_at : $topic->last_post_at) }}</time>
                    <span class="forum-activity-author">{{ $byAuthor ? $topic->author_name : $topic->last_author_name }}</span>
                </div>
            @empty
                <p class="forum-activity-empty">{{ __('module-forum::messages.'.($tab === 'popular' ? 'activity_popular_empty' : 'activity_empty')) }}</p>
            @endforelse
        </div>
    @endforeach
</section>
