import { FormEvent, useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { IconAlertTriangle } from '@tabler/icons-react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { SelectInput } from '@/components/ui/SelectInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { Agent, Zone } from '@/types';

/**
 * The Agents/Index "Change Zone" quick action — a fast path to reassign a
 * field agent to a different zone without a full Edit-form detour. Submits
 * to the existing `PATCH /agents/{agent}` route (App\Http\Controllers\
 * AgentController::update), sending only `zone_uuid`; the FormRequest/DTO/
 * Service chain already treats every Agent field as optional-on-update, so
 * a zone-only payload is a normal partial update — no backend change was
 * needed for that part.
 *
 * "Smart" here means surfacing, before the office confirms: the
 * destination zone's current workload (customer_count), whether the
 * destination already has another active agent (soft warning — dual
 * coverage can be legitimate, so this never hard-blocks), and whether the
 * agent's current zone would be left with zero active agents after the
 * move. All three are derived client-side from the `zones` prop's
 * customer_count/agent_count/agent_names (see AgentController::
 * zonesWithStats()) plus the agent's own current zone/status — no extra
 * request is needed just to preview the change.
 */
export function ChangeZoneModal({ agent, zones, onClose }: { agent: Agent | null; zones: Zone[]; onClose: () => void }) {
    const { data, setData, patch, processing, errors, reset } = useForm({
        zone_uuid: '',
    });

    // Re-seed to the agent's current zone whenever a fresh agent opens the
    // modal, so the select starts on their existing assignment rather than
    // a blank option.
    useEffect(() => {
        if (agent) {
            setData('zone_uuid', agent.zone_uuid ?? '');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [agent]);

    const currentZone = zones.find((zone) => zone.uuid === agent?.zone_uuid) ?? null;
    const selectedZone = zones.find((zone) => zone.uuid === data.zone_uuid) ?? null;
    const isChanging = agent !== null && data.zone_uuid !== '' && data.zone_uuid !== agent.zone_uuid;

    const destinationCustomerCount = selectedZone?.customer_count ?? 0;
    const destinationAgentCount = selectedZone?.agent_count ?? 0;
    const destinationHasOtherAgent = isChanging && destinationAgentCount > 0;
    const destinationAgentNames = selectedZone?.agent_names?.join(', ') ?? 'another agent';

    // The current zone's agent_count already includes this agent (if it's
    // active), so subtract it off to see who would remain after the move.
    const currentZoneRemainingAfterMove = currentZone ? (currentZone.agent_count ?? 0) - (agent?.status === 'active' ? 1 : 0) : 0;
    const currentZoneWillBeEmpty = isChanging && currentZone !== null && currentZoneRemainingAfterMove <= 0;

    function close() {
        reset();
        onClose();
    }

    function submit(e: FormEvent) {
        e.preventDefault();

        if (!agent || !isChanging) {
            return;
        }

        patch(`/agents/${agent.uuid}`, {
            preserveScroll: true,
            onSuccess: () => close(),
        });
    }

    return (
        <Modal open={agent !== null} onClose={close} title="Change Zone">
            {agent && (
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                        <p className="font-medium text-slate-900">{agent.name}</p>
                        <p className="mt-1 text-slate-600">Currently in {agent.zone_name ?? 'no zone'}</p>
                    </div>

                    <SelectInput
                        id="change-zone-select"
                        label="New zone"
                        value={data.zone_uuid}
                        onChange={(e) => setData('zone_uuid', e.target.value)}
                        error={errors.zone_uuid}
                    >
                        <option value="">Select a zone</option>
                        {zones.map((zone) => (
                            <option key={zone.uuid} value={zone.uuid}>
                                {zone.name} ({zone.town})
                            </option>
                        ))}
                    </SelectInput>

                    {isChanging && selectedZone && (
                        <div className="flex flex-col gap-3">
                            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                <p className="font-medium text-slate-900">
                                    {selectedZone.name} ({selectedZone.town})
                                </p>
                                <p className="mt-1 text-slate-600">
                                    {destinationCustomerCount} customer{destinationCustomerCount === 1 ? '' : 's'}
                                </p>
                            </div>

                            {destinationHasOtherAgent && (
                                <div className="flex gap-3 rounded-lg bg-amber-100 p-3 ring-1 ring-inset ring-amber-300">
                                    <IconAlertTriangle size={20} stroke={1.75} className="mt-0.5 shrink-0 text-amber-600" />
                                    <p className="text-sm text-amber-800">
                                        <strong>{selectedZone.name}</strong> is already covered by {destinationAgentNames} — assigning{' '}
                                        {agent.name} too means the zone will have {destinationAgentCount + 1} agents.
                                    </p>
                                </div>
                            )}

                            {currentZoneWillBeEmpty && currentZone && (
                                <div className="flex gap-3 rounded-lg bg-amber-100 p-3 ring-1 ring-inset ring-amber-300">
                                    <IconAlertTriangle size={20} stroke={1.75} className="mt-0.5 shrink-0 text-amber-600" />
                                    <p className="text-sm text-amber-800">
                                        <strong>{currentZone.name}</strong> will have no agent assigned after this change.
                                    </p>
                                </div>
                            )}
                        </div>
                    )}

                    <div className="flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={close}
                            disabled={processing}
                            className="rounded-lg px-4 py-2.5 text-sm font-semibold"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            disabled={processing || !isChanging}
                            className="rounded-lg px-4 py-2.5 text-sm font-semibold"
                        >
                            {processing && <LoadingSpinner className="h-4 w-4" />}
                            Confirm Zone Change
                        </Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
