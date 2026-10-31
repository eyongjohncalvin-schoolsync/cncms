/**
 * "List us on cncms.tech" at registration — the company appears in the
 * public home page's "Cable companies running on CNCMS" strip once the
 * landlord approves it (App\Services\HomepageCompaniesService).
 */
export function ShowOnHomepageCheckbox({ checked, onChange }: { checked: boolean; onChange: (value: boolean) => void }) {
    return (
        <label className="flex items-start gap-2.5 text-sm text-slate-700">
            <input
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                className="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
            />
            <span>
                Show our company name and logo on cncms.tech
                <span className="block text-xs text-slate-400">Among the cable companies using CNCMS. You can ask us to remove it at any time.</span>
            </span>
        </label>
    );
}
