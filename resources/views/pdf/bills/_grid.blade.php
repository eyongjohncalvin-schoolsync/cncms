{{--
    Bulk N-up bill grid — takes $bills (an array of per-bill data arrays,
    each shaped exactly like App\Services\ManuscriptService::billData()'s
    return value, e.g. from billDataForCustomers()), a $density (1/2/3/4),
    and a $template name, and tiles them onto sheets.

    Mechanism, per this cycle's design review:
      - Chunked in PHP via collect($bills)->chunk($density) — one chunk per
        physical sheet/page.
      - Rendered as an HTML <table class="sheet-grid"> per sheet (dompdf has
        no CSS Grid/Flexbox support — confirmed via GitHub issue research
        this session — so this deliberately does NOT attempt either).
      - density 1 -> 1x1 (one full-page bill per sheet).
        density 2 -> 1 column x 2 rows (bill cards are portrait-ish, so
          stacking beats side-by-side for 2-up).
        density 3 -> 1 column x 3 rows (same single-column stack as 2-up,
          one card per third of the sheet).
        density 4 -> 2 columns x 2 rows.
      - The final, possibly-ragged chunk is padded with empty <td>s so every
        row is rectangular — ragged rows destabilize dompdf's table layout.
      - Page breaks between sheets use page-break-after: always on a
        wrapping <div>, computed per-chunk in PHP (not a :last-child CSS
        selector) so the very last sheet doesn't emit a trailing blank page
        — page-break-inside: avoid is deliberately NOT used here (documented
        as unreliable in dompdf: it silently drops explicit column widths).
      - density > 1 always uses the 'compact' template inside each cell
        regardless of the $template argument — Kumba Compact is the one
        sized for a ~1/2 or 1/4 A4 cell; density 1 uses whichever of the
        three templates the tenant selected.
      - The logo data URI is resolved exactly ONCE by the caller (see
        ManuscriptService::billDataForCustomers()'s doc comment on the
        performance bug this fixes) and threaded through every bill's own
        'logo_data_uri' key — this view never calls
        Company::logoDataUri() itself.
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Bills</title>
    <style>
        /* dompdf ships a UA default of `@page { margin: 1.2cm }`, so without
           this the usable sheet is only 273mm x 186mm and every N-up row
           height computed against 297mm overflows. Zero it out — the grid
           is full-bleed by design (the cut lines are the dashed cell
           borders) and `body { margin: 0 }` already assumes this. */
        @page {
            margin: 0;
        }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            margin: 0;
            padding: 0;
        }
        table.sheet-grid {
            table-layout: fixed;
            width: 100%;
            margin: 0;
            border-collapse: collapse;
        }
        table.sheet-grid td {
            vertical-align: top;
            /* dompdf 3.x has NO `box-sizing` support (it throws on the
               property), so `height` below is the CONTENT box — the 6px
               padding (top+bottom) and the collapsed 0.5px border add
               ~3.4mm of chrome per row ON TOP of it. The per-density
               heights computed below already subtract that chrome so
               N rows still sum to <= 297mm. */
            padding: 6px;
            border: 0.5px dashed #999;
            overflow: hidden;
        }
    </style>
</head>
<body>
@php
    $perPage = in_array((int) $density, [1, 2, 3, 4], true) ? (int) $density : 1;
    $effectiveTemplate = $perPage > 1 ? 'compact' : $template;

    // Row height math. The usable sheet is the full A4 portrait page —
    // 297mm — because `@page { margin: 0 }` in the <style> above cancels
    // dompdf's 1.2cm UA default and `body` has no margin/padding.
    //
    // `height` on a <td> is dompdf's CONTENT box (it has no `box-sizing`),
    // and each cell adds ~3.4mm of chrome on top: 6px+6px padding (=9pt
    // =3.18mm) plus the collapsed 0.5px dashed border. So the CONTENT
    // height per row must be (297mm / rows) - 3.4mm, then rounded down a
    // little more so dompdf's sub-pixel rounding can't tip a row over:
    //   1-up: 297   - 3.4 -> 293mm
    //   2-up: 148.5 - 3.4 -> 144mm  (2 x (144 + 3.4) = 294.8mm <= 297)
    //   3-up: 99    - 3.4 -> 95mm   (3 x (95  + 3.4) = 295.2mm <= 297)
    //   4-up: 148.5 - 3.4 -> 144mm  (two rows, same as 2-up)
    // Result: every density fits one sheet per page, N bills -> exactly
    // ceil(N / perPage) pages, nothing clipped or past the edge.
    $geometry = match ($perPage) {
        2 => ['rows' => 2, 'cols' => 1, 'height' => '144mm'],
        3 => ['rows' => 3, 'cols' => 1, 'height' => '95mm'],
        4 => ['rows' => 2, 'cols' => 2, 'height' => '144mm'],
        default => ['rows' => 1, 'cols' => 1, 'height' => '293mm'],
    };

    $chunks = collect($bills)->chunk($perPage)->values();
    $lastChunkIndex = $chunks->count() - 1;
@endphp
@foreach ($chunks as $chunkIndex => $chunk)
    @php
        $cells = $chunk->values();
        // Pad the final, possibly-short chunk with nulls so every row is a
        // full rectangle of <td>s — see this file's doc comment above.
        while ($cells->count() < $perPage) {
            $cells->push(null);
        }
        $isLastSheet = $chunkIndex === $lastChunkIndex;
    @endphp
    <div @if (! $isLastSheet) style="page-break-after: always;" @endif>
        <table class="sheet-grid">
            <tbody>
                @for ($row = 0; $row < $geometry['rows']; $row++)
                    <tr>
                        @for ($col = 0; $col < $geometry['cols']; $col++)
                            @php $cell = $cells->get($row * $geometry['cols'] + $col); @endphp
                            <td style="width: {{ number_format(100 / $geometry['cols'], 4) }}%; height: {{ $geometry['height'] }};">
                                @if ($cell)
                                    @include('pdf.bills.'.$effectiveTemplate, $cell)
                                @endif
                            </td>
                        @endfor
                    </tr>
                @endfor
            </tbody>
        </table>
    </div>
@endforeach
</body>
</html>
