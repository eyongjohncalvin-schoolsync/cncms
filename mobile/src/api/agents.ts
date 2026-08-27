import { apiClient } from './client';

/**
 * GET /api/v1/agents/me — App\Http\Controllers\Api\AgentController::me(),
 * App\Http\Resources\AgentMeResource. Resolves the Agent row linked to the
 * CURRENTLY AUTHENTICATED user (`Agent::where('user_id', auth()->id())`) —
 * there is no uuid parameter, by design: this endpoint cannot be pointed at
 * any other agent's record.
 *
 * Response types are defined locally here rather than added to
 * ../types/api.ts: that file is being edited by other screens' builders in
 * this same build wave, and this shape is only consumed by
 * app/agent-profile.tsx, so keeping it local avoids an avoidable merge
 * conflict on a shared file for a single-consumer type.
 *
 * A 404 means the authenticated account has no linked Agent row (e.g. an
 * office-role login with no field-agent record) — left to the caller to
 * render as an explanatory empty state, not treated as an error.
 */
export interface AgentMeApi {
    uuid: string;
    name: string;
    location: string | null;
    phone: string | null;
    zone_uuid: string | null;
    zone_name: string | null;
    /** Numeric string (Postgres DECIMAL cast to string by Eloquent), like `customers.bill` elsewhere in this app. */
    salary: string | null;
    email: string | null;
    dob: string | null;
    marital_status: string | null;
    children: number | null;
    status: string;
    /** Full public URL (Storage::disk('public')->url(...)), or null if no photo is on file. */
    picture_url: string | null;
    last_sync_at: string | null;
    created_at: string;
}

export interface AgentMeResponse {
    data: AgentMeApi;
}

export async function fetchMyAgentProfile(): Promise<AgentMeResponse> {
    const { data } = await apiClient.get<AgentMeResponse>('/agents/me');

    return data;
}
