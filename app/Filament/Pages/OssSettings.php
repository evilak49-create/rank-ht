<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Storage\OssConfiguration;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

class OssSettings extends Page
{
    protected static ?string $title = 'OSS 配置';

    protected static string|\UnitEnum|null $navigationGroup = '系统管理';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.oss-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('system.manage-oss');
    }

    public function mount(): void
    {
        $this->fillPurpose('private');
    }

    public function hydrate(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Select::make('purpose')->label('存储用途')->required()->selectablePlaceholder(false)->live()
                ->options(['private' => '私有申诉凭证', 'public' => '公开图片'])
                ->afterStateUpdated(fn (string $state) => $this->fillPurpose($state)),
            Select::make('provider')->label('渠道')->required()->selectablePlaceholder(false)->live()
                ->options(['aws' => '亚马逊 Amazon S3', 'aliyun' => '阿里云 OSS'])
                ->afterStateUpdated(function (string $state): void {
                    $this->data['endpoint'] = null;
                    $this->data['region'] = $state === 'aliyun' ? 'cn-hangzhou' : 'us-east-1';
                }),
            TextInput::make('bucket')->label('存储桶 Bucket')->required()->maxLength(63),
            TextInput::make('region')->label('地域 Region')->required()->maxLength(32),
            TextInput::make('endpoint')->label('HTTPS 服务端点')->maxLength(512)
                ->placeholder(fn (Get $get): string => $get('provider') === 'aliyun'
                    ? 'https://s3.oss-cn-hangzhou.aliyuncs.com' : '自动使用所选 AWS 地域的服务端点'),
            TextInput::make('public_url')->label('公开访问域名')->maxLength(512)
                ->visible(fn (Get $get): bool => $get('purpose') === 'public'),
            TextInput::make('access_key')->label('Access Key ID')->required()->maxLength(256)->autocomplete(false),
            TextInput::make('secret_key')->label('Secret Key')->required()->maxLength(256)->password()->autocomplete('new-password'),
        ])->columns(2);
    }

    public function save(OssConfiguration $settings): void
    {
        abort_unless(self::canAccess(), 403);

        try {
            $data = $this->form->getState();
            $settings->save($data, Filament::auth()->user());
            unset($this->audit);
            Notification::make()->title('存储验证通过，配置已生效')->success()->send();
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[str_starts_with($field, 'data.') ? $field : 'data.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        } finally {
            $this->data['access_key'] = null;
            $this->data['secret_key'] = null;
        }
    }

    #[Computed]
    public function audit(): array
    {
        return DB::table('oss_audits')->latest('id')->limit(10)
            ->get(['purpose', 'provider', 'status', 'created_at'])->all();
    }

    private function fillPurpose(string $purpose): void
    {
        $location = app(OssConfiguration::class)->active($purpose);
        $this->form->fill([
            'purpose' => $purpose,
            'provider' => $location?->provider ?? 'aws',
            'region' => $location?->region ?? 'us-east-1',
            'bucket' => $location?->bucket,
            'endpoint' => $location?->endpoint,
            'public_url' => $location?->public_url,
        ]);
    }
}
