<?php

declare(strict_types=1);

use App\Support\UpperCaseLabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Brings existing rows in line with App\Support\UpperCaseLabel, which the
 * models now apply on every write: zone and branch names, customer names and
 * locations — trimmed, inner whitespace collapsed, mb_strtoupper()'d.
 *
 * Normalised in PHP, not with SQL upper(): Postgres' upper() follows the
 * database locale, and on some (e.g. Windows' "English_United States.1252")
 * it leaves "é" alone, which would disagree with what the app stores.
 *
 * Zones and branches (names are unique): rows whose names collide once
 * normalised ("Thr01" and "THR01") are left exactly as they are and listed in
 * a warning (log + console) for the owner to resolve by hand — nothing is
 * merged and the migration never fails on them. A functional unique index on
 * upper(name) is added only when no collision remains; otherwise it is
 * skipped with a warning (re-run the SQL by hand after resolving them).
 *
 * Customers (never unique — homonyms are real people): chunked by id, one
 * set-based UPDATE per chunk, soft-deleted rows included, no model events and
 * no audit rows. updated_at is bumped on changed rows only, so mobile devices
 * (delta sync on updated_at) pick up the new labels.
 *
 * A value that would no longer fit its column once normalised (upper-casing
 * can lengthen, e.g. "ß" -> "SS") is left unchanged and warned about.
 */
return new class extends Migration
{
    private const int CUSTOMER_CHUNK = 1000;

    public function up(): void
    {
        $this->normaliseUniqueNames('zones', 25);
        $this->normaliseUniqueNames('branches', 50);
        $this->normaliseCustomers();

        // The hour-long dropdown caches (ZoneService::all(), BranchService::all())
        // would otherwise keep serving the old casing.
        Cache::forget('zones:all');
        Cache::forget('branches:all');
    }

    /**
     * Only the functional indexes can be undone. The old casing and spacing
     * were overwritten and are not kept anywhere, so names stay upper-case —
     * which is harmless: the app stores them that way regardless.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS zones_name_upper_unique');
        DB::statement('DROP INDEX IF EXISTS branches_name_upper_unique');
    }

    private function normaliseUniqueNames(string $table, int $maxLength): void
    {
        $now = now();
        $groups = DB::table($table)->orderBy('id')->get(['id', 'name'])
            ->groupBy(fn (object $row): string => UpperCaseLabel::normalise($row->name) ?? '');

        foreach ($groups as $normalised => $rows) {
            $normalised = (string) $normalised;

            if ($normalised === '') {
                continue;
            }

            if ($rows->count() > 1) {
                $this->warn("{$table}: left unchanged, these names collide as \"{$normalised}\": ".$this->describe($rows).'. Rename or merge them by hand.');

                continue;
            }

            $row = $rows->first();

            if ($row->name === $normalised) {
                continue;
            }

            if (mb_strlen($normalised) > $maxLength) {
                $this->warn("{$table}: left unchanged, \"{$normalised}\" (id {$row->id}) would be longer than {$maxLength} characters.");

                continue;
            }

            DB::table($table)->where('id', $row->id)->update(['name' => $normalised, 'updated_at' => $now]);
        }

        $this->addUpperUniqueIndex($table);
    }

    private function addUpperUniqueIndex(string $table): void
    {
        $collisions = DB::table($table)
            ->selectRaw('upper(name) as upper_name')
            ->groupByRaw('upper(name)')
            ->havingRaw('count(*) > 1')
            ->pluck('upper_name');

        if ($collisions->isNotEmpty()) {
            $this->warn("{$table}: unique index on upper(name) NOT added — names still collide: ".$collisions->implode(', ').". After resolving them run: CREATE UNIQUE INDEX {$table}_name_upper_unique ON {$table} (upper(name));");

            return;
        }

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$table}_name_upper_unique ON {$table} (upper(name))");
    }

    private function normaliseCustomers(): void
    {
        DB::table('customers')->select(['id', 'name', 'location'])->chunkById(self::CUSTOMER_CHUNK, function (Collection $customers): void {
            $changes = [];

            foreach ($customers as $customer) {
                $name = $this->fitting(UpperCaseLabel::normalise($customer->name), 50, $customer->name, 'name', $customer->id) ?? $customer->name;
                $location = $customer->location === null
                    ? null
                    : $this->fitting(UpperCaseLabel::normalise($customer->location), 30, $customer->location, 'location', $customer->id);

                if ($name !== $customer->name || $location !== $customer->location) {
                    $changes[] = [$customer->id, $name, $location];
                }
            }

            if ($changes === []) {
                return;
            }

            DB::update(
                'UPDATE customers AS c SET name = v.name, location = v.location, updated_at = now() FROM (VALUES '
                .implode(', ', array_fill(0, count($changes), '(?::bigint, ?::varchar, ?::varchar)'))
                .') AS v(id, name, location) WHERE c.id = v.id',
                array_merge(...$changes),
            );
        });
    }

    /**
     * The normalised value, or the original when the normalised one would
     * overflow the column (warned). Null/blank normalises to null.
     */
    private function fitting(?string $normalised, int $maxLength, ?string $original, string $column, int $id): ?string
    {
        if ($normalised !== null && mb_strlen($normalised) > $maxLength) {
            $this->warn("customers.{$column}: left unchanged for id {$id}, \"{$normalised}\" would be longer than {$maxLength} characters.");

            return $original;
        }

        return $normalised;
    }

    /**
     * @param  Collection<int, object{id: int, name: string}>  $rows
     */
    private function describe(Collection $rows): string
    {
        return $rows->map(fn (object $row): string => "\"{$row->name}\" (id {$row->id})")->implode(', ');
    }

    private function warn(string $message): void
    {
        Log::warning("[normalise_labels_to_upper_case] {$message}");

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln("<comment>  WARN {$message}</comment>");
        }
    }
};
