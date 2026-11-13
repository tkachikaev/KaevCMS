<x-admin.card>
    <x-admin.card-heading
        :title="__('module-news-comments::messages.news_title')"
        :description="__('module-news-comments::messages.news_hint')"
    />

    @if($news->isEmpty())
        <div class="admin-empty-state empty-state">
            <h2>{{ __('module-news-comments::messages.news_empty') }}</h2>
            <p>{{ __('module-news-comments::messages.news_empty_hint') }}</p>
        </div>
    @else
        <div class="admin-card-list news-comments-news-list">
            @foreach($news as $article)
                @php($policy = $policies[$article->id] ?? 'inherit')
                <article class="admin-card-row news-comments-news-row">
                    <div class="news-comments-news-main">
                        <strong>{{ $article->titleFor() }}</strong>
                        <small>
                            ID <span class="admin-num">{{ $article->id }}</span>
                            · <time datetime="{{ $article->created_at?->toAtomString() }}">{{ $article->created_at?->format('d.m.Y H:i') ?? '—' }}</time>
                        </small>
                    </div>
                    @if($canManage)
                        <form class="news-comments-news-form" method="POST" action="{{ route('admin.module-pages.news-comments.news.update', ['adminPath' => $adminPath, 'news' => $article->id, 'page' => $news->currentPage()]) }}">
                            @csrf
                            @method('PUT')
                            <div class="admin-field admin-field-compact">
                                <label class="news-comments-visually-hidden" for="news_policy_{{ $article->id }}">{{ __('module-news-comments::messages.news_policy') }}</label>
                                <select id="news_policy_{{ $article->id }}" name="policy">
                                    @foreach(['inherit' => 'inherit', 'enabled' => 'policy_enabled', 'disabled' => 'policy_disabled'] as $value => $translation)
                                        <option value="{{ $value }}" @selected($policy === $value)>
                                            {{ __('module-news-comments::messages.'.$translation) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <x-admin.button type="submit">{{ __('module-news-comments::messages.save_news') }}</x-admin.button>
                        </form>
                    @else
                        <span @class([
                            'status-badge',
                            'status-badge-success' => $policy === 'enabled',
                            'status-badge-danger' => $policy === 'disabled',
                            'status-badge-muted' => $policy === 'inherit',
                        ])>{{ __('module-news-comments::messages.'.($policy === 'inherit' ? 'inherit' : 'policy_'.$policy)) }}</span>
                    @endif
                </article>
            @endforeach
        </div>
        <x-admin.pagination
            :paginator="$news->appends(['tab' => 'news'])"
            :aria-label="__('module-news-comments::messages.news_pages_label')"
            :pages-label="__('module-news-comments::messages.news_pages_label')"
            :previous-label="__('module-news-comments::messages.previous')"
            :next-label="__('module-news-comments::messages.next')"
            numbered
        />
    @endif
</x-admin.card>
