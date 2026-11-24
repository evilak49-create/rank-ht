<?php

namespace App\Filament\Resources\PixPayoutChannelResource\Pages;

use App\Filament\Resources\PixPayoutChannelResource;
use App\Models\PixPayoutChannel;
use App\Models\User;
use App\RedPackets\ExternalPixChannel;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ListPixPayoutChannels extends ListRecords
{
    protected static string $resource = PixPayoutChannelResource::class;

    public static function providerAction(string $driver): Action
    {
        $definition = ExternalPixChannel::PROVIDERS[$driver];

        return Action::make('configure_'.$driver)->label($definition['label'])->authorize(self::canManageChannels(...))
            ->fillForm(function (PixPayoutChannel $record) use ($definition): array {
                $stored = $record->configuration ?? [];
                $settings = [
                    'enabled' => $record->enabled,
                    'merchant' => $stored['merchant'] ?? null,
                    'callback_ips' => implode("\n", $stored['callback_ips'] ?? explode(' ', $definition['ips'])),
                    'min_cents' => $stored['min_cents'] ?? null,
                    'max_cents' => $stored['max_cents'] ?? null,
                    'callback' => route('pix.provider-callback', ['channel' => $record->id]),
                ];
                foreach (['create', 'query', 'balance'] as $key) {
                    $settings[$key] = $stored[$key] ?? 'https://'.$definition['host'].$definition[$key];
                }

                return $settings;
            })
            ->schema([
                Toggle::make('enabled')->label('启用自动出款'),
                TextInput::make('merchant')->label('商户号')->required()->maxLength(80),
                TextInput::make('secret')->label('签名密钥')->password()->maxLength(255)->helperText('留空保留当前密钥。'),
                TextInput::make('create')->label('代付下单地址')->url()->required(),
                TextInput::make('query')->label('代付查单地址')->url()->required(),
                TextInput::make('balance')->label('余额查询地址')->url()->required(),
                Textarea::make('callback_ips')->label('允许的回调 IP')->required()->helperText('按行填写。'),
                TextInput::make('callback')->label('本站回调地址')->disabled()->dehydrated(false),
                TextInput::make('min_cents')->label('最低代付金额（分）')->integer()->minValue(1)
                    ->helperText($driver === 'u2cpay' ? '如使用测试账户，可填写 100。' : null),
                TextInput::make('max_cents')->label('最高代付金额（分）')->integer()->minValue(1)
                    ->helperText($driver === 'u2cpay' ? '如使用测试账户，可填写 1000。' : null),
            ])->action(function (PixPayoutChannel $record, array $data) use ($driver): void {
                abort_unless(self::canManageChannels(), 403);
                try {
                    DB::transaction(function () use ($record, $data, $driver): void {
                        $channel = PixPayoutChannel::query()->where('driver', $driver)->lockForUpdate()->findOrFail($record->id);
                        $settings = ExternalPixChannel::validateConfiguration(array_replace($data, [
                            'driver' => $driver,
                            'secret' => filled($data['secret'] ?? null) ? $data['secret'] : ($channel->configuration['secret'] ?? null),
                            'callback_ips' => preg_split('/\s+/', trim($data['callback_ips']), -1, PREG_SPLIT_NO_EMPTY),
                        ]));
                        $channel->fill(['enabled' => (bool) $data['enabled']]);
                        if ($channel->configuration !== $settings) {
                            $channel->forceFill([
                                'configuration' => $settings,
                                'last_balance_cents' => null, 'last_balance_at' => null,
                                'last_balance_attempt_at' => null, 'last_balance_failed' => false,
                            ]);
                        }
                        $channel->save();
                    }, 3);
                } catch (UniqueConstraintViolationException) {
                    throw ValidationException::withMessages(['merchant' => '同名渠道已存在，请检查渠道设置后重试。']);
                }
            })->successNotificationTitle('三方渠道配置已保存');
    }

    private static function canManageChannels(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('pix.manage-channels');
    }

    public function hydrate(): void
    {
        abort_unless(PixPayoutChannelResource::canViewAny(), 403);
    }
}
