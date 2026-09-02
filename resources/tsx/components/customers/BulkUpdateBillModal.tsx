import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { formatCurrency } from '@/lib/formatCurrency';
import { xsrfTokenHeader } from '@/lib/csrfToken';
import type { CustomerLevel, CustomerStatus } from '@/types';

export type BulkBillAdjustMode = 'set' | 'increase_fixed' | 'increase_percent';

/**
 * The two ways Customers/Index.tsx can hand off a batch to this modal — see
 * App\Services\CustomerService::resolveCustomersForBulkBillUpdate()'s doc
 * comment for the server-side mirror of this same priority rule (explicit
 * uuids win when present; otherwise the filter descriptor is used).
 * `targetCount` is display-only (the checked-row count, or the current
 * filtered total across ALL pages from `customers.meta.total`) — the actual
 * number that gets updated always comes back from the preview/commit
 * response itself, never trusted from this count.
 */
export type BulkBillTarget =
    | { mode: 'uuids'; uuids: string[]; targetCount: number }
    | {
          mode: 'filter';
          filters: { zone_uuid: string | null; level: CustomerLevel | null; status: CustomerStatus | null; search: string | null };
          targetCount: number;
      };

interface PreviewRow {
    customer_uuid: string;
    name: string;
    current_bill: string;
    new_bill: string;
}

interface PreviewResponse {
    preview: PreviewRow[];
    skipped: Record<string, string>;
}

const modeValueLabels: Record<BulkBillAdjustMode, string> = {
    set: 'New bill (FCFA)',
    increase_fixed: 'Increase by (FCFA)',
    increase_percent: 'Increase by (%)',
};

/**
 * Every value here is a string, string[], or null — a structural subset of
 * Inertia's FormDataConvertible, so this same object literal shape can be
 * handed both to a plain fetch() (JSON.stringify) and to router.post()
 * (which requires a RequestPayload) without needing to import Inertia's
 * FormDataConvertible type just to satisfy the cast.
 */
interface BulkBillRequestBody {
    [key: string]: string | string[] | null | undefined;
    mode: BulkBillAdjustMode;
    value: string;
    customer_uuids?: string[];
    zone_uuid?: string | null;
    level?: CustomerLevel | null;
    status?: CustomerStatus | null;
    search?: string | null;
}

function requestBody(target: BulkBillTarget, mode: BulkBillAdjustMode, value: string): BulkBillRequestBody {
    return target.mode === 'uuids' ? { customer_uuids: target.uuids, mode, value } : { ...target.filters, mode, value };
}

/**
 * The annual bill-rate adjustment tool — "increase every customer in Zone
 * THR01 by 500 FCFA" / "set every VIP customer to 5,000 FCFA" — driven from
 * Customers/Index.tsx's bulk-select checkbox column and filter bar. Unlike
 * BulkStatusModal (its closest sibling on the Disconnections page), this
 * modal has a mandatory PREVIEW step before the "Apply" button is even
 * enabled: it changes real customers' recurring pricing, so office staff
 * must see the actual current->new numbers (fetched from
 * POST /customers/bulk-update-bill/preview, which computes but never
 * writes anything) before committing via POST /customers/bulk-update-bill.
 * Both endpoints run through the exact same App\Services\CustomerService::
 * planBulkBillUpdate() computation server-side, so what's previewed here is
 * guaranteed to match what gets saved.
 *
 * The preview fetch is a plain fetch() (not router.visit()/Inertia) since
 * it must return raw JSON without navigating — same reasoning as
 * Payments/Create.tsx's last-payment lookup, plus a manually-attached CSRF
 * header since that precedent's request was a GET and this one is a POST
 * (see lib/csrfToken.ts).
 */
