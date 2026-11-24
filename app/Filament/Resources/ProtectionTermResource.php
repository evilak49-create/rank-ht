<?php

namespace App\Filament\Resources;

use App\Content\Publishing;
use App\Filament\Resources\ProtectionTermResource\Pages\ListProtectionTerms;
use App\Models\ProtectionTerm;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ProtectionTermResource extends Resource
{
    protected static ?string $model = ProtectionTerm::class;

    protected static ?string $pluralModelLabel = '保障条款';

    protected static string|\UnitEnum|null $navigationGroup = '平台管理';

    protected static ?int $navigationSort = 40;

    public static function canViewAny(): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof User && $staff->hasPermission('protection.terms.manage');
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('platform'))->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('版本')->sortable(),
            TextColumn::make('scope')->label('适用范围')
                ->state(fn (ProtectionTerm $record): string => $record->platform?->name ?? '全站'),
            TextColumn::make('title')->label('标题')->searchable(),
            TextColumn::make('created_at')->label('发布时间')->dateTime()->sortable(),
        ])->recordActions([
            Action::make('read')->label('查看条款')->authorize(fn () => self::canViewAny())
                ->modalDescription(fn (ProtectionTerm $record): HtmlString => new HtmlString($record->editorBody()))
                ->modalSubmitAction(false)->modalCancelActionLabel('关闭'),
            Action::make('edit')->label('编辑')->authorize(fn () => self::canViewAny())
                ->modalDescription('保存后会发布为新的全站版本，已有申诉仍引用原版本。')
                ->schema(self::fields())
                ->fillForm(fn (ProtectionTerm $record): array => [
                    'title' => $record->title, 'body_html' => $record->editorBody(),
                ])
                ->action(fn (Action $action, array $data) => ActionForm::submit($action, fn () => self::saveVersion($data)))
                ->successNotificationTitle('已发布修改后的全站条款'),
            Action::make('delete')->label('删除')->color('danger')->requiresConfirmation()
                ->authorize(fn () => self::canViewAny())
                ->modalDescription('删除当前版本后，前台会停止展示条款。已关联的申诉仍保留条款历史。')
                ->action(function (ProtectionTerm $record): void {
                    abort_unless(self::canViewAny(), 403);
                    $record->delete();
                })->successNotificationTitle('已删除保障条款'),
        ]);
    }

    public static function publishAction(): Action
    {
        return Action::make('publish')->label('发布条款版本')->authorize(fn () => self::canViewAny())
            ->modalDescription('此版本适用于所有平台，标题及正文中的变量会按访问者及当前平台替换。')
            ->schema(self::fields())
            ->action(fn (Action $action, array $data) => ActionForm::submit($action, fn () => self::saveVersion($data)))
            ->successNotificationTitle('已发布全站保障条款');
    }

    private static function fields(): array
    {
        return [
            TextInput::make('title')->label('标题')->required()->maxLength(160),
            RichEditor::make('body_html')->label('条款正文')->required()->fileAttachments(false)
                ->toolbarButtons([
                    ['h2', 'h3', 'bold', 'italic', 'underline'],
                    ['orderedList', 'bulletList', 'blockquote', 'link'],
                    ['undo', 'redo'],
                ])->helperText('可用变量：'.implode('、', ProtectionTerm::PLACEHOLDERS)),
        ];
    }

    private static function saveVersion(array $data): void
    {
        abort_unless(self::canViewAny(), 403);
        $data = Validator::make($data, [
            'title' => 'required|string|max:160',
            'body_html' => 'required|string|max:30000',
        ])->validate();
        $html = app(Publishing::class)->sanitize($data['body_html']);
        $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
        if (blank(str_replace("\u{00A0}", ' ', $plain))) {
            throw ValidationException::withMessages(['body_html' => '请输入条款正文。']);
        }

        ProtectionTerm::create([
            'title' => $data['title'], 'body' => mb_substr($plain, 0, 12000),
            'body_html' => $html, 'user_id' => Filament::auth()->id(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListProtectionTerms::route('/')];
    }
}
