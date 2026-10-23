<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Auth\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the 5 RBAC v2 system roles into the CURRENT tenant schema with
 * EXACTLY the permissions their hardcoded role checks grant today, so no
 * user gains or loses any access when RBAC v2 lands (see the plan doc's
 * "Default seed — behaviour must not change on day 1" and the Wave 1
 * catalog table for the per-permission derivation).
 *
 * Idempotent and safe to re-run on the live `tenantswecom` schema:
 *   - roles are matched by `name` and created only if missing;
 *   - permission rows are (re)seeded ONLY for a role that currently has
 *     ZERO of them — a role an admin has since customised via the Wave 3
 *     matrix UI is left completely untouched. Nothing is ever deleted here.
 *
 * Reached three ways, all calling the same code: the create-roles-tables
 * migration's companion seed migration (covers existing tenants on
 * `tenants:migrate`), Database\Seeders\TenantDatabaseSeeder (covers newly
 * provisioned tenants), and `php artisan cncms:seed-default-roles`
 * (App\Console\Commands\SeedDefaultRoles — manual re-run).
 */
class DefaultRolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::definitions() as $name => $definition) {
            $role = Role::query()->firstOrCreate(
                ['name' => $name],
                [
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'is_system' => true,
                    'is_super' => $definition['is_super'] ?? false,
                ],
            );

