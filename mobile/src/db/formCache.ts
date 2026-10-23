import { getMeta, setMeta } from './syncMeta';
import { fetchZones, type ZoneApi } from '../api/zones';
import { fetchServiceCatalogue } from '../api/services';
import { fetchInstallationOptions } from '../api/customers';
import type { InstallationOptionsResponse, ServiceCatalogueApi } from '../types/api';
import type { SyncMetaKey } from '../types/db';

/**
 * What the add-customer form needs to work offline (installation phase 3):
 * zones, the service catalogue with its prices, and the installation
 * options (offers, company max free months, what this user may do) —
 * cached on the phone as JSON in sync_meta. Refreshed after a sync and
 * whenever the form opens online.
 */

export interface AddCustomerFormData {
    zones: ZoneApi[];
    catalogue: ServiceCatalogueApi[];
    /** Null on a server older than installation phase 2 (no installation section). */
    installationOptions: InstallationOptionsResponse['data'] | null;
}

async function readJson<T>(key: SyncMetaKey): Promise<T | null> {
    const raw = await getMeta(key);

    if (!raw) {
        return null;
    }

    try {
        return JSON.parse(raw) as T;
    } catch {
        return null;
    }
}

export async function getCachedFormData(): Promise<AddCustomerFormData | null> {
    const [zones, catalogue, installationOptions] = await Promise.all([
        readJson<ZoneApi[]>('cache_zones'),
        readJson<ServiceCatalogueApi[]>('cache_service_catalogue'),
        readJson<InstallationOptionsResponse['data']>('cache_installation_options'),
    ]);

    if (!zones || !catalogue) {
        return null;
    }

    return { zones, catalogue, installationOptions };
}

export async function cacheFormData(data: AddCustomerFormData): Promise<void> {
    await setMeta('cache_zones', JSON.stringify(data.zones));
    await setMeta('cache_service_catalogue', JSON.stringify(data.catalogue));

    if (data.installationOptions) {
        await setMeta('cache_installation_options', JSON.stringify(data.installationOptions));
    }
}

/**
 * Fetches all three live and caches them. A 403 on the options (a user who
 * may not add customers) or an older server leaves the options null.
 * Network errors propagate.
 */
export async function refreshFormData(): Promise<AddCustomerFormData> {
    const [zones, catalogue, options] = await Promise.all([
        fetchZones(),
        fetchServiceCatalogue(),
        fetchInstallationOptions().catch((error: unknown) => {
            const status = (error as { response?: { status?: number } })?.response?.status;

            if (status !== undefined) {
                return null;
            }

            throw error;
        }),
    ]);

    const data = { zones: zones.data, catalogue: catalogue.data, installationOptions: options?.data ?? null };
    await cacheFormData(data);

    return data;
}
