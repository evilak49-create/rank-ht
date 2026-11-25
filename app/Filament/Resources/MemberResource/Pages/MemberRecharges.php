<?php

namespace App\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\MemberResource;
use App\Models\Member;
use App\Models\PlatformBinding;
use App\Platforms\PlatformRecharges;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class MemberRecharges extends Page
{
    protected static string $resource = MemberResource::class;

    protected static ?string $title = '平台充值记录';

    protected string $view = 'filament.members.recharges';

    #[Locked]
    public int $memberId;

    #[Locked]
    public int $bindingId;

    #[Locked]
    public array $recharges = [];

    #[Locked]
    public string $accountLabel;

    #[Locked]
    public ?string $queryError = null;

    public function mount(int $record, int $binding): void
    {
        $this->memberId = $record;
        $this->bindingId = $binding;
        $this->loadPage(1);
    }

    public function hydrate(): void
    {
        abort_unless(MemberResource::canViewAny(), 403);
    }

    public function loadPage(int $page): void
    {
        abort_unless(MemberResource::canViewAny(), 403);
        abort_unless($page >= 1 && $page <= PlatformRecharges::MAX_PAGE, 404);
        $member = Member::query()->findOrFail($this->memberId);
        $binding = PlatformBinding::query()->whereBelongsTo($member)->with('platform')->findOrFail($this->bindingId);
        $this->accountLabel = $binding->platform->name.' · '.$binding->accountName();
        $this->queryError = null;
        $this->recharges = [];

        try {
            $this->recharges = app(PlatformRecharges::class)->list($member, (string) $binding->id, $page);
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [404, 409, 503], true)) {
                throw $exception;
            }
            $this->queryError = '暂时无法获取充值记录，请稍后重试。';
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label('返回会员详情')->url(fn (): string => MemberResource::getUrl('view', ['record' => $this->memberId])),
        ];
    }
}
