<?php

/*
 * Outreach (B2B prospecting) — follow-up templates and the sign-off.
 *
 * Merge fields: {first_name} {company} {original_subject} {sender}
 * Placeholders [Name], [Signature] and [Opt-out line] in a drafted email are
 * expanded the same way. Wording follows docs/growth/sales/2026-10-28-emails.md.
 * Nothing here is ever sent by the server — it pre-fills the director's own
 * email app via a mailto: link.
 */
return [

    // A prospect whose segment contains one of these words gets the PARTNER
    // (operator-to-operator) follow-ups; everyone else gets the CORPORATE ones.
    'partner_keywords' => ['partner', 'operator', 'chauffeur', 'travel management', 'tmc', 'trade'],

    'signature' => "{sender}\nDirector, Central Executive Transfers Ltd\n0114 399 8242 · admin@centralexecutivetransfers.co.uk\ncentralexecutivetransfers.co.uk · Licensed operator OP037 · Company No. 15749931",

    'opt_out' => "If this isn't relevant, just reply \"no thanks\" and I won't contact you again.",

    'follow_ups' => [
        'corporate' => [
            'day_4' => [
                'label' => 'Day-4 follow-up',
                'subject' => 'Re: {original_subject}',
                'body' => "Hello {first_name},\n\n"
                    ."A quick follow-up from a different angle: the person who books the car.\n\n"
                    ."When we collect your visitor, you get the driver's name and vehicle before pickup, plus a live tracking link. You can see they've been collected without chasing anyone. Inbound flights are tracked, so a two-hour delay doesn't become your problem. Calls between visitor and driver use masked numbers, so nobody's personal number is shared.\n\n"
                    ."If it helps, I can set {company} up with an account and you could try us on your next visitor. One journey, invoiced like the rest.\n\n"
                    ."Worth a 10-minute call?\n\n"
                    ."[Signature]\n\n[Opt-out line]",
            ],
            'day_10' => [
                'label' => 'Day-10 follow-up',
                'subject' => 'Corporate rate card - Central Executive Transfers',
                'body' => "Hello {first_name},\n\n"
                    ."One last useful thing. Here is how a CET account works in practice:\n\n"
                    ."- Prices for your regular routes are agreed once and fixed. No surge.\n"
                    ."- You book by phone, email or online, and you get driver details and a tracking link before each pickup.\n"
                    ."- At month end you receive one VAT invoice from our VAT-registered Ltd covering every journey.\n\n"
                    ."Shall I send the rate card over? If the timing's wrong, just say and I'll leave it there.\n\n"
                    ."[Signature]\n\n[Opt-out line]",
            ],
        ],
        'partner' => [
            'day_4' => [
                'label' => 'Day-4 follow-up',
                'subject' => 'Re: {original_subject}',
                'body' => "Hello {first_name},\n\n"
                    ."A quick follow-up on the part that matters most to operators: staying invisible. We send your passenger an unbranded driver link with driver details and live tracking, and no CET branding appears anywhere. You keep the client and the relationship. We just do the miles in South Yorkshire and send you one cover invoice per period.\n\n"
                    ."Happy to run a single test job so you can see it end to end.\n\n"
                    ."[Signature]\n\n[Opt-out line]",
            ],
            'day_10' => [
                'label' => 'Day-10 follow-up',
                'subject' => 'Trade rates for South Yorkshire',
                'body' => "Hello {first_name},\n\n"
                    ."I'll keep this short. If you ever have a job starting or ending in Sheffield, South Yorkshire, North Derbyshire or at East Midlands Airport, we can cover it at agreed trade rates with unbranded tracking and one cover invoice. Shall I send the rate card and the one-page partner outline?\n\n"
                    ."[Signature]\n\n[Opt-out line]",
            ],
        ],
    ],
];
