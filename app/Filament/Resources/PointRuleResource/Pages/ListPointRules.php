<?php

namespace App\Filament\Resources\PointRuleResource\Pages;

use App\Filament\Resources\PointRuleResource;
use Filament\Resources\Pages\ListRecords;

class ListPointRules extends ListRecords
{
    protected static string $resource = PointRuleResource::class;

    public function hydrate(): void
    {
        abort_unless(PointRuleResource::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [PointRuleResource::updateAction(), PointRuleResource::updateRebateAction(), PointRuleResource::updateFundRateAction()];
    }
}
