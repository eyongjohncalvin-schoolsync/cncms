<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Agent;
use App\Models\Scopes\BranchScope;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\ZoneAgent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Deleting a staff account (owner, 2026-10-26): only an account with no
 * history — created by mistake, never used. Anyone whose name is on a
 * payment, verification, bon, till, audit entry or any other record can
 * only be deactivated, so that history never points at nobody.
 *
 * "History" is found by scanning every user column of the tenant schema
 * (any `*_by`, `*user_id`, `*_by_id` column, or one with a foreign key to
 * users), so a table added later is covered without touching this class.
 * The few columns that are only the account's own setup — its membership,
 * agent profile and zones, salary, devices, sign-in log, notifications —
 * are removed with it instead.
 */
class StaffRemovalService
{
    /**
     * Columns that are the account's own setup, not something it did.
     *
     * @var list<string>
     */
    private const SETUP_COLUMNS = [
        'tenant_users.user_id',
        'agents.user_id',
        'technician_zones.user_id',
        'staff_assignments.user_id',
        'staff_salaries.user_id',
        'device_push_tokens.user_id',
        'field_devices.user_id',
        'notification_recipients.user_id',
        'notifications.recipient_user_id',
        'user_activitylogs.user_id',
    ];

    public function __construct(private readonly AccountSecurityService $account) {}

    /**
     * The ids among $userIds that have any history — one query for the whole list.
     *
     * @param  list<int>  $userIds
     * @return array<int, true>
     */
    public function usersWithHistory(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $selects = [];
        $bindings = [];

        foreach ($this->historyColumns() as [$table, $column]) {
            $selects[] = "select \"{$column}\" as user_id from \"{$table}\" where \"{$column}\" in ({$placeholders})";
            array_push($bindings, ...$userIds);
        }

        if ($selects === []) {
            return [];
        }

        return collect(DB::select('select distinct user_id from ('.implode(' union all ', $selects).') history', $bindings))
            ->mapWithKeys(fn (object $row): array => [(int) $row->user_id => true])
            ->all();
    }

    /**
     * Where $user has history, as a readable place name ("Payments"), or null.
     */
    public function historyOf(User $user): ?string
    {
        foreach ($this->historyColumns() as [$table, $column]) {
            if (DB::table($table)->where($column, $user->id)->exists()) {
                return Str::headline($table);
            }
        }

        return null;
    }

    /**
     * Removes the account and its setup rows in one transaction, then signs
     * it out everywhere.
     *
     * @throws ValidationException when the account may not be deleted
     */
    public function delete(TenantUser $tenantUser, User $actor): void
    {
        $user = $tenantUser->user;

        $refusal = match (true) {
            $user->id === $actor->id => 'You cannot delete your own account.',
            (bool) $user->is_landlord => 'A platform owner account cannot be deleted here.',
            ($place = $this->historyOf($user)) !== null => "{$user->name} has history ({$place}), so the account can only be deactivated.",
            default => null,
        };

        if ($refusal !== null) {
            throw ValidationException::withMessages(['user' => $refusal]);
        }

        // One connection, one transaction: the central row is deleted through
        // the tenant connection (`public.users`), so a membership in another
        // tenant (a foreign key to users) makes the whole delete fail cleanly.
        DB::transaction(function () use ($tenantUser, $user): void {
            $agentIds = Agent::query()->withoutGlobalScope(BranchScope::class)->where('user_id', $user->id)->pluck('id');
            ZoneAgent::query()->withoutGlobalScopes()->whereIn('agent_id', $agentIds)->delete();
            Agent::query()->withoutGlobalScope(BranchScope::class)->whereKey($agentIds)->delete();

            foreach (self::SETUP_COLUMNS as $setupColumn) {
                [$table, $column] = explode('.', $setupColumn);

                if ($table !== 'tenant_users' && $table !== 'agents' && DB::getSchemaBuilder()->hasTable($table)) {
                    DB::table($table)->where($column, $user->id)->delete();
                }
            }

            // Through Eloquent, so the audit log records who removed whom.
            $tenantUser->delete();

            DB::delete('delete from public.users where id = ?', [$user->id]);
        });

        $this->account->signOutEverywhere($user);
    }

    /**
     * Every user column of the current tenant schema except the setup ones.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function historyColumns(): array
    {
        $rows = DB::select(<<<'SQL'
            select c.relname as table_name, a.attname as column_name
            from pg_attribute a
            join pg_class c on c.oid = a.attrelid
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = current_schema()
              and c.relkind in ('r', 'p') and not c.relispartition
              and a.attnum > 0 and not a.attisdropped
              and format_type(a.atttypid, a.atttypmod) in ('bigint', 'integer')
              and (a.attname like '%\_by' or a.attname like '%user\_id' or a.attname like '%\_by\_id'
                   or exists (select 1 from pg_constraint k
                              where k.conrelid = c.oid and k.contype = 'f'
                                and a.attnum = any (k.conkey) and k.confrelid = 'public.users'::regclass))
            order by 1, 2
            SQL);

        return collect($rows)
            ->reject(fn (object $row): bool => in_array("{$row->table_name}.{$row->column_name}", self::SETUP_COLUMNS, true))
            ->map(fn (object $row): array => [$row->table_name, $row->column_name])
            ->values()
            ->all();
    }
}
