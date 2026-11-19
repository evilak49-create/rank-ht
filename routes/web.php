<?php

use App\Http\Controllers\ProtectionController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/member.php';

Route::get('admin/protection-claims/{claim}/evidence/{evidence}', [ProtectionController::class, 'adminEvidence'])
    ->whereNumber(['claim', 'evidence'])->middleware(['auth:web', 'signed:relative'])->name('claim-evidence.admin');
