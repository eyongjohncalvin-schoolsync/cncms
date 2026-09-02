import { FormEvent, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { IconDownload, IconUpload } from '@tabler/icons-react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';

interface ImportModalProps {
    open: boolean;
    onClose: () => void;
    /** Route to POST the .xlsx file to, e.g. "/zones/import". */
    action: string;
    /** e.g. "Zones" / "Customers" — used in the modal title. */
    entityLabel: string;
    /** Plain-English description of the expected columns, shown as help text. */
    columnsHelp: string;
    /** Route that downloads a blank, correctly-formatted .xlsx template, e.g. "/zones/import/template". */
    templateUrl: string;
}

/**
 * File-picker modal for the bulk zone/customer .xlsx import — shared by
 * Zones/Index.tsx and Customers/Index.tsx rather than duplicated, since
 * both are the same "pick one .xlsx file, POST it, show what happened"
 * flow (App\Services\ZoneImportService / CustomerImportService on the
 * backend). Mirrors the file-upload pattern already used for payment
 * receipt photos (Payments/Show.tsx: useForm + forceFormData).
 */
export function ImportModal({ open, onClose, action, entityLabel, columnsHelp, templateUrl }: ImportModalProps) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [fileName, setFileName] = useState<string | null>(null);

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm<{ file: File | null }>({
        file: null,
    });

    function resetForm() {
        reset();
        clearErrors();
        setFileName(null);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    }

    function submit(e: FormEvent) {
        e.preventDefault();

        post(action, {
            forceFormData: true,
            onSuccess: () => {
                resetForm();
                onClose();
            },
        });
    }

    function handleClose() {
        resetForm();
        onClose();
    }

    return (
        <Modal open={open} onClose={handleClose} title={`Import ${entityLabel}`}>
            <form onSubmit={submit} className="flex flex-col gap-3">
                <p className="text-sm text-slate-500">
                    Upload an .xlsx spreadsheet with columns: <span className="font-medium text-slate-700">{columnsHelp}</span>. Each row is
                    validated independently — a bad row is reported, not treated as a reason to reject the whole file.
                </p>
                <a
                    href={templateUrl}
                    download
                    className="inline-flex w-fit items-center gap-1.5 rounded-md text-sm font-medium text-blue-700 transition-colors hover:text-blue-800 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                >
                    <IconDownload size={16} stroke={2} />
                    Download blank template
                </a>
                <input
                    ref={fileInputRef}
                    type="file"
                    accept=".xlsx"
                    onChange={(e) => {
                        const file = e.target.files?.[0] ?? null;
                        setData('file', file);
                        setFileName(file?.name ?? null);
                    }}
                    className="rounded-lg text-sm text-slate-600 ring-1 ring-inset ring-slate-300 transition-shadow duration-150 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 hover:ring-slate-400 hover:file:bg-slate-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                />
                {errors.file && <p className="text-xs text-red-600">{errors.file}</p>}
                <div className="mt-2 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button type="button" variant="secondary" onClick={handleClose} disabled={processing} className="w-full sm:w-auto">
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || !fileName} className="w-full sm:w-auto">
                        {processing ? <LoadingSpinner className="h-4 w-4" /> : <IconUpload size={16} stroke={2} />}
                        {processing ? 'Importing…' : 'Import'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
