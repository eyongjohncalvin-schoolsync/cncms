/**
 * A legal document's text, already turned into safe HTML on the server
 * (App\Services\LegalDocumentService::toHtml — raw HTML in the source is
 * escaped there, so it is safe to inject here).
 */
export function LegalBody({ html, className = '' }: { html: string; className?: string }) {
    return (
        <div
            className={`text-sm leading-relaxed text-slate-700 [&_a]:text-blue-600 [&_a]:underline [&_h2]:mt-5 [&_h2]:text-base [&_h2]:font-semibold [&_h2]:text-slate-900 [&_h2:first-child]:mt-0 [&_li]:mt-1 [&_p]:mt-2 [&_strong]:text-slate-900 [&_ul]:mt-2 [&_ul]:list-disc [&_ul]:pl-5 ${className}`}
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
}
