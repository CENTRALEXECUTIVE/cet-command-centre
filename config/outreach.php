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

    'signature' => "Abdi & Maj\nDirectors, Central Executive Transfers\n0114 399 8242 · admin@centralexecutivetransfers.co.uk · centralexecutivetransfers.co.uk",

    'opt_out' => "If this isn't relevant, just let us know and we won't contact you again.",

    // Follow-ups follow docs/growth/sales/kit/email-style-guide.md: short, about
    // the reader, one easy question, no feature lists.
    'follow_ups' => [
        'corporate' => [
            'day_4' => [
                'label' => 'Day-4 follow-up',
                'subject' => 'Re: {original_subject}',
                'body' => "Hello {first_name},\n\n"
                    ."Just following up on our note last week, in case it was buried.\n\n"
                    ."When a visitor, client or senior colleague needs collecting, the person arranging it usually wants one thing: to know it's handled. That's what we do, whether it's an airport or station pickup, a day of meetings with the car waiting, or a run to London and back.\n\n"
                    ."If it would help, we could set {company} up with an account so it's ready the next time something comes up. Would that be useful?\n\n"
                    ."[Signature]\n\n[Opt-out line]",
            ],
            'day_10' => [
                'label' => 'Day-10 follow-up',
                'subject' => 'Chauffeur account for {company}',
                'body' => "Hello {first_name},\n\n"
                    ."A last note from us, and then we'll leave you in peace.\n\n"
                    ."If {company} ever needs a dependable chauffeur in South Yorkshire, for executives, visitors or events, we'd be glad to help. Everything runs on one account with a single monthly VAT invoice, and you'll always deal with one of the two of us directly.\n\n"
                    ."Shall we send our account details over so you have them on file?\n\n"
                    ."[Signature]\n\n[Opt-out line]",
            ],
        ],
        'partner' => [
            'day_4' => [
                'label' => 'Day-4 follow-up',
                'subject' => 'Re: {original_subject}',
                'body' => "Hello {first_name},\n\n"
                    ."Just following up on our note last week.\n\n"
                    ."If you ever have a passenger starting or finishing in Sheffield, South Yorkshire or the East Midlands, we'd be glad to look after them for you: experienced chauffeurs, an unbranded service so your client stays yours, and someone available 24/7.\n\n"
                    ."We're happy to start with a single job so you can see how we work. Would that be worth a quick call?\n\n"
                    ."[Signature]\n\n[Opt-out line]",
            ],
            'day_10' => [
                'label' => 'Day-10 follow-up',
                'subject' => 'South Yorkshire cover for {company}',
                'body' => "Hello {first_name},\n\n"
                    ."A last note from us. If South Yorkshire or the East Midlands ever comes up for {company}, we'd like to be the partner you call, and any work in your area that comes our way would go to you.\n\n"
                    ."Shall we send our trade details over so you have them on file?\n\n"
                    ."[Signature]\n\n[Opt-out line]",
            ],
        ],
    ],
];
