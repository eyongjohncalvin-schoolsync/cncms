/**
 * The sign-up tick box for the Terms and Privacy policy — recorded as the
 * company's first acceptance (App\Services\LegalDocumentService). The
 * links open the public pages in a new tab so the form keeps its input.
 */
export function AcceptTermsCheckbox({ checked, onChange, error }: { checked: boolean; onChange: (value: boolean) => void; error?: string }) {
    return (
        <div className="flex flex-col gap-1">
            <label className="flex items-start gap-2.5 text-sm text-slate-700">
                <input
                    type="checkbox"
                    checked={checked}
                    onChange={(event) => onChange(event.target.checked)}
                    className="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                />
                <span>
                    I accept the{' '}
                    <a href="/terms" target="_blank" rel="noopener" className="font-medium text-blue-600 hover:underline">
                        Terms of service
                    </a>{' '}
                    and the{' '}
                    <a href="/privacy" target="_blank" rel="noopener" className="font-medium text-blue-600 hover:underline">
                        Privacy policy
                    </a>{' '}
                    for my company.
                </span>
            </label>
            {error && <p className="text-xs text-red-600">{error}</p>}
        </div>
    );
}
