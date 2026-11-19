<?php

namespace App\Auth;

class AdminPermissions
{
    public const LABELS = [
        'members.view' => '查看会员',
        'members.change-status' => '禁用或恢复会员',
        'members.change-phone' => '修改会员手机号',
        'members.reset-password' => '重置会员密码',
        'members.adjust-points' => '调整会员积分',
        'points.view' => '查看积分流水',
        'points.manage-rules' => '管理积分规则',
        'platforms.view' => '查看平台',
        'platforms.edit' => '管理平台资料',
        'platforms.configure-query' => '管理平台接口',
        'platforms.test-query' => '测试平台接口',
        'platforms.change-status' => '停用或启用平台',
        'platforms.view-reviews' => '查看平台评价',
        'platforms.moderate-reviews' => '审核平台评价',
        'platforms.generate-reviews' => '批量生成 AI 平台说明',
        'shop.manage-products' => '管理商品和库存',
        'shop.view-orders' => '查看商品订单',
        'shop.ship-orders' => '登记发货',
        'shop.cancel-orders' => '取消商品订单',
        'funds.view' => '查看平台资金订单',
        'funds.review' => '审核平台资金订单',
        'funds.resume' => '恢复平台资金发放或查单',
        'activities.manage-checkin' => '管理签到规则',
        'activities.view-checkin' => '查看签到',
        'red-packets.manage' => '管理红包规则',
        'red-packets.view' => '查看红包和邀请记录',
        'pix.view' => '查看 Pix 提现',
        'pix.review' => '审核 Pix 提现或查单',
        'pix.resume' => '恢复 Pix 出款',
        'protection.claims.view' => '查看申诉案件和凭证',
        'protection.terms.manage' => '发布保障条款',
        'protection.eligibility.manage' => '管理保障资格',
        'content.manage' => '管理 Banner、公告和帮助中心',
        'support.manage' => '管理客服和常见问题',
        'system.manage-business-timezone' => '管理业务时区',
        'system.manage-oss' => '管理 OSS 配置',
        'system.manage-ai' => '管理 AI 渠道',
        'system.manage-admins' => '管理后台账号',
        'system.manage-roles' => '管理后台角色和授权',
        'system.view-admin-audit' => '查看后台授权审计',
    ];
}
