@php
    $member = $getRecord();
    $channel = $member->promotionChannel;
    $agent = $member->promotionInvitation?->inviter;
@endphp

<div class="px-3 py-2 text-sm">
    <div>
        @if ($channel && \App\Filament\Resources\PromotionChannelResource::canViewAny())
            <a class="underline" href="{{ \App\Filament\Resources\PromotionChannelResource::getUrl('index', ['tableFilters' => ['channel' => ['id' => $channel->id]]]) }}">{{ $channel->name }}</a>
        @else
            {{ $channel?->name ?? '—' }}
        @endif
    </div>
    <div>
        @if ($agent)
            <a class="underline" href="{{ \App\Filament\Resources\MemberResource::getUrl('view', ['record' => $agent]) }}">{{ $agent->phone }}</a>
        @else
            —
        @endif
    </div>
</div>
