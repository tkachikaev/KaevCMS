@props(['title' => null, 'description' => null, 'titleId' => null])
<div {{ $attributes->class(['account-panel-header']) }}>
    @if($title || $description || isset($summary))
        <div class="account-panel-heading">
            @if($title)<h2 @if($titleId) id="{{ $titleId }}" @endif>{{ $title }}</h2>@endif
            @if($description)<p>{{ $description }}</p>@endif
            {{ $summary ?? '' }}
        </div>
    @endif
    @isset($actions)
        <div class="account-panel-actions">{{ $actions }}</div>
    @endisset
    {{ $slot }}
</div>
