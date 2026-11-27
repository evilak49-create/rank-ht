<?php

use App\Http\Controllers\ProtectionController;
use App\Promotion\PromotionAttribution;
use Illuminate\Support\Facades\Route;

require __DIR__.'/member.php';

Route::get('go/{channel:code}', [PromotionAttribution::class, 'visit'])
    ->where('channel', '[a-f0-9]{32}')->name('promotion.visit');

Route::get('admin/protection-claims/{claim}/evidence/{evidence}', [ProtectionController::class, 'adminEvidence'])
    ->whereNumber(['claim', 'evidence'])->middleware(['auth:web', 'signed:relative'])->name('claim-evidence.admin');
