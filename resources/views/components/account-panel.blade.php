@props(['flush' => false, 'layout' => 'stack'])
<section {{ $attributes->class(['account-panel', 'account-panel--flush' => $flush, 'account-panel--row' => $layout === 'row']) }}>
    {{ $slot }}
</section>
