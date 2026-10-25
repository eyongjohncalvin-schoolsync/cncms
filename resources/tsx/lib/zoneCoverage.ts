/**
 * Zone coverage maths for the Zones/Coverage page: the same rules as
 * App\Services\ZoneCoverageService (workloads() and apply()), run in the
 * browser so a preview of any change is instant.
 */

export interface CoverageZone {
    uuid: string;
    name: string;
    customers: number;
    responsible: string | null;
    helpers: string[];
}

export interface CoverageAgent {
    uuid: string;
    name: string;
    active: boolean;
}

export type CoverageAction = 'responsible' | 'helper' | 'remove' | 'clear';

export interface CoverageChange {
    action: CoverageAction;
    agentUuid: string | null;
    zoneUuids: string[];
    keepPreviousAsHelper: boolean;
}

export type WorkloadStatus = 'none' | 'normal' | 'busy' | 'overloaded';

/** Customers per agent: each zone's active customers shared equally among the agents covering it. */
export function workloads(zones: CoverageZone[]): Map<string, number> {
    const load = new Map<string, number>();

    for (const zone of zones) {
        const covering = [zone.responsible, ...zone.helpers].filter((uuid): uuid is string => uuid !== null);

        for (const agentUuid of covering) {
            load.set(agentUuid, (load.get(agentUuid) ?? 0) + zone.customers / covering.length);
        }
    }

    return load;
}

/** Zones each agent covers. */
export function zoneCounts(zones: CoverageZone[]): Map<string, number> {
    const counts = new Map<string, number>();

    for (const zone of zones) {
        for (const agentUuid of [zone.responsible, ...zone.helpers]) {
            if (agentUuid !== null) {
                counts.set(agentUuid, (counts.get(agentUuid) ?? 0) + 1);
            }
        }
    }

    return counts;
}

/** Busy from 80% of the limit, overloaded above it; no limit set means no judgement. */
export function workloadStatus(customers: number, limit: number | null): WorkloadStatus {
    if (limit === null || limit <= 0) {
        return 'none';
    }

    if (customers > limit) {
        return 'overloaded';
    }

    return customers >= limit * 0.8 ? 'busy' : 'normal';
}

/** The zones as they would be after the change — mirrors ZoneCoverageService::apply(). */
export function applyChange(zones: CoverageZone[], change: CoverageChange): CoverageZone[] {
    const targets = new Set(change.zoneUuids);
    const agent = change.agentUuid;

    return zones.map((zone) => {
        if (!targets.has(zone.uuid)) {
            return zone;
        }

        const helpers = zone.helpers.filter((uuid) => uuid !== agent);

        switch (change.action) {
            case 'responsible': {
                if (agent === null || zone.responsible === agent) {
                    return zone;
                }

                const previous = zone.responsible;

                return {
                    ...zone,
                    responsible: agent,
                    helpers: previous !== null && change.keepPreviousAsHelper ? [...helpers, previous] : helpers,
                };
            }
            case 'helper':
                if (agent === null || zone.responsible === agent || zone.helpers.includes(agent)) {
                    return zone;
                }

                return { ...zone, helpers: [...zone.helpers, agent] };
            case 'remove':
                if (agent === null || !covers(zone, agent)) {
                    return zone;
                }

                return { ...zone, responsible: zone.responsible === agent ? null : zone.responsible, helpers };
            case 'clear':
                return zone.responsible === null ? zone : { ...zone, responsible: null };
        }
    });
}

/** Does the agent cover this zone at all (responsible or helper)? */
export function covers(zone: CoverageZone, agentUuid: string): boolean {
    return zone.responsible === agentUuid || zone.helpers.includes(agentUuid);
}
