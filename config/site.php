<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Public website
|--------------------------------------------------------------------------
|
| Contact details shown on cncms.tech (home, contact, legal pages and the
| WhatsApp button), and the first versions of the legal documents.
|
*/

return [
    'company' => 'OneTech',

    'phone' => env('SITE_PHONE', '+237 672 528 022'),

    // International number without "+" or spaces, for wa.me links.
    'whatsapp' => env('SITE_WHATSAPP', '237672528022'),

    'email' => env('SITE_EMAIL', 'onetechcncms@gmail.com'),

    // Version 1 of Privacy and Terms (resources/legal/{locale}/*.md).
    'legal_version' => 1,
    'legal_effective' => '2026-11-01',
];
