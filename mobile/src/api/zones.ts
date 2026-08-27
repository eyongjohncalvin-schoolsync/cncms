import { apiClient } from './client';

/**
 * GET /api/v1/zones — App\Http\Controllers\Api\ZoneController::index(),
 * backing App\Http\Resources\ZoneResource. Open to every role per
 * App\Policies\ZonePolicy::viewAny() (unlike create/update/delete, which
 * stay super/admin/manager-only — this app has no zone-management UI, only
 * the read-only lookup screen at app/zones.tsx; that stays office/web-only,
 * matching the policy).
 *
 * `ZoneApi`/`ZoneListResponse` are declared locally here rather than added
 * to src/types/api.ts — several other agents are editing screens in this
 * same parallel build and that shared file is a likely merge-conflict spot,
 * so this narrow, self-contained module avoids touching it.
 *
 * The real response also carries `customer_count`/`agent` per zone (the
 * index query eagerly loads both — see App\Repositories\Eloquent\
 * ZoneRepository::paginate()), but the zones screen deliberately doesn't
 * surface either (mobile-app-react-native.md's zones-screen brief: "no
 * per-zone customer counts or detail drill-in ... keep this simple, it's a
 * reference lookup, not a management tool") — so `ZoneApi` only declares
 * the fields actually consumed. Extra response fields are simply ignored.
 *
 * No local cache/table for this — zones barely ever change
 * (App\Services\ZoneService::all()'s own comment: "29 of them"), so a plain
 * live call each time the screen opens is the right amount of complexity,
 * same reasoning as fetchCustomerDetail() in src/api/customers.ts. This
 * call requires connectivity; there is no offline fallback.
 *
 * `per_page: 100` is comfortably above the current zone count so the whole
 * list comes back in one page rather than being paginated for a screen
 * this small (the endpoint defaults to per_page=25, which would otherwise
 * truncate the ~29 zones that exist today).
 */
export interface ZoneApi {
    uuid: string;
    name: string;
    town: string;
}

export interface ZoneListResponse {
    data: ZoneApi[];
}

export async function fetchZones(): Promise<ZoneListResponse> {
    const { data } = await apiClient.get<ZoneListResponse>('/zones', {
        params: { per_page: 100 },
    });

    return data;
}
