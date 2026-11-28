@if(in_array($vote->reward_reason, ['recipient_unavailable', 'reward_unconfigured'], true))
    <p>{{ __('module-voting::messages.reason_'.$vote->reward_reason) }}</p>
@endif
@php($snapshotItems = $vote->snapshotItems())
@if($snapshotItems !== [])
    <details>
        <summary>{{ __('module-voting::messages.rewards') }} ({{ count($snapshotItems) }})</summary>
        <ul>
            @foreach($snapshotItems as $item)
                <li>{{ __('module-voting::messages.item_id') }} {{ $item['item_id'] }} × {{ format_number($item['amount']) }}</li>
            @endforeach
        </ul>
    </details>
@endif
