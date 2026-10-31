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

    // Length of the free demo a self-service sign-up gets on approval. After
    // it the workspace is read-only until a landlord activates it.
    'demo_months' => 4,

    // Send a company's super/admin to accept Terms published as "must
    // accept" (EnsureLegalAccepted). Off only in the test suite, where the
    // seeded companies never signed up; the legal tests turn it back on.
    'enforce_legal_acceptance' => (bool) env('LEGAL_ENFORCE_ACCEPTANCE', true),

    // Version 1 of Privacy and Terms (resources/legal/{locale}/*.md), shown
    // only until the database holds a published version (it is copied in
    // by the legal documents migration). Later versions: Landlord → Legal.
    'legal_version' => 1,
    'legal_effective' => '2026-11-01',
];
