<?php

declare(strict_types=1);

/*
| Public website (cncms.tech): home, about, contact, privacy, terms.
| Never name the technology or providers behind the service here.
*/

return [
    'brand_by' => 'by OneTech',
    'meta_description' => 'CNCMS is software for cable TV operators: customers, monthly manuscripts, payments, field agents and cash control in one place. Free 4-month demo.',
    'skip' => 'Skip to content',

    'nav' => [
        'features' => 'Features',
        'app' => 'Agent app',
        'how' => 'How it works',
        'security' => 'Security',
        'contact' => 'Contact',
        'about' => 'About',
        'login' => 'Log in',
        'demo' => 'Free demo',
        'dashboard' => 'Open your workspace',
        'language' => 'Language',
        'menu' => 'Menu',
    ],

    'hero' => [
        'eyebrow' => 'Software for cable TV operators',
        'title' => 'Run your cable network <em>without the paper.</em>',
        'lede' => 'Customers, monthly manuscripts, payments, field agents and every franc of cash, in one place. Built in Kumba with cable operators who run it every day.',
        'demo' => 'Register for a free 4-month demo',
        'fine' => 'No card needed. Your data stays yours, even after the demo.',
        'shot_label' => 'Example of the CNCMS dashboard',
        'shot_title' => 'Manuscript · November 2026',
        'shot_tag' => 'Published',
        'collected' => 'Collected',
        'customers' => 'Customers',
        'paid' => 'Paid so far',
        'phone_title' => 'Payment recorded',
        'phone_line' => 'Receipt 000412 · Fiango',
        'phone_wa' => 'Send on WhatsApp',
        'sample' => 'Example figures',
    ],

    'clients' => [
        'title' => 'Cable companies running on CNCMS',
        'slot_title' => 'Your company',
        'slot_text' => 'Appears here when you join',
    ],

    'pains' => [
        'eyebrow' => 'Sound familiar?',
        'title' => 'The problems every cable operator knows',
        'items' => [
            ['The money doesn\'t add up', 'Collections, hand-ins and the till never agree, and nobody can say where the gap is.'],
            ['Manuscripts on paper', 'Books get lost, copied wrong, or rewritten every month by hand.'],
            ['Agents\' cash you can\'t trace', 'You find out about missing money weeks later, if at all.'],
            ['Which zone is paying?', 'No clear picture of who paid, who owes, and which area is slipping.'],
        ],
    ],

    'features' => [
        'eyebrow' => 'What CNCMS does',
        'title' => 'Everything a cable company runs on, in one system',
        'items' => [
            'customers' => ['Customers and zones', 'Every subscriber, their services, price and zone. Archive without losing history.'],
            'manuscripts' => ['Monthly manuscripts and bills', 'Generate the month in minutes, carry debts and prepayments forward, print bills by zone.'],
            'payments' => ['Payments and receipts', 'Numbered receipts for every payment, shared on WhatsApp in one tap.'],
            'app' => ['Field agent phone app', 'Agents record payments in the field, even without network. It syncs when the signal returns.'],
            'cash' => ['Cash controls', 'Tills, hand-ins, shortages and a monthly money balance that shows every franc.'],
            'zones' => ['Zone performance', 'See which zones are paying and get an alert when one drops below its usual level.'],
            'expenses' => ['Expenses and approvals', 'Vouchers, advances and salaries, approved by the right people before money leaves.'],
            'complaints' => ['Complaints and disconnections', 'Log faults, plan disconnections and reconnections, and charge the right fees.'],
            'roles' => ['Staff roles and branches', 'Decide who can see and do what, branch by branch. Every action is recorded.'],
        ],
    ],

    'app' => [
        'eyebrow' => 'The agent app',
        'title' => 'Your office, in every agent\'s pocket',
        'lede' => 'A fast Android app for your field agents. It keeps working with no network and sends everything to the office when the signal comes back.',
        'label' => 'Example screen of the agent app',
        'zone' => 'Fiango · 214 customers',
        'offline' => 'Offline · 3 waiting',
        'search' => 'Search name, phone or box number',
        'owes_1' => 'Owes 5,000 frs',
        'paid' => 'Paid · November',
        'owes_2' => 'Owes 2,500 frs',
        'cut' => 'Due for disconnection',
        'pay' => 'Pay',
        'tabs' => ['Home', 'Customers', 'Pay', 'More'],
        'items' => [
            ['Works offline', 'Record payments in any village, sync later. Nothing is lost.'],
            ['Payment in seconds', 'Find the customer, enter the amount, and the receipt goes out on WhatsApp.'],
            ['Their zone, their list', 'Who paid, who owes, and who is due for disconnection.'],
            ['Field work', 'New customers, disconnections, reconnections, complaints and expenses.'],
            ['Cash hand-over', 'Agents hand cash to the office in the app, so every franc has a trail.'],
            ['For managers too', 'The month\'s manuscript, zone performance, reports and alerts on the phone.'],
        ],
    ],

    'local' => [
        ['Weak network? Fine.', 'Pages are light and the agent app works offline.'],
        ['English and French', 'Each person picks their language.'],
        ['Made for FCFA', 'Monthly bills, debts and prepayments the way cable works here.'],
        ['WhatsApp receipts', 'Customers get proof of payment on the phone they already use.'],
    ],

    'how' => [
        'eyebrow' => 'How it works',
        'title' => 'Set up your company yourself, in four steps',
        'lede' => 'A setup guide shows you each step the first time you log in. If you get stuck, our team is one WhatsApp message away.',
        'steps' => [
            ['Register for the demo', 'Fill in the registration form. Once your workspace is approved, you log in as the company\'s administrator.'],
            ['Add your zones', 'Download the zone template, fill in your zones and upload it. Or add them one by one on screen.'],
            ['Add your customers', 'Download the customer template, fill it in from your books and upload it. Every customer lands in the right zone.'],
            ['Bring in your agents', 'Create their accounts and show them how to download the agent app. Then run your first month.'],
        ],
        'guide_label' => 'Example of the setup guide',
        'guide_who' => 'Setup guide',
        'guide_msg' => 'Welcome to CNCMS! You have 0 zones so far. Step 2 of 4: download the zone template, fill in one zone per row, then upload it here.',
        'guide_b1' => 'Download zone template',
        'guide_b2' => 'Add zones by hand',
        'guide_title' => 'A guide on your screen, not a manual',
        'guide_text' => 'From your first login, the guide checks what is done and shows you the next step: zones, customers, staff, the agent app, your first month.',
    ],

    'security' => [
        'eyebrow' => 'Security',
        'title' => 'Your company\'s data, protected',
        'items' => [
            'Two-step login for managers and owners',
            'Every payment, change and approval is recorded with who and when',
            'Encrypted backups every night, kept in two separate secure places',
            'Each company sees only its own data. We never sell or share it.',
        ],
        'log_title' => 'Activity log · today',
        'log' => [
            ['08:12', 'Agent Brenda opened the Fiango till'],
            ['10:47', 'Receipt 000412 issued · 2,500 frs'],
            ['13:05', 'Voucher V-118 approved by the manager'],
            ['17:30', 'Till closed · count matches'],
        ],
    ],

    'quote' => [
        'text' => '“We used to spend the first week of every month rewriting books. Now the month is ready before it starts.”',
        'by' => 'MDCN — Multimedia Digital Cable Network, Kumba',
    ],

    'faq' => [
        'eyebrow' => 'Questions',
        'title' => 'Frequently asked',
        'items' => [
            ['What happens after the 4-month demo?', 'You choose. Continue on a paid plan, or stop. If you stop, you can still log in and export your data. We never delete it without asking you.'],
            ['Can I bring in the customers from my books?', 'Yes. Download the customer template, fill it in, and upload it. Zones work the same way.'],
            ['Do my agents need internet all the time?', 'No. The agent app records payments offline and sends them when the network returns.'],
            ['Is my company\'s data safe?', 'Each company sees only its own data. Managers can use two-step login, every action is recorded, and encrypted backups are made every night and kept in two separate places.'],
            ['We have more than one town. Does it work?', 'Yes. Run several branches in one company, each with its own staff, zones and prices, and see them together.'],
            ['How much does it cost after the demo?', 'It depends on the size of your network. Call or WhatsApp us on :phone for a price.'],
        ],
    ],

    'demo' => [
        'eyebrow' => 'Free for 4 months',
        'title' => 'Try CNCMS with your own customers',
        'lede' => 'Four months, the full system, no payment. At the end you choose: continue, or leave and take your data with you.',
        'cta' => 'Register for the free demo',
        'call' => 'Prefer to talk first? Call or WhatsApp us.',
    ],

    'contact' => [
        'title' => 'Contact us',
        'lede' => 'Questions about CNCMS, the demo, or your account? Our team answers by phone, WhatsApp and email.',
        'phone' => 'Phone',
        'whatsapp' => 'WhatsApp',
        'email' => 'Email',
        'office' => 'Office',
        'office_value' => 'Kumba, Cameroon',
        'chat' => 'Chat with us',
        'support_title' => 'Already using CNCMS?',
        'support_text' => 'Log in and tell us your company name when you write, so we can help you faster.',
    ],

    'about' => [
        'title' => 'About OneTech',
        'lede' => 'OneTech builds CNCMS, software made for cable TV operators in Cameroon.',
        'body' => [
            'CNCMS started in Kumba, inside a working cable company. Every month the same problems came back: manuscripts rewritten by hand, collections that did not match the till, and no clear view of which zones were paying.',
            'We built CNCMS to fix those problems for good: one place for customers, monthly manuscripts, payments, field agents and cash, with an app that keeps working when the network does not.',
            'Today CNCMS runs real cable networks every day. We keep improving it with the operators who use it, and our team is a phone call or a WhatsApp message away.',
        ],
        'values_title' => 'What we care about',
        'values' => [
            ['Money that balances', 'Every franc collected can be traced from the customer to the bank.'],
            ['Built for here', 'FCFA, English and French, WhatsApp, and networks that come and go.'],
            ['Your data is yours', 'Each company sees only its own data, and can take it with them at any time.'],
        ],
    ],

    'legal' => [
        'privacy' => 'Privacy policy',
        'terms' => 'Terms of service',
        'version' => 'Version :version · in effect since :date',
        'questions' => 'Questions about this document? Write to :email.',
    ],

    'footer' => [
        'rights' => '© :year OneTech · Kumba, Cameroon',
        'privacy' => 'Privacy',
        'terms' => 'Terms',
    ],

    'whatsapp_float' => 'Chat with us',
];
