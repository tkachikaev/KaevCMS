@props(['title' => null, 'description' => null])
<div {{ $attributes->class(['account-empty', 'account-standard-empty']) }}>
    @isset($icon)<span class="account-empty-symbol" aria-hidden="true">{{ $icon }}</span>@endisset
    @if($title)<h3>{{ $title }}</h3>@endif
    @if($description)<p>{{ $description }}</p>@endif
    {{ $slot }}
</div>
