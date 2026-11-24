<?php

namespace App\Filament\Resources\ProtectionClaimResource\Pages;

use App\Filament\Resources\ProtectionClaimResource;
use App\Models\User;
use App\Protection\ProtectionClaims;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;

class ViewProtectionClaim extends ViewRecord
{
    protected static string $resource = ProtectionClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->resolutionAction('resolved', '标记已解决'),
            $this->resolutionAction('unresolved', '标记未解决'),
        ];
    }

    private function resolutionAction(string $outcome, string $label): Action
    {
        return Action::make($outcome)->label($label)->requiresConfirmation()
            ->authorize(fn (): bool => Filament::auth()->user()?->hasPermission('protection.claims.resolve') ?? false)
            ->visible(fn (): bool => $this->record->case_status === 'pending')
            ->action(function () use ($outcome): void {
                $operator = Filament::auth()->user();
                abort_unless($operator instanceof User, 403);
                app(ProtectionClaims::class)->resolve($this->record, $operator, $outcome);
                $this->record->refresh();
            });
    }

    public function hydrate(): void
    {
        abort_unless(ProtectionClaimResource::canViewAny(), 403);
    }
}
