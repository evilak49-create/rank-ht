<div
    class="fi-no-notification"
    style="max-width: min(100%, 44rem);"
    x-data="notificationComponent({ notification: { id: @js($getId()), duration: @js($getDuration()) } })"
    x-transition
    x-on:close-notification.window="if ($event.detail.id === @js($getId())) close()"
    x-on:keydown.escape="handleEscape($event)"
    wire:key="platform-query-response-{{ $getId() }}"
>
    <div class="fi-no-notification-main" style="min-width: 0;">
        <h3 class="fi-no-notification-title">{{ $getTitle() }}</h3>
        <label class="fi-no-notification-body">
            服务商响应原文
            <textarea
                readonly
                rows="12"
                wrap="soft"
                spellcheck="false"
                style="display: block; width: 100%; max-height: 50vh; overflow: auto; overflow-wrap: anywhere; border: 1px solid currentColor; border-radius: 0.375rem; padding: 0.5rem; font-family: monospace;"
            >{{ "\n".$responseBody }}</textarea>
        </label>
    </div>
    <x-filament::icon-button
        icon="heroicon-o-x-mark"
        color="gray"
        :label="__('filament-notifications::notification.actions.close.label')"
        x-on:click="dismiss()"
    />
</div>
