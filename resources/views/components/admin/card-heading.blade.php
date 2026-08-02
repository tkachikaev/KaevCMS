@props([
    'title',
    'description' => null,
])

<div {{ $attributes->class(['admin-card-heading']) }}>
    <div>
        <h2>{{ $title }}</h2>
        @if(filled($description))
            <p>{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="admin-card-heading-actions">{{ $actions }}</div>
    @endisset
</div>
