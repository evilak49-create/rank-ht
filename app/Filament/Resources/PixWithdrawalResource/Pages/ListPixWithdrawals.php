<?php

namespace App\Filament\Resources\PixWithdrawalResource\Pages;

use App\Filament\Resources\PixWithdrawalResource;
use App\Models\PixPayoutChannel;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class ListPixWithdrawals extends ListRecords
{
    protected static string $resource = PixWithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
