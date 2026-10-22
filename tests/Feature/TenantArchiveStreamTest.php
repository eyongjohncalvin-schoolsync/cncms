<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\TenantArchive;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TenantArchive::stream() — the batched server-side cursor that keeps
 * tenant:export / tenant:import from loading a whole table into memory.
 * DatabaseTransactions supplies the transaction a cursor needs.
 */
class TenantArchiveStreamTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return list<int>
     */
    private function streamed(int $rows, int $batchSize): array
    {
        $sql = $rows > 0 ? "SELECT generate_series(1, {$rows}) AS n" : 'SELECT 1 AS n WHERE false';

        return array_map(fn (object $row) => (int) $row->n, iterator_to_array(TenantArchive::stream(DB::connection('pgsql'), $sql, $batchSize), false));
    }

    public function test_every_row_arrives_once_across_batch_boundaries(): void
    {
        $this->assertSame(range(1, 7), $this->streamed(7, 3));  // partial last batch
        $this->assertSame(range(1, 6), $this->streamed(6, 3));  // exact multiple
        $this->assertSame([1], $this->streamed(1, 1000));
        $this->assertSame([], $this->streamed(0, 3));
    }

    public function test_the_checksum_ignores_row_order(): void
    {
        $columns = ['id', 'name'];
        $rows = [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b'], ['id' => 3, 'name' => 'b']];

        $this->assertSame(
            TenantArchive::checksum($rows, $columns),
            TenantArchive::checksum(array_reverse($rows), $columns),
        );
        $this->assertNotSame(
            TenantArchive::checksum($rows, $columns),
            TenantArchive::checksum([...$rows, ['id' => 3, 'name' => 'b']], $columns),
        );
    }
}
