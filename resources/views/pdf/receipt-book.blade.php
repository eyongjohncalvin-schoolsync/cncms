@php
    /**
     * A system-printed receipt book — App\Services\ReceiptBookService
     * (docs/plans/cash-controls.md). Four leaves per A4 page; each leaf is an
     * office stub (kept in the book) + the customer's copy (torn off along
     * the dashed line). The number is printed by the system, never written
     * by hand; the amount in words is written by the collector, then checked
     * against the recorded payment on the check page the QR opens.
     *
     * @var \App\Models\ReceiptBlock $block
     * @var \Illuminate\Support\Collection $pages  chunks of ['number','status','qr']
     */
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt book {{ $block->number }}</title>
    <style>
        @page { margin: 8mm; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 8.5pt; color: #111; margin: 0; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        table.leaf { width: 100%; border-collapse: collapse; height: 66mm; margin-bottom: 2.5mm; border: 1px solid #333; }
        td.stub { width: 30%; border-right: 1.5px dashed #555; padding: 3mm; vertical-align: top; background: #f5f5f5; }
        td.copy { width: 70%; padding: 3mm 4mm; vertical-align: top; }
        .company { font-weight: bold; font-size: 10pt; }
        .meta { font-size: 7pt; color: #444; }
        .number { font-family: 'DejaVu Sans Mono', monospace; font-weight: bold; font-size: 11pt; color: #b91c1c; letter-spacing: .5px; }
        .stub .number { font-size: 9pt; }
        .title { font-weight: bold; letter-spacing: 1px; font-size: 9.5pt; }
        .line { border-bottom: 1px dotted #666; height: 5mm; margin-top: 1mm; }
        .label { font-size: 7.5pt; color: #333; }
        table.fields { width: 100%; border-collapse: collapse; }
        table.fields td { padding: 0 2mm 0 0; vertical-align: bottom; }
        .box { display: inline-block; width: 3mm; height: 3mm; border: 1px solid #333; margin: 0 1mm 0 3mm; vertical-align: middle; }
        .qr { width: 22mm; height: 22mm; }
        .void { color: #b91c1c; font-weight: bold; }
        .head td { vertical-align: top; }
        .note { font-size: 6.5pt; color: #444; }
    </style>
</head>
<body>
@foreach ($pages as $leaves)
    <div class="page">
        @foreach ($leaves as $leaf)
            <table class="leaf">
                <tr>
                    <td class="stub">
                        <div class="label">OFFICE STUB — keep in book</div>
                        <div class="number">{{ $leaf['number'] }}</div>
                        <div class="label" style="margin-top: 2mm;">Date</div><div class="line"></div>
                        <div class="label">Customer / account</div><div class="line"></div>
                        <div class="label">Amount (FCFA)</div><div class="line"></div>
                        <div class="label">Cash / MTN / Orange · Txn ID</div><div class="line"></div>
                        <div class="meta" style="margin-top: 2mm;">Book {{ $block->number }} · {{ $holderName }}</div>
                        @if ($leaf['status'] !== 'unused')<div class="void">{{ strtoupper($leaf['status']) }}</div>@endif
                    </td>
                    <td class="copy">
                        <table class="head" style="width: 100%;">
                            <tr>
                                <td>
                                    @if ($logo)<img src="{{ $logo }}" style="max-height: 9mm; max-width: 30mm;"><br>@endif
                                    <span class="company">{{ $company['name'] }}</span><br>
                                    <span class="meta">{{ $company['location'] }}@if($company['phone']) · Tel {{ $company['phone'] }}@endif @if($company['momo_number']) · MOMO {{ $company['momo_number'] }}@endif</span>
                                </td>
                                <td style="text-align: right; width: 34mm;">
                                    <div class="title">RECEIPT / REÇU</div>
                                    <div class="number">{{ $leaf['number'] }}</div>
                                </td>
                            </tr>
                        </table>
                        <table class="fields">
                            <tr>
                                <td style="width: 35%;"><div class="label">Date</div><div class="line"></div></td>
                                <td><div class="label">Received from / Reçu de</div><div class="line"></div></td>
                            </tr>
                            <tr>
                                <td><div class="label">Account No.</div><div class="line"></div></td>
                                <td><div class="label">For (month(s)) / Pour</div><div class="line"></div></td>
                            </tr>
                            <tr>
                                <td><div class="label">Amount / Montant (FCFA)</div><div class="line"></div></td>
                                <td><div class="label">Amount in words / En lettres</div><div class="line"></div></td>
                            </tr>
                        </table>
                        <table style="width: 100%; margin-top: 1.5mm;">
                            <tr>
                                <td style="vertical-align: top;">
                                    <div class="label">
                                        <span class="box"></span>Cash <span class="box"></span>MTN MoMo <span class="box"></span>Orange Money
                                    </div>
                                    <div class="label" style="margin-top: 1.5mm;">Txn ID</div><div class="line" style="width: 60%;"></div>
                                    <div class="label" style="margin-top: 1.5mm;">Collected by: <strong>{{ $holderName }}</strong> · Signature</div><div class="line" style="width: 60%;"></div>
                                </td>
                                <td style="width: 24mm; text-align: center; vertical-align: top;">
                                    <img src="{{ $leaf['qr'] }}" class="qr" alt="QR">
                                    <div class="note">Scan to check<br>Scannez pour vérifier</div>
                                </td>
                            </tr>
                        </table>
                        <div class="note">Only receipts printed with a number and QR by {{ $company['name'] }} are valid. Scan the QR: it shows the amount we recorded.</div>
                    </td>
                </tr>
            </table>
        @endforeach
    </div>
@endforeach
</body>
</html>
