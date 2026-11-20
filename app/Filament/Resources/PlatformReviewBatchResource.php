<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlatformReviewBatchResource\Pages\ListPlatformReviewBatches;
use App\Models\Platform;
use App\Models\PlatformReviewBatch;
use App\Models\User;
use App\Platforms\PlatformReviewBatches;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlatformReviewBatchResource extends Resource
{
    protected static ?string $model = PlatformReviewBatch::class;

    protected static ?string $pluralModelLabel = 'AI 平台说明批次';

    protected static string|\UnitEnum|null $navigationGroup = '平台与保障';

    protected static ?int $navigationSort = 30;

    private const STATES = ['pending' => '待生成', 'processing' => '生成中', 'succeeded' => '已发布', 'failed' => '失败'];

    private const ERRORS = [
        'provider_unavailable' => 'AI 配置暂不可用',
        'provider_error' => 'DeepSeek 请求失败',
        'invalid_selection' => '返回的条目编号或数量不合格',
        'source_changed' => '生成期间平台简介发生变更',
        'worker_interrupted' => '队列任务多次中断',
    ];

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('platforms.view')
            && $operator->hasPermission('platforms.generate-reviews');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('批次'),
            TextColumn::make('platform.name')->label('平台')->searchable(),
            TextColumn::make('status')->label('状态')->badge()->formatStateUsing(fn (string $state): string => self::STATES[$state]),
            TextColumn::make('quantity')->label('条数'),
            TextColumn::make('model')->label('模型')->placeholder('—'),
            TextColumn::make('operator.username')->label('发起人'),
            TextColumn::make('failure_code')->label('失败原因')->formatStateUsing(fn (string $state): string => self::ERRORS[$state]),
            TextColumn::make('created_at')->label('发起时间')->dateTime(),
            TextColumn::make('finished_at')->label('完成时间')->dateTime(),
        ])->filters([SelectFilter::make('status')->label('状态')->options(self::STATES)])
            ->recordActions([
                self::generateAction('retry')->label('重新发起'),
                Action::make('view_entries')->label('查看发布内容')
                    ->visible(fn (PlatformReviewBatch $record): bool => $record->status === 'succeeded' && PlatformReviewResource::canViewAny())
                    ->url(fn (PlatformReviewBatch $record): string => PlatformReviewResource::getUrl('index', [
                        'filters' => ['batch' => ['value' => $record->id]],
                    ])),
            ])->poll('30s');
    }

    public static function generateAction(string $name = 'generate_descriptions'): Action
    {
        return Action::make($name)->label('生成 AI 平台说明')
            ->authorize(fn (): bool => self::canViewAny())
            ->visible(fn (Platform|PlatformReviewBatch $record): bool => $record instanceof Platform || $record->status === 'failed')
            ->schema([
                Hidden::make('request_id')->required(),
                TextInput::make('quantity')->label('生成条数')->required()->integer()->minValue(10)->maxValue(30)
                    ->helperText('每条取自平台简介中的一项独立说明，前台会标注 AI 平台说明。'),
            ])->fillForm(fn (Platform|PlatformReviewBatch $record): array => [
                'request_id' => (string) Str::uuid(),
                'quantity' => $record instanceof PlatformReviewBatch ? $record->quantity : 10,
            ])->action(function (Action $action, Platform|PlatformReviewBatch $record, array $data): void {
                $platform = $record instanceof PlatformReviewBatch ? $record->platform : $record;
                try {
                    app(PlatformReviewBatches::class)->submit($platform, Filament::auth()->user(), (int) $data['quantity'], $data['request_id']);
                } catch (ValidationException $exception) {
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $errors['mountedActions.'.$action->getNestingIndex().'.data.'.$field] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
                Notification::make()->title('已提交生成批次')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListPlatformReviewBatches::route('/')];
    }
}