            // Never re-touch a role whose matrix an admin may have already
            // edited. A freshly-created role, or one seeded before the
            // permission pass ever ran, has zero rows and gets populated.
            if ($role->permissions()->count() === 0 && $definition['permissions'] !== []) {
                $role->syncPermissions($definition['permissions']);
            }
        }
    }

    /**
     * @return array<string, array{label: string, description: string, is_super?: bool, permissions: list<string>}>
     */
    public static function definitions(): array
    {
        return [
            'super' => [
                'label' => 'Owner',
                'description' => 'Full, unconditional access. Bypasses every permission check (Gate::before); its permission list is ignored.',
                'is_super' => true,
                'permissions' => [], // is_super — bypass, no rows needed
            ],
            'admin' => [
                'label' => 'Administrator',
                'description' => 'Every permission in the catalog except approving opening balances (owner-only by default). Office administration, including user and role management.',
                'permissions' => self::adminPermissions(),
            ],
            'manager' => [
                'label' => 'Manager',
                'description' => 'Day-to-day operations: customers, payments, zones, agents, complaints, arrears approval, reports, audit.',
                'permissions' => self::managerPermissions(),
            ],
            'agent' => [
                'label' => 'Field agent',
                'description' => 'Field collections: record payments, view manuscripts and their own zone, submit complaints and arrears requests.',
                'permissions' => self::agentPermissions(),
            ],
            'cashier' => [
                'label' => 'Cashier',
                'description' => 'Receives collectors\' daily remittances, records walk-in payments, pays out approved vouchers and closes the till each day.',
                'permissions' => self::cashierPermissions(),
            ],
            'worker' => [
                'label' => 'Worker',
                'description' => 'Minimal read access plus logging complaints and arrears requests. Front-desk payment recording is a separate per-user flag, not this role.',
                'permissions' => self::workerPermissions(),
            ],
        ];
    }

    /**
     * The `super,admin,manager` role set — every Policy method whose
     * current isAnyOf() names exactly those three (or a subset that
     * includes manager). See the Wave 1 catalog table.
     *
     * @return list<string>
     */
    /**
     * The whole catalog minus `opening_balances.approve`: opening balances
     * are entered by admins/managers and approved by the owner (super
     * bypasses every check), so no system role is seeded with the approval
     * step — it is granted deliberately through Roles & permissions.
     *
     * @return list<string>
     */
    private static function adminPermissions(): array
    {
        return array_values(array_diff(Permission::values(), [Permission::OpeningBalancesApprove->value]));
    }

    private static function managerPermissions(): array
    {
        return [
            Permission::CustomersView->value,
            Permission::CustomersCreate->value,
            Permission::CustomersUpdate->value,
            Permission::CustomersDelete->value,
            Permission::CustomersArchive->value,
            Permission::CustomersPrintBill->value,
            Permission::CustomersChangeStatus->value,
            Permission::CustomersStatusBoard->value,
            Permission::CustomersEligibilityBoard->value,

            Permission::PaymentsView->value,
            Permission::PaymentsCreate->value,
            Permission::PaymentsUpdate->value,
            Permission::PaymentsVerify->value,
            Permission::PaymentsIssueReceipt->value,

            Permission::ManuscriptsView->value,
            Permission::ManuscriptsExport->value,
            Permission::ManuscriptsSendBill->value,

            Permission::ZonesView->value,
            Permission::ZonesManage->value,

            Permission::AgentsView->value,
            Permission::AgentsManage->value,

            Permission::BranchesView->value,

            Permission::ExpendituresView->value,
            Permission::ExpendituresCreate->value,
            Permission::ExpendituresDashboard->value,
            Permission::BudgetsManage->value,

            Permission::ReportsView->value,
            Permission::ReportsExport->value,

            Permission::ComplaintsView->value,
            Permission::ComplaintsCreate->value,
            Permission::ComplaintsResolve->value,
            Permission::ComplaintsAssign->value,

            Permission::ArrearsView->value,
            Permission::ArrearsRequest->value,
            Permission::ArrearsApprove->value,

            Permission::InstallationsManage->value,
            Permission::InstallationsActivate->value,

            Permission::OpeningBalancesView->value,
            Permission::OpeningBalancesEnter->value,

            Permission::ReceiptsManageStock->value,
            Permission::CashView->value,
            Permission::CashRemit->value,
            Permission::VouchersRequest->value,
            Permission::VouchersApprove->value,

            Permission::AuditView->value,

            Permission::CompanyView->value,
        ];
    }

    /**
     * The `super,admin,manager,agent` role set. Note the deliberate
     * exclusions: `customers.change_status` (agent only gets zone-scoped
     * disconnect, an OR-branch) and `payments.verify` (same — an agent
     * verifies only within their own zone, enforced separately).
     *
     * @return list<string>
     */
    private static function agentPermissions(): array
    {
        return [
            Permission::CustomersView->value,
            Permission::CustomersPrintBill->value,
            Permission::CustomersEligibilityBoard->value,

            Permission::PaymentsView->value,
            Permission::PaymentsCreate->value,

            Permission::ManuscriptsView->value,
            Permission::ManuscriptsSendBill->value,

            Permission::ZonesView->value,
            Permission::AgentsView->value,
            Permission::BranchesView->value,

            Permission::ExpendituresView->value,
            Permission::ExpendituresCreate->value,

            Permission::ReportsView->value,

            Permission::ComplaintsView->value,
            Permission::ComplaintsCreate->value,

            Permission::ArrearsView->value,
            Permission::ArrearsRequest->value,

            Permission::CashRemit->value,
            Permission::VouchersRequest->value,

            Permission::InstallationsField->value,

            Permission::CompanyView->value,
        ];
    }

    /**
     * The cashier (docs/plans/cash-controls.md): receives the collectors'
     * daily remittances into a till, records walk-in payments, pays out
     * approved vouchers and closes the till each day. Deliberately NOT
     * `vouchers.approve` — whoever pays out never approves (maker-checker).
     *
     * @return list<string>
     */
    private static function cashierPermissions(): array
    {
        return [
            Permission::CustomersView->value,

            Permission::PaymentsView->value,
            Permission::PaymentsCreate->value,
            Permission::PaymentsIssueReceipt->value,

            Permission::ExpendituresView->value,

            Permission::CashView->value,
            Permission::CashRemit->value,
            Permission::CashReceive->value,

            Permission::VouchersRequest->value,
            Permission::VouchersPay->value,

            Permission::CompanyView->value,
        ];
    }

    /**
     * Everything that is unconditionally `true` in a Policy today (every
     * role, worker included) — plus complaints.create and arrears.request,
     * which are also ungated. This is exactly the worker's current surface.
     *
     * @return list<string>
     */
    private static function workerPermissions(): array
    {
        return [
            Permission::CustomersView->value,
            Permission::PaymentsView->value,
            Permission::ZonesView->value,
            Permission::AgentsView->value,
            Permission::BranchesView->value,
            Permission::ExpendituresView->value,
            Permission::ComplaintsView->value,
            Permission::ComplaintsCreate->value,
            Permission::ArrearsView->value,
            Permission::ArrearsRequest->value,
            Permission::CompanyView->value,
        ];
    }
}
