import { useEffect, useState } from "react";
import { router } from "@inertiajs/react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { LoadingSpinner } from "@/components/ui/LoadingSpinner";
import { xsrfTokenHeader } from "@/lib/csrfToken";

/**
 * What Zones/Index.tsx hands this modal: the checked rows, or every zone
 * matching the current search across all pages ("select all N matching").
 * Either way the preview response is the source of truth — the commit
 * sends exactly the uuids the preview returned.
 */
export type BulkDeleteZonesTarget =
    | { mode: "uuids"; uuids: string[] }
    | { mode: "all_matching"; search: string | null };

interface PreviewZone {
    uuid: string;
    name: string;
    town: string;
    customer_count: number;
    agent_count: number;
    kept_reason: string | null;
}

interface PreviewResponse {
    zones: PreviewZone[];
    truncated: boolean;
}

function previewBody(target: BulkDeleteZonesTarget): Record<string, unknown> {
    return target.mode === "uuids"
        ? { zone_uuids: target.uuids }
        : { all_matching: true, search: target.search };
}

/**
 * Bulk zone delete confirmation. Opening it fetches POST
 * /zones/bulk-delete/preview (no writes) so the office worker sees every
 * zone that will go and every zone that will be kept (customers or agents
 * still assigned — App\Services\ZoneService::delete()'s rule) before
 * anything happens. Only the "Delete N zones" button deletes, via POST
 * /zones/bulk-delete, which re-checks every rule per zone server-side.
 */
export function BulkDeleteZonesModal({
    target,
    onClose,
    onSuccess,
}: {
    target: BulkDeleteZonesTarget | null;
    onClose: () => void;
    onSuccess: () => void;
}) {
    const [preview, setPreview] = useState<PreviewResponse | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    useEffect(() => {
        setPreview(null);
        setPreviewError(null);
        setDeleteError(null);

        if (!target) {
            return;
        }

        let cancelled = false;

        fetch("/zones/bulk-delete/preview", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                ...xsrfTokenHeader(),
            },
            body: JSON.stringify(previewBody(target)),
        })
            .then(async (response) => {
                if (!response.ok) {
                    const body = await response.json().catch(() => null);
                    const message =
                        body?.errors && typeof body.errors === "object"
                            ? Object.values(
                                  body.errors as Record<string, string[]>,
                              )
                                  .flat()
                                  .join(" ")
                            : (body?.message ??
                              `Could not load the selected zones (${response.status}).`);

                    throw new Error(message);
                }

                return response.json() as Promise<PreviewResponse>;
            })
            .then((body) => {
                if (!cancelled) {
                    setPreview(body);
                }
            })
            .catch((error: Error) => {
                if (!cancelled) {
                    setPreviewError(error.message);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [target]);

    const deletable = preview
        ? preview.zones.filter((zone) => zone.kept_reason === null)
        : [];
    const kept = preview
        ? preview.zones.filter((zone) => zone.kept_reason !== null)
        : [];

    function confirmDelete() {
        if (!preview || deletable.length === 0) {
            return;
        }

        setDeleteError(null);

        router.post(
            "/zones/bulk-delete",
            { zone_uuids: preview.zones.map((zone) => zone.uuid) },
            {
                preserveScroll: true,
                onStart: () => setDeleting(true),
                onFinish: () => setDeleting(false),
                onSuccess: () => {
                    onSuccess();
                    onClose();
                },
                onError: (errors) =>
                    setDeleteError(Object.values(errors).join(" ")),
            },
        );
    }

    return (
        <Modal
            open={target !== null}
            onClose={() => !deleting && onClose()}
            title="Delete Zones"
        >
            {target && (
                <div className="flex flex-col gap-4">
                    {!preview && !previewError && (
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <LoadingSpinner
                                className="h-4 w-4"
                                label="Loading selected zones"
                            />
                            Checking the selected zones…
                        </div>
                    )}

                    {previewError && (
                        <p className="text-sm text-red-600">{previewError}</p>
                    )}

                    {preview && (
                        <>
                            {deletable.length > 0 ? (
                                <div>
                                    <p className="text-sm text-slate-700">
                                        These {deletable.length} zone
                                        {deletable.length === 1 ? "" : "s"} will
                                        be permanently deleted:
                                    </p>
                                    <ul className="mt-2 max-h-56 overflow-y-auto rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900">
                                        {deletable.map((zone) => (
                                            <li
                                                key={zone.uuid}
                                                className="py-0.5"
                                            >
                                                {zone.name}{" "}
                                                <span className="text-slate-500">
                                                    · {zone.town}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ) : (
                                <p className="text-sm text-slate-600">
                                    None of the selected zones can be deleted.
                                </p>
                            )}

                            {kept.length > 0 && (
                                <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                                    <p className="font-medium">
                                        {kept.length} zone
                                        {kept.length === 1 ? "" : "s"} will be
                                        kept:
                                    </p>
                                    <ul className="mt-1 max-h-40 list-disc overflow-y-auto pl-4">
                                        {kept.map((zone) => (
                                            <li key={zone.uuid}>
                                                {zone.name} — kept,{" "}
                                                {zone.kept_reason}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            {preview.truncated && (
                                <p className="text-xs text-slate-500">
                                    Only the first {preview.zones.length}{" "}
                                    matching zones are included. Run this again
                                    afterwards for the rest.
                                </p>
                            )}
                        </>
                    )}

                    {deleteError && (
                        <p className="text-sm text-red-600">{deleteError}</p>
                    )}

                    <div className="flex flex-col-reverse gap-2 border-t border-slate-200 pt-4 sm:flex-row sm:justify-end">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={onClose}
                            disabled={deleting}
                            className="w-full rounded-lg px-4 py-2.5 text-sm font-semibold sm:w-auto"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="danger"
                            onClick={confirmDelete}
                            disabled={deleting || deletable.length === 0}
                            className="w-full rounded-lg px-4 py-2.5 text-sm font-semibold sm:w-auto"
                        >
                            {deleting && <LoadingSpinner className="h-4 w-4" />}
                            Delete {deletable.length} zone
                            {deletable.length === 1 ? "" : "s"}
                        </Button>
                    </div>
                </div>
            )}
        </Modal>
    );
}
