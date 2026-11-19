<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

#[Fillable(['disk', 'object_key', 'mime', 'size_bytes', 'position'])]
#[Hidden(['disk', 'object_key'])]
class ClaimEvidence extends Model
{
    public $timestamps = false;

    protected $table = 'claim_evidence';

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ProtectionClaim::class, 'protection_claim_id');
    }

    public function accessUrl(string $viewer): string
    {
        return URL::temporarySignedRoute('claim-evidence.'.$viewer, now()->addMinutes(5), [
            'claim' => $this->protection_claim_id, 'evidence' => $this->id,
        ], absolute: false);
    }
}
