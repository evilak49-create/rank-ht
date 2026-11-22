<?php

namespace App\Filament\Resources\PlatformResource\Pages;

use App\Filament\Resources\PlatformResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ListPlatforms extends ListRecords
{
    protected static string $resource = PlatformResource::class;

    public function hydrate(): void
    {
        abort_unless(PlatformResource::canViewAny(), 403);
    }

    public function exception(Throwable $e): void
    {
        if (! $e instanceof ValidationException) {
            return;
        }

        foreach (array_keys($e->errors()) as $field) {
            if (Str::is('mountedActions.*.data.featured_position', $field)) {
                Notification::make()->danger()->title('首页精选位置不可用')
                    ->body('请在「平台资料」中选择 1 到 10 的空位，或留空后继续。')
                    ->persistent()->send();

                return;
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [PlatformResource::createPlatformAction()];
    }
}
