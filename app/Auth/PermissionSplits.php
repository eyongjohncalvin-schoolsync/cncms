<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The permissions split out of borrowed or hardcoded checks (owner,
 * 2026-11-02: "if I create a role, I decide exactly what it sees and
 * does"). Each new permission starts with exactly the roles that could do
 * the thing before — nobody gains or loses anything on the day — and from
 * then on is ticked like any other in Settings → Roles. Used by the
 * migration that tops up existing roles and by DefaultRolesSeeder for new
 * companies, so the two never disagree.
 */
final class PermissionSplits
{
    /**
     * New permission => the permissions that used to grant it (any one is
     * enough).
     *
     * @var array<string, list<string>>
     */
    public const array FROM = [
        'payments.send_receipt' => ['payments.view'],
        'manuscripts.print_bills' => ['manuscripts.export'],
        'compensation.view' => ['installations.manage', 'installations.approve'],
        'compensation.request' => ['installations.manage'],
        'compensation.approve' => ['installations.approve'],
        'cash.manage_accounts' => ['company.update'],
        'cash.close_days' => ['vouchers.approve'],
        'cash.write_off' => ['vouchers.approve'],
        'cash.reconcile' => ['cash.view'],
        'cash.pay_technician_fees' => ['cash.receive'],
        'mobile.use_app' => ['manuscripts.view'],
        'complaints.take_jobs' => ['complaints.resolve'],
    ];

    /**
     * New permissions that used to be a role NAME (super/admin): the admin
     * role starts with them; super holds everything anyway.
     *
     * @var list<string>
     */
    public const array ADMIN_ONLY = [
        'arrears.approve_final',
        'reports.profit_and_loss',
        'company.sign_contract',
    ];

    /**
     * $permissions plus every split permission they used to grant.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public static function derive(array $permissions): array
    {
        foreach (self::FROM as $permission => $sources) {
            if (array_intersect($sources, $permissions) !== []) {
                $permissions[] = $permission;
            }
        }

        return array_values(array_unique($permissions));
    }
}
