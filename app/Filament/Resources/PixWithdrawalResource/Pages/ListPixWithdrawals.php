<?php

namespace App\Filament\Resources\PixWithdrawalResource\Pages;

use App\Filament\Resources\PixWithdrawalResource;
use App\Models\PixPayoutChannel;
use App\Models\User;
use App\Points\PointRules;
use App\RedPackets\ExternalPixChannel;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ListPixWithdrawals extends ListRecords
{
    protected static string $resource = PixWithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                self::providerAction('u2cpay'),
                self::providerAction('4zpay'),
                Action::make('provider_balance')->label('查询三方余额')->authorize(self::canManageChannels(...))
                    ->schema([Select::make('channel')->label('渠道')->required()->options(fn (): array => PixPayoutChannel::query()
                        ->whereIn('driver', array_keys(ExternalPixChannel::PROVIDERS))->whereNotNull('configuration')
                        ->pluck('name', 'id')->all())])
                    ->action(function (array $data, ExternalPixChannel $external): void {
                        abort_unless(self::canManageChannels(), 403);
                        $channel = PixPayoutChannel::query()->whereIn('driver', array_keys(ExternalPixChannel::PROVIDERS))->findOrFail($data['channel']);
                        try {
                            $amount = $channel->configuration === null ? null : $external->balance($channel->configuration);
                        } catch (ConnectionException) {
                            $amount = null;
                        }
                        if ($amount === null) {
                            Notification::make()->title('暂未取得 BRL 可用余额')->warning()->send();

                            return;
                        }
                        Notification::make()->title('BRL 可用余额：R$ '.PointRules::display($amount, 2))->success()->send();
                    }),
            ])->label('三方渠道配置')->button(),
            Action::make('add_channel')->label('新增出款渠道')->authorize(self::canManageChannels(...))
                ->modalDescription('新增渠道用于登记人工 Pix 出款。')
                ->schema([TextInput::make('name')->label('渠道名称')->required()->maxLength(80)])
                ->action(function (array $data): void {
                    abort_unless(self::canManageChannels(), 403);
                    $name = trim($data['name']);
                    if ($name === '') {
                        throw ValidationException::withMessages(['name' => '请输入渠道名称。']);
                    }

                    try {
                        PixPayoutChannel::create(['name' => $name, 'enabled' => true]);
                    } catch (UniqueConstraintViolationException) {
                        throw ValidationException::withMessages(['name' => '该渠道名称已存在。']);
                    }
                })->successNotificationTitle('出款渠道已新增'),
            Action::make('channel_availability')->label('启用或停用渠道')->authorize(self::canManageChannels(...))
                ->schema([
                    Select::make('channel')->label('渠道')->required()->searchable()
                        ->options(fn (): array => PixPayoutChannel::query()->orderBy('name')->get()
                            ->mapWithKeys(fn (PixPayoutChannel $channel): array => [
                                $channel->id => $channel->name.($channel->enabled ? '（启用）' : '（停用）'),
                            ])->all()),
                    Toggle::make('enabled')->label('启用此渠道')->default(true),
                ])->action(function (array $data): void {
                    abort_unless(self::canManageChannels(), 403);
                    PixPayoutChannel::query()->findOrFail($data['channel'])->update(['enabled' => $data['enabled']]);
                })->successNotificationTitle('渠道状态已保存'),
        ];
    }

    private static function providerAction(string $driver): Action
    {
        $definition = ExternalPixChannel::PROVIDERS[$driver];

        return Action::make('configure_'.$driver)->label($definition['label'])->authorize(self::canManageChannels(...))
            ->fillForm(function () use ($driver, $definition): array {
                $channel = PixPayoutChannel::query()->where('driver', $driver)->first();
                $stored = $channel?->configuration ?? [];
                $settings = [
                    'enabled' => $channel?->enabled ?? false,
                    'merchant' => $stored['merchant'] ?? null,
                    'callback_ips' => implode("\n", $stored['callback_ips'] ?? explode(' ', $definition['ips'])),
                    'min_cents' => $stored['min_cents'] ?? null,
                    'max_cents' => $stored['max_cents'] ?? null,
                    'callback' => $channel === null ? '首次保存后生成' : route('pix.provider-callback', ['channel' => $channel->id]),
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
            ])->action(function (array $data) use ($driver, $definition): void {
                abort_unless(self::canManageChannels(), 403);
                try {
                    DB::transaction(function () use ($data, $driver, $definition): void {
                        $channel = PixPayoutChannel::query()->where('driver', $driver)->lockForUpdate()->first();
                        $settings = ExternalPixChannel::validateConfiguration(array_replace($data, [
                            'driver' => $driver,
                            'secret' => filled($data['secret'] ?? null) ? $data['secret'] : ($channel?->configuration['secret'] ?? null),
                            'callback_ips' => preg_split('/\s+/', trim($data['callback_ips']), -1, PREG_SPLIT_NO_EMPTY),
                        ]));
                        ($channel ?? new PixPayoutChannel(['name' => $definition['label'], 'driver' => $driver]))
                            ->fill(['configuration' => $settings, 'enabled' => (bool) $data['enabled']])->save();
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
        abort_unless(PixWithdrawalResource::canViewAny(), 403);
    }
}
