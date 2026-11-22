<?php

namespace App\Http\Controllers;

use App\Models\ClaimEvidence;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\ProtectionClaim;
use App\Models\ProtectionTerm;
use App\Models\User;
use App\Protection\ClaimEvidenceStorage;
use App\Protection\ProtectionClaims;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProtectionController extends Controller
{
    public function publicTerms(int $platform): JsonResponse
    {
        return $this->currentTerms(Platform::query()->available()->findOrFail($platform)->id);
    }

    public function bindingTerms(Request $request, int $binding): JsonResponse
    {
        return $this->currentTerms($this->ownedBindings($request)->findOrFail($binding)->platform_id);
    }

    public function bindings(Request $request): JsonResponse
    {
        $bindings = $this->ownedBindings($request)->with('platform:id,name')->latest('id')->paginate(20);

        return $this->privateJson($bindings->through(fn (PlatformBinding $binding): array => [
            'id' => $binding->id, 'platform_id' => $binding->platform_id, 'platform_name' => $binding->platform->name,
            'platform_account_id' => $binding->platform_account_id,
            'masked_platform_account' => ProtectionClaims::maskAccount($binding->platform_account_id),
            'protection_status' => $binding->protection_status,
        ]));
    }

    public function index(Request $request): JsonResponse
    {
        $claims = ProtectionClaim::query()->whereBelongsTo($request->user())->latest('id')->paginate(20);

        return $this->privateJson($claims->through(fn (ProtectionClaim $claim): array => $this->claimData($claim, detail: false)));
    }

    public function store(Request $request, ProtectionClaims $claims): JsonResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'binding_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/\A(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?\z/'],
            'withdrawn_at' => [
                'required', 'string', 'date', 'after_or_equal:1000-01-02 UTC', 'before_or_equal:now',
                'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/',
            ],
            'description' => ['required', 'string', 'max:5000'],
            'evidence' => ['required', 'array', 'list', 'min:1', 'max:'.ClaimEvidenceStorage::MAX_FILES],
            'evidence.*' => ['required', File::types(['jpg', 'jpeg', 'png', 'webp'])->max(ClaimEvidenceStorage::MAX_KIB), 'dimensions:max_width=8192,max_height=8192'],
        ]);
        $claim = $claims->submit($request->user(), $data);

        return $this->privateJson(['data' => $this->claimData($claim->load('evidence'))], $claim->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, int $claim): JsonResponse
    {
        $record = ProtectionClaim::query()->whereBelongsTo($request->user())->with('evidence')->findOrFail($claim);

        return $this->privateJson(['data' => $this->claimData($record)]);
    }

    public function memberEvidence(Request $request, int $claim, int $evidence, ClaimEvidenceStorage $storage): StreamedResponse
    {
        $case = ProtectionClaim::query()->whereBelongsTo($request->user())->findOrFail($claim);

        return $storage->show($case->evidence()->findOrFail($evidence));
    }

    public function adminEvidence(Request $request, int $claim, int $evidence, ClaimEvidenceStorage $storage): StreamedResponse
    {
        $staff = $request->user('web');
        abort_unless($staff instanceof User && $staff->hasPermission('protection.claims.view'), 403);
        $file = ClaimEvidence::query()->where('protection_claim_id', $claim)->findOrFail($evidence);

        return $storage->show($file);
    }

    private function currentTerms(int $platform): JsonResponse
    {
        $terms = ProtectionTerm::query()->where('platform_id', $platform)->latest('id')->firstOrFail();

        return response()->json(['data' => $terms->only(['id', 'platform_id', 'title', 'body', 'created_at'])])
            ->header('Cache-Control', 'private, no-store');
    }

    private function ownedBindings(Request $request): Builder
    {
        return PlatformBinding::query()->where('member_id', $request->user()->id);
    }

    private function claimData(ProtectionClaim $case, bool $detail = true): array
    {
        $data = [
            'id' => $case->id, 'binding_id' => $case->platform_binding_id,
            'platform_id' => $case->platform_id, 'platform_name' => $case->platform_name,
            'platform_account_id' => $case->platform_account_id,
            'masked_platform_account' => ProtectionClaims::maskAccount($case->platform_account_id),
            'protection_status' => $case->protection_status, 'term_version_id' => $case->protection_term_id,
            'amount_cents' => $case->amount_cents, 'currency' => 'BRL', 'withdrawn_at' => $case->withdrawn_at,
            'created_at' => $case->created_at,
        ];
        if ($detail) {
            $data['description'] = $case->description;
            $data['evidence'] = $case->evidence->map(fn (ClaimEvidence $file): array => [
                'id' => $file->id, 'position' => $file->position, 'mime' => $file->mime,
                'url' => $file->accessUrl('member'),
            ]);
        }

        return $data;
    }

    private function privateJson(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
