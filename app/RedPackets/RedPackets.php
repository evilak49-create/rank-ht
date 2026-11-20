<?php

namespace App\RedPackets;

use App\Models\Member;
use App\Models\RedPacketRound;
use App\Models\RedPacketRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class RedPackets
{
    public const PERMISSIONS = ['red-packets.manage', 'red-packets.view', 'pix.view', 'pix.review', 'pix.resume'];

    private const DEFAULT_PAGE_COPY = [
        'opening_title' => '现金红包',
        'opening_description' => null,
        'open_label' => '拆开红包',
        'balance_title' => '累计红包',
        'invite_label' => '邀请好友',
        'rules_title' => '活动规则',
        'rules_html' => '<p>拆开红包后可获得本轮提现目标金额 85% 至 95% 的初始奖励。好友通过邀请链接注册后，每次按剩余差额的 50% 至 70% 发放奖励。差额低于 R$ 0,10 时，下次有效邀请可补齐。</p>'
            .'<p>邀请链接可跨轮次使用。达到本轮目标后产生的有效邀请，将在下一轮结算。达标后可通过 Pix 提现。</p>',
    ];


    public function __construct(private readonly InvitationRewards $invitations, private readonly RedPacketLedger $ledger) {}

    public function open(Member $member, string $request): RedPacketRound
    {
        return DB::transaction(function () use ($member, $request): RedPacketRound {
            $owner = $this->lockActive($member);
            $request = strtolower($request);
            $reference = DB::table('red_packet_open_requests')
                ->where(['member_id' => $owner->id, 'request_id' => $request])->value('red_packet_round_id');
            if ($reference !== null) {
                return RedPacketRound::findOrFail($reference);
            }

            $round = RedPacketRound::query()->where('member_id', $owner->id)->whereNull('closed_at')->lockForUpdate()->first();
            if ($round === null) {
                $rules = RedPacketRule::query()->latest('id')->firstOrFail();
                $bps = random_int(8500, 9500);
                $initial = intdiv($rules->threshold_cents * $bps, 10000);
                $round = RedPacketRound::create([
                    'member_id' => $owner->id, 'red_packet_rule_id' => $rules->id, 'threshold_cents' => $rules->threshold_cents,
                    'initial_bps' => $bps, 'initial_cents' => $initial, 'balance_cents' => $initial, 'frozen_cents' => 0,
                ]);
                $this->ledger->record($round, 'initial', $initial, calculation: ['bps' => $bps, 'threshold_cents' => $rules->threshold_cents]);
                $this->invitations->settlePending($round);
            }
            DB::table('red_packet_open_requests')->insert([
                'member_id' => $owner->id, 'request_id' => $request, 'red_packet_round_id' => $round->id,
            ]);

            return $round;
        }, 3);
    }

    public function inviteCode(Member $member): string
    {
        if ($member->invite_code !== null) {
            return $member->invite_code;
        }

        return DB::transaction(function () use ($member): string {
            $owner = $this->lockActive($member);
            if ($owner->invite_code === null) {
                $owner->forceFill(['invite_code' => bin2hex(random_bytes(16))])->save();
            }

            return $owner->invite_code;
        }, 3);
    }

    public function saveCpf(Member $member, string $value): string
    {
        $cpf = Cpf::normalize($value);
        DB::transaction(fn () => $this->lockActive($member)->forceFill(['pix_cpf' => $cpf])->save(), 3);

        return $cpf;
    }

    public function saveThreshold(string $value, User $operator): RedPacketRule
    {
        abort_unless($operator->hasPermission('red-packets.manage'), 403);
        if (! preg_match('/\A([0-9]{1,6})(?:\.([0-9]{1,2}))?\z/', $value, $matches)) {
            throw ValidationException::withMessages(['threshold' => '请输入最多两位小数的 BRL 金额。']);
        }
        $cents = (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($cents < 1) {
            throw ValidationException::withMessages(['threshold' => '提现门槛必须大于零。']);
        }

        return $this->version(['threshold_cents' => $cents], $operator);
    }

    public function pageCopy(): array
    {
        $active = RedPacketRule::query()->latest('id')->firstOrFail();
        $copy = array_intersect_key($active->page_copy ?? [], self::DEFAULT_PAGE_COPY) + self::DEFAULT_PAGE_COPY;
        $copy['rules_html'] = $this->sanitizeRules($copy['rules_html']);

        return $copy;
    }

    public function savePageCopy(array $input, User $operator): RedPacketRule
    {
        abort_unless($operator->hasPermission('red-packets.manage'), 403);
        $copy = Validator::make($input, [
            'opening_title' => ['required', 'string', 'max:80'],
            'opening_description' => ['nullable', 'string', 'max:240'],
            'open_label' => ['required', 'string', 'max:30'],
            'balance_title' => ['required', 'string', 'max:80'],
            'invite_label' => ['required', 'string', 'max:30'],
            'rules_title' => ['required', 'string', 'max:80'],
            'rules_html' => ['required', 'string', 'max:20000'],
        ])->validate();
        $copy['rules_html'] = $this->sanitizeRules($copy['rules_html']);
        $plain = html_entity_decode(strip_tags($copy['rules_html']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (! preg_match('/[^\s\x{00a0}]/u', $plain)) {
            throw ValidationException::withMessages(['rules_html' => '请填写可显示的活动规则。']);
        }

        return $this->version(['page_copy' => $copy], $operator);
    }

    private function sanitizeRules(string $html): string
    {
        $config = (new HtmlSanitizerConfig)->allowElement('a', ['href', 'title'])
            ->allowElement('ol', ['start'])->allowLinkSchemes(['https', 'http'])->allowRelativeLinks();
        foreach (['p', 'br', 'h2', 'h3', 'strong', 'b', 'em', 'i', 'u', 'blockquote', 'ul', 'li'] as $element) {
            $config = $config->allowElement($element);
        }

        return (new HtmlSanitizer($config))->sanitize($html);
    }

    private function version(array $changes, User $operator): RedPacketRule
    {
        return DB::transaction(function () use ($changes, $operator): RedPacketRule {
            RedPacketRule::query()->oldest('id')->lockForUpdate()->firstOrFail();
            $active = RedPacketRule::query()->latest('id')->lockForUpdate()->firstOrFail();

            return RedPacketRule::create($changes + ['user_id' => $operator->id] + $active->only(['threshold_cents', 'page_copy']));
        }, 3);
    }

    public function lockActive(Member $member): Member
    {
        $owner = Member::query()->lockForUpdate()->findOrFail($member->id);
        abort_if($owner->disabled_at !== null, 403, '会员账号已禁用。');
        abort_if($owner->session_version !== $member->session_version, 401, '会员登录会话已失效。');

        return $owner;
    }
}
