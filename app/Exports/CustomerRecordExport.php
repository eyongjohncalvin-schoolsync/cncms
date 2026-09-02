<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The multi-sheet .xlsx half of the customer record export — the structured
 * counterpart to resources/views/pdf/customer-record.blade.php, fed the
 * IDENTICAL App\Services\CustomerRecordExportService::gather() payload so
 * the two downloads can never disagree. One tab per section (Profile,
 * Payments, Manuscripts, Arrears Adjustments, Messages, Complaints, Audit
 * Trail), each a plain App\Exports\CustomerRecordSheet.
 *
 * Must implement `Export` explicitly (not just `WithMultipleSheets`) — same
 * maatwebsite/excel v4 strictness noted on CustomerImportTemplateExport.
 */
final class CustomerRecordExport implements Export, WithMultipleSheets
{
    /**
     * @param  array<string, mixed>  $data  the gather() result
     */
    public function __construct(private readonly array $data) {}

    /**
     * @return array<int, Export>
     */
    public function sheets(): array
    {
        return [
            $this->profileSheet(),
            $this->paymentsSheet(),
            $this->manuscriptsSheet(),
            $this->arrearsAdjustmentsSheet(),
            $this->messagesSheet(),
            $this->complaintsSheet(),
            $this->auditTrailSheet(),
        ];
    }

    private function profileSheet(): CustomerRecordSheet
    {
        $profile = $this->data['profile'] ?? [];

        $rows = [];
        foreach ($profile as $field => $value) {
            $rows[] = [$field, $this->scalar($value)];
        }

        return new CustomerRecordSheet('Profile', ['Field', 'Value'], $rows);
    }

    private function paymentsSheet(): CustomerRecordSheet
    {
        $rows = array_map(fn (array $p): array => [
            $p['created_at'] ?? null,
            (float) ($p['amount'] ?? 0),
            (float) ($p['credit'] ?? 0),
            $p['method'] ?? null,
            $p['months'] ?? null,
            $p['verification_status'] ?? null,
            $p['processed_at'] ?? null,
            $p['processed_period'] ?? null,
            $p['expiration_date'] ?? null,
            $p['verification']['momo_ref'] ?? null,
            $p['verification']['momo_status'] ?? null,
            $p['verification']['verified_by'] ?? null,
            $p['verification']['verified_at'] ?? null,
            $p['receipt']['number'] ?? null,
            $p['receipt']['issued_at'] ?? null,
            $p['receipt']['status'] ?? null,
        ], $this->data['payments'] ?? []);

        return new CustomerRecordSheet('Payments', [
            'Recorded At', 'Amount', 'Credit', 'Method', 'Months', 'Verification Status',
            'Processed At', 'Processed Period', 'Expiration Date',
            'MoMo Ref', 'MoMo Status', 'Verified By', 'Verified At',
            'Receipt No', 'Receipt Issued At', 'Receipt Status',
        ], $rows);
    }

    private function manuscriptsSheet(): CustomerRecordSheet
    {
        $rows = array_map(fn (array $m): array => [
            $m['period'] ?? null,
            (float) ($m['bill'] ?? 0),
            (float) ($m['total_arrears'] ?? 0),
            (float) ($m['credit'] ?? 0),
            (float) ($m['total_bill'] ?? 0),
            $m['payment_expiration'] ?? null,
            $m['prepaid_months_remaining'] ?? 0,
            $m['coverage_through'] ?? null,
            $m['command_run_id'] ?? null,
            $m['command_run'] ?? null,
            $m['created_at'] ?? null,
        ], $this->data['manuscripts'] ?? []);

        return new CustomerRecordSheet('Manuscripts', [
            'Period', 'Bill', 'Total Arrears', 'Credit', 'Total Bill',
            'Payment Expiration', 'Prepaid Months Remaining', 'Coverage Through',
            'Command Run ID', 'Command', 'Created At',
        ], $rows);
    }

    private function arrearsAdjustmentsSheet(): CustomerRecordSheet
    {
        $rows = array_map(fn (array $a): array => [
            $a['requested_at'] ?? null,
            $a['direction'] ?? null,
            $a['target'] ?? null,
            (float) ($a['amount'] ?? 0),
            $a['target_period'] ?? null,
            $a['reason_category'] ?? null,
            $a['reason_note'] ?? null,
            $a['status'] ?? null,
            $a['requested_by'] ?? null,
            $a['approved_by'] ?? null,
            $a['second_approved_by'] ?? null,
            $a['approved_at'] ?? null,
            $a['rejection_reason'] ?? null,
        ], $this->data['arrears_adjustments'] ?? []);

        return new CustomerRecordSheet('Arrears Adjustments', [
            'Requested At', 'Direction', 'Target', 'Amount', 'Target Period',
            'Reason', 'Reason Note', 'Status', 'Requested By', 'Approved By',
            'Second Approved By', 'Approved At', 'Rejection Reason',
        ], $rows);
    }

    private function messagesSheet(): CustomerRecordSheet
    {
        $rows = array_map(fn (array $m): array => [
            $m['created_at'] ?? null,
            $m['type'] ?? null,
            $m['channel'] ?? null,
            $m['status'] ?? null,
            $m['sid'] ?? null,
            $m['content'] ?? null,
        ], $this->data['messages'] ?? []);

        return new CustomerRecordSheet('Messages', [
            'Sent At', 'Type', 'Channel', 'Status', 'SID', 'Content',
        ], $rows);
    }

    private function complaintsSheet(): CustomerRecordSheet
    {
        $rows = array_map(fn (array $c): array => [
            $c['created_at'] ?? null,
            $c['category'] ?? null,
            $c['title'] ?? null,
            $c['description'] ?? null,
            $c['status'] ?? null,
            $c['urgent'] ? 'yes' : 'no',
            $c['escalation_level'] ?? 0,
            $c['escalated_at'] ?? null,
            $c['assigned_to'] ?? null,
            $c['resolved_by'] ?? null,
            $c['resolution_notes'] ?? null,
            $c['resolved_at'] ?? null,
        ], $this->data['complaints'] ?? []);

        return new CustomerRecordSheet('Complaints', [
            'Submitted At', 'Category', 'Title', 'Description', 'Status', 'Urgent',
            'Escalation Level', 'Escalated At', 'Assigned To', 'Resolved By',
            'Resolution Notes', 'Resolved At',
        ], $rows);
    }

    private function auditTrailSheet(): CustomerRecordSheet
    {
        $entries = $this->data['audit_trail']['entries'] ?? [];

        $rows = array_map(fn (array $e): array => [
            $e['created_at'] ?? null,
            $e['table'] ?? null,
            $e['record_uuid'] ?? null,
            $e['action'] ?? null,
            $e['user'] ?? null,
            $e['ip_address'] ?? null,
            $this->formatChanges($e['changes'] ?? []),
        ], $entries);

        return new CustomerRecordSheet('Audit Trail', [
            'When', 'Table', 'Record UUID', 'Action', 'User', 'IP', 'Changes (field: old -> new)',
        ], $rows);
    }

    /**
     * @param  list<array{field: string, old: mixed, new: mixed}>  $changes
     */
    private function formatChanges(array $changes): string
    {
        return implode('; ', array_map(
            fn (array $c): string => sprintf(
                '%s: %s -> %s',
                $c['field'],
                $this->scalar($c['old']),
                $this->scalar($c['new']),
            ),
            $changes,
        ));
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value) ?: '';
        }

        return $value === null ? '' : (string) $value;
    }
}
