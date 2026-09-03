import { apiClient } from './client';
import type { ServiceCatalogueResponse } from '../types/api';

/**
 * GET /api/v1/services — App\Http\Controllers\Api\ServiceController::
 * index(), backing App\Http\Resources\ServiceCatalogueResource. The
 * customer add/edit form's tick-list (services.md sections 6-8). Every
 * ACTIVE service + its active options only; open to any authenticated
 * tenant member (same reasoning as fetchZones()).
 *
 * When editing an EXISTING customer, the caller must separately merge in
 * whatever that customer already holds from CustomerDetailApi.services —
 * a service/option they hold could have since gone inactive and dropped
 * out of this list, but services.md section 8 says it must still show
 * ticked (with an "(inactive)" tag) rather than silently disappear. There
 * is no customer-scoped variant of this endpoint server-side; the web
 * admin does this same merge in CustomerController::serviceCatalogue(),
 * this is the mobile equivalent done client-side instead.
 *
 * No local cache — same reasoning as fetchZones(): the catalogue barely
 * ever changes and this is only called when the create/edit form opens.
 * Requires connectivity; there is no offline fallback (matches every other
 * live-only call in src/api/).
 */
export async function fetchServiceCatalogue(): Promise<ServiceCatalogueResponse> {
    const { data } = await apiClient.get<ServiceCatalogueResponse>('/services');

    return data;
}
