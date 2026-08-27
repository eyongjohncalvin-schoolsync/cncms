import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import type { ImportReport } from '@/types';

/**
 * Renders the row-level partial-success report flashed by
 * ZoneController::import()/CustomerController::import() after a bulk
 * .xlsx import (App\Services\ZoneImportService / CustomerImportService).
 * `expectedType` guards against showing a stale customers-import report
 * on the Zones page (or vice versa) if the flash session key happens to
 * still be set from a different page's import.
 */
export function ImportReportCard({ report, expectedType }: { report: ImportReport | null | undefined; expectedType: ImportReport['type'] }) {
    if (!report || report.type !== expectedType) {
        return null;
    }

    return (
        <Card className="animate-fade-up mb-4">
            <CardHeader className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-semibold text-slate-900">Import report</h3>
                <div className="flex gap-2">
                    <Badge tone="green">{report.succeeded_count} imported</Badge>
                    {report.failed_count > 0 && <Badge tone="red">{report.failed_count} failed</Badge>}
                </div>
            </CardHeader>
            {report.failed_count > 0 && (
                <CardBody className="p-0">
                    <Table>
                        <TableHead>
                            <Th>Row</Th>
                            <Th>Reason</Th>
                        </TableHead>
                        <TableBody>
                            {report.failed.map((failure) => (
                                <tr key={failure.row}>
                                    <Td className="font-medium text-slate-900">{failure.row}</Td>
                                    <Td className="text-red-700">{failure.reason}</Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                </CardBody>
            )}
        </Card>
    );
}
