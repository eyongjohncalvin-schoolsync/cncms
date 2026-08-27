<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface AuditLogRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'table_name', 'action',
     *                                         'user_uuid', 'search', 'record_uuid', 'from', 'to'.
     *                                         'search' is the primary, name-based way to find a
     *                                         record's audit trail; 'record_uuid' remains an exact-match
     *                                         escape hatch for callers that already know the UUID.
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
}