export function BulkUpdateBillModal({
    target,
    onClose,
    onSuccess,
}: {
    target: BulkBillTarget | null;
    onClose: () => void;
    onSuccess: () => void;
}) {
    const [mode, setMode] = useState<BulkBillAdjustMode>('set');
    const [value, setValue] = useState('');
    const [previewStatus, setPreviewStatus] = useState<'idle' | 'loading' | 'loaded' | 'error'>('idle');
    const [preview, setPreview] = useState<PreviewResponse | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [applying, setApplying] = useState(false);
    const [applyErrors, setApplyErrors] = useState<Record<string, string>>({});

    // Fresh state every time a new target opens the modal — a stale
    // preview/error from a previous batch must never leak into this one.
    useEffect(() => {
        if (target) {
            setMode('set');
            setValue('');
            setPreviewStatus('idle');
            setPreview(null);
            setPreviewError(null);
            setApplyErrors({});
        }
    }, [target]);

    // Changing the adjustment after a preview was fetched invalidates it —
    // "Apply" stays disabled until the office worker re-previews, so the
    // numbers they confirmed on screen are always the numbers actually
    // computed at commit time.
    function updateMode(next: BulkBillAdjustMode) {
        setMode(next);
        setPreview(null);
        setPreviewStatus('idle');
    }

    function updateValue(next: string) {
        setValue(next);
        setPreview(null);
        setPreviewStatus('idle');
    }

    function runPreview() {
        if (!target || !value) {
            return;
        }

        setPreviewStatus('loading');
        setPreviewError(null);

        fetch('/customers/bulk-update-bill/preview', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...xsrfTokenHeader(),
            },
            body: JSON.stringify(requestBody(target, mode, value)),
        })
            .then(async (response) => {
                if (!response.ok) {
                    const body = await response.json().catch(() => null);
                    const message =
                        body?.errors && typeof body.errors === 'object'
                            ? Object.values(body.errors as Record<string, string[]>).flat().join(' ')
                            : (body?.message ?? `Preview failed (${response.status}).`);

                    throw new Error(message);
                }

                return response.json() as Promise<PreviewResponse>;
            })
            .then((body) => {
                setPreview(body);
                setPreviewStatus('loaded');
            })
            .catch((error: Error) => {
                setPreviewError(error.message);
                setPreviewStatus('error');
            });
    }

    function close() {
        onClose();
    }

    function apply() {
        if (!target || !preview || preview.preview.length === 0) {
            return;
        }

        setApplyErrors({});

        router.post('/customers/bulk-update-bill', requestBody(target, mode, value), {
            preserveScroll: true,
            onStart: () => setApplying(true),
            onFinish: () => setApplying(false),
            onSuccess: () => {
                onSuccess();
                close();
            },
            onError: (errors) => setApplyErrors(errors),
        });
    }

    const skippedEntries = preview ? Object.entries(preview.skipped) : [];
    const applyCount = preview ? preview.preview.length : (target?.targetCount ?? 0);

    return (
        <Modal open={target !== null} onClose={close} title="Update Bills">
            {target && (
                <div className="flex flex-col gap-4">
                    <p className="text-sm text-slate-600">
                        {target.mode === 'uuids'
                            ? `Applies to the ${target.targetCount} customer${target.targetCount === 1 ? '' : 's'} currently selected.`
                            : `Applies to all ${target.targetCount} customer${target.targetCount === 1 ? '' : 's'} matching the current filters — across every page, not just this one.`}
                    </p>

                    <SelectInput id="bulk-bill-mode" label="Adjustment" value={mode} onChange={(e) => updateMode(e.target.value as BulkBillAdjustMode)}>
                        <option value="set">Set to</option>
                        <option value="increase_fixed">Increase by amount</option>
                        <option value="increase_percent">Increase by percentage</option>
                    </SelectInput>

                    <TextInput
                        id="bulk-bill-value"
                        label={modeValueLabels[mode]}
                        type="number"
                        step="0.01"
                        value={value}
                        onChange={(e) => updateValue(e.target.value)}
                        placeholder={mode === 'increase_percent' ? 'e.g. 10 for +10%, -5 for -5%' : 'e.g. 500'}
                    />
                    {applyErrors.value && <p className="text-xs text-red-600">{applyErrors.value}</p>}
                    {applyErrors.mode && <p className="text-xs text-red-600">{applyErrors.mode}</p>}
                    {applyErrors.customer_uuids && <p className="text-xs text-red-600">{applyErrors.customer_uuids}</p>}

                    <div className="flex justify-end">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={runPreview}
                            disabled={!value || previewStatus === 'loading'}
                            className="rounded-lg px-4 py-2 text-sm font-semibold"
                        >
                            {previewStatus === 'loading' && <LoadingSpinner className="h-4 w-4" />}
                            Preview
                        </Button>
                    </div>

                    {previewError && <p className="text-sm text-red-600">{previewError}</p>}

                    {preview && (
                        <div className="flex flex-col gap-3">
                            {preview.preview.length > 0 ? (
                                <div className="max-h-64 overflow-y-auto rounded-lg border border-slate-200">
                                    <Table>
                                        <TableHead>
                                            <Th>Name</Th>
                                            <Th>Current</Th>
                                            <Th>New</Th>
                                        </TableHead>
                                        <TableBody>
                                            {preview.preview.map((row) => (
                                                <tr key={row.customer_uuid}>
                                                    <Td>{row.name}</Td>
                                                    <Td>{formatCurrency(row.current_bill)}</Td>
                                                    <Td className="font-medium text-slate-900">{formatCurrency(row.new_bill)}</Td>
                                                </tr>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            ) : (
                                <p className="text-sm text-slate-500">No customers would be updated by this adjustment.</p>
                            )}

                            {skippedEntries.length > 0 && (
                                <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                                    <p className="font-medium">
                                        {skippedEntries.length} would be skipped:
                                    </p>
                                    <ul className="mt-1 list-disc pl-4">
                                        {skippedEntries.map(([uuid, reason]) => (
                                            <li key={uuid}>{reason}</li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    )}

                    <div className="flex flex-col-reverse gap-2 border-t border-slate-200 pt-4 sm:flex-row sm:justify-end">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={close}
                            disabled={applying}
                            className="w-full rounded-lg px-4 py-2.5 text-sm font-semibold sm:w-auto"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            onClick={apply}
                            disabled={applying || !preview || preview.preview.length === 0}
                            className="w-full rounded-lg px-4 py-2.5 text-sm font-semibold sm:w-auto"
                        >
                            {applying && <LoadingSpinner className="h-4 w-4" />}
                            Apply to {applyCount} Customer{applyCount === 1 ? '' : 's'}
                        </Button>
                    </div>
                </div>
            )}
        </Modal>
    );
}
