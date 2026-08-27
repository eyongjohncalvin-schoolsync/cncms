<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Bill {{ $bill_number }}</title>
    <style>
        /* DejaVu Sans/Serif/Sans Mono only — dompdf's built-in Unicode-safe
           font set. No @font-face web fonts, no non-ASCII currency glyphs
           (FCFA is written as plain text everywhere). */
        body {
            font-family: 'DejaVu Sans', sans-serif;
            margin: 0;
            padding: 0;
            color: #111;
        }
    </style>
</head>
<body>
    {{-- @include without an explicit data array inherits this view's own
         variable scope, so the chosen template partial sees exactly the
         same $company/$customer/$manuscript/... variables this wrapper
         was given — no need to re-list them here. --}}
    @include('pdf.bills.'.$template)
</body>
</html>
