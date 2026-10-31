@php
    /**
     * The signed service contract — App\Services\ContractService::sign().
     * The text exactly as signed (blanks filled in), then the online
     * signature record. Stored once at signing; never re-rendered.
     *
     * @var \App\Models\ContractSignature $signature
     * @var \App\Models\LegalDocumentVersion $contract
     * @var string $bodyHtml
     */
    $fr = $signature->locale === 'fr';
    $signedAt = $signature->signed_at->copy()->setTimezone(\App\Support\BusinessTimezone::WAT);
@endphp
<!DOCTYPE html>
<html lang="{{ $signature->locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $fr ? 'Contrat de service' : 'Service contract' }} — {{ $signature->company_name }}</title>
    <style>
        @page { margin: 18mm 16mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; line-height: 1.45; color: #111; margin: 0; }
        .head { border-bottom: 2px solid #1e3a8a; padding-bottom: 3mm; margin-bottom: 5mm; }
        .brand { font-size: 8pt; color: #1e3a8a; font-weight: bold; letter-spacing: 1px; }
        h1 { font-size: 15pt; margin: 1mm 0 0; }
        .meta { font-size: 8pt; color: #555; margin-top: 1mm; }
        h2 { font-size: 10.5pt; margin: 5mm 0 1mm; color: #1e3a8a; }
        p { margin: 1.5mm 0; }
        ul { margin: 1mm 0; padding-left: 5mm; }
        li { margin: 0.8mm 0; }
        .sign { width: 100%; border-collapse: collapse; margin-top: 8mm; page-break-inside: avoid; }
        .sign td { width: 50%; border: 1px solid #333; padding: 3mm; vertical-align: top; font-size: 8.5pt; }
        .sign .who { font-weight: bold; font-size: 9.5pt; }
        .typed { font-family: 'DejaVu Serif', serif; font-style: italic; font-size: 14pt; margin: 2mm 0; }
        .k { color: #555; }
        .foot { margin-top: 6mm; font-size: 7pt; color: #666; word-break: break-all; }
    </style>
</head>
<body>
    <div class="head">
        <div class="brand">ONETECH · CNCMS</div>
        <h1>{{ $fr ? 'Contrat de service' : 'Service contract' }}</h1>
        <div class="meta">
            {{ $signature->company_name }} · {{ $fr ? 'Version' : 'Version' }} {{ $contract->version }}
            · {{ $fr ? 'signé le' : 'signed' }} {{ $signedAt->format('d/m/Y H:i') }} ({{ $fr ? 'heure du Cameroun' : 'Cameroon time' }})
        </div>
    </div>

    {!! $bodyHtml !!}

    <table class="sign">
        <tr>
            <td>
                <div class="who">{{ $fr ? "Pour l'Opérateur" : 'For the Operator' }} — {{ $signature->company_name }}</div>
                <div class="typed">{{ $signature->signer_name }}</div>
                @if ($signature->signer_title)
                    <div>{{ $signature->signer_title }}</div>
                @endif
                <div><span class="k">{{ $fr ? 'Signé en ligne le' : 'Signed online on' }}</span> {{ $signedAt->format('d/m/Y H:i') }}</div>
                <div><span class="k">{{ $fr ? 'Adresse IP' : 'IP address' }}</span> {{ $signature->ip_address ?? '—' }}</div>
            </td>
            <td>
                <div class="who">{{ $fr ? 'Pour' : 'For' }} {{ config('site.company') }}</div>
                <div class="typed">{{ config('site.company') }}</div>
                <div>{{ $fr ? 'Version publiée le' : 'Version published on' }} {{ $contract->published_at?->copy()->setTimezone(\App\Support\BusinessTimezone::WAT)->format('d/m/Y') ?? '—' }}</div>
                <div>Kumba · {{ config('site.phone') }} · {{ config('site.email') }}</div>
            </td>
        </tr>
    </table>

    <div class="foot">
        {{ $fr ? 'Empreinte du texte signé (SHA-256)' : 'Fingerprint of the signed text (SHA-256)' }}: {{ $signature->body_sha256 }}
    </div>
</body>
</html>
