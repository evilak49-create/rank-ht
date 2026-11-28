<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['host', 'purpose'])]
class SiteDomain extends Model
{
    public const CHANNEL = 'channel';

    public const PWA = 'pwa';

    public const PURPOSES = [self::CHANNEL => '渠道', self::PWA => 'PWA'];

    public const MANAGE = 'system.manage-domains';
}
