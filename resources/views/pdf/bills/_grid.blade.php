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
        density 2/3/4 -> ONE ROW of 2/3/4 side-by-side full-height columns
          (owner's ask: "stack horizontally ... so that when printed they
          come out long"). Each column is a tall narrow strip you cut apart
          down the thick vertical rules — traditional bill-book style. The
          bill sits at the top of its strip; the rest is blank and trimmed.
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
        /* dompdf ships a UA default of `@page { margin: 1.2cm }` — zero it
           out so the strips run the full sheet and `body { margin: 0 }`
           holds. */
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
            /* Each cell is one full-height A4 strip. Separator between the
               side-by-side bills is a single THICK vertical rule (owner's
               ask: "separated from the next by just a thick line") — cut
               along it. No box around the cell; the trailing strip's right
               rule sits at the sheet edge. dompdf has no `box-sizing`, so
               keep padding tiny to leave the content width close to the
               nominal 1/N of the page. */
            padding: 3px 4px;
            border: 0;
            border-right: 1.75pt solid #000;
            overflow: hidden;
        }
    </style>
</head>
<body>
@php
    $perPage = in_array((int) $density, [1, 2, 3, 4], true) ? (int) $density : 1;
    $effectiveTemplate = $perPage > 1 ? 'compact' : $template;

    // Layout: 1-up fills the page; 2/3/4-up put that many bills SIDE BY SIDE
    // in a single row of full-height (293mm content box) strips. The bill
    // sits at the top of its strip (vertical-align: top); everything below
    // is blank and trimmed after cutting down the vertical rules.
    $cols = $perPage;
    $stripHeight = $perPage === 1 ? '293mm' : '290mm';

    $chunks = collect($bills)->chunk($perPage)->values();
    $lastChunkIndex = $chunks->count() - 1;
@endphp
@foreach ($chunks as $chunkIndex => $chunk)
    @php
        $cells = $chunk->values();
        // Pad the final, possibly-short chunk with nulls so the row is a
        // full rectangle of <td>s — see this file's doc comment above.
        while ($cells->count() < $perPage) {
            $cells->push(null);
        }
        $isLastSheet = $chunkIndex === $lastChunkIndex;
    @endphp
    <div @if (! $isLastSheet) style="page-break-after: always;" @endif>
        <table class="sheet-grid">
            <tbody>
                <tr>
                    @foreach ($cells as $cell)
                        <td style="width: {{ number_format(100 / $cols, 4) }}%; height: {{ $stripHeight }};">
                            @if ($cell)
                                @include('pdf.bills.'.$effectiveTemplate, $cell + ['grid_columns' => $cols])
                            @endif
                        </td>
                    @endforeach
                </tr>
            </tbody>
        </table>
    </div>
@endforeach
</body>
</html>
