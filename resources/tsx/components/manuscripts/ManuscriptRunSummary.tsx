import type { CommandRunComputedResultSummary } from '@/types';

/**
 * The computed-result summary grid — extracted from Settings/CommandRuns.tsx's
 * preview modal (task-scheduler.md's 2026-08-27 stage 3 addendum) so it
 * renders identically wherever a `CommandRun`'s `computed_result_summary` is
 * shown: that page's own Preview modal, and the new one-click
 * Manuscripts/RunReview.tsx screen reachable straight from the Calculate
 * button. Both surfaces must show the exact same numbers in the exact same
 * shape, since both are ultimately showing the same "what publishing will
 * commit" guarantee (see App\Services\ManuscriptGenerationBatchService::
 * publish()'s class doc).
 */
export function ManuscriptRunSummary({ summary }: { summary: CommandRunComputedResultSummary }) {
    return (
        <dl className="grid grid-cols-2 gap-3 text-sm">
            <div>
                <dt className="text-xs text-slate-500">Customers processed</dt>
                <dd className="font-medium text-slate-900">{summary.customers_processed}</dd>
            </div>
            <div>
                <dt className="text-xs text-slate-500">Frozen customers</dt>
                <dd className="font-medium text-slate-900">{summary.frozen_customers}</dd>
            </div>
            <div>
                <dt className="text-xs text-slate-500">Total arrears</dt>
                <dd className="font-medium text-slate-900">{summary.total_arrears_sum.toLocaleString()}</dd>
            </div>
            <div>
                <dt className="text-xs text-slate-500">Total credit</dt>
                <dd className="font-medium text-slate-900">{summary.total_credit_sum.toLocaleString()}</dd>
            </div>
            <div>
                <dt className="text-xs text-slate-500">Total bill</dt>
                <dd className="font-medium text-slate-900">{summary.total_bill_sum.toLocaleString()}</dd>
            </div>
            <div>
                <dt className="text-xs text-slate-500">Errors</dt>
                <dd className="font-medium text-slate-900">{summary.errors}</dd>
            </div>
        </dl>
    );
}
