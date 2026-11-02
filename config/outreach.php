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
    // Follow-ups follow docs/growth/sales/kit/cold-email-rules-v2.md: short, about
    // the reader, a NEW angle each time (never "just following up"), one easy
    // question, no service lists, no em dashes.
    'follow_ups' => [
        'corporate' => [
            'day_4' => [
                'label' => 'Day-4 follow-up',
                'subject' => 'Re: {original_subject}',
                'body' => "Hello {first_name},\n\n"
                    ."When a flight lands early or a meeting runs over, it's usually the PA left chasing the car.\n\n"
                    ."We track every flight and the two of us answer the phone ourselves, day or night, so there's nobody to chase.\n\n"
                    ."Would it help to have an account set up for {company}, ready for next time?\n\n"
                    ."[Signature]\n\n[Opt-out line]",
            ],
            'day_10' => [
                'label' => 'Day-10 follow-up',
                'subject' => 'Re: {original_subject}',
                'body' => "Hello {first_name},\n\n"
                    ."We'll leave it here so we don't clutter your inbox.\n\n"
                    ."If a visitor or colleague ever needs collecting in South Yorkshire, reply to this email and we'll take it from there.\n\n"
                    ."[Signature]\n\n[Opt-out line]",
            ],
        ],
        'partner' => [
            'day_4' => [
                'label' => 'Day-4 follow-up',
                'subject' => 'Re: {original_subject}',
                'body' => "Hello {first_name},\n\n"
                    ."Happy to start small. If a single Sheffield, Manchester Airport or East Midlands job comes up that you'd rather not send a car 100 miles for, give it to us and judge us on that one.\n\n"
                    ."Is there a job coming up we could take off your hands?\n\n"
                    ."[Signature]\n\n[Opt-out line]",
            ],
            'day_10' => [
                'label' => 'Day-10 follow-up',
                'subject' => 'Re: {original_subject}',
                'body' => "Hello {first_name},\n\n"
                    ."Last one from us. If South Yorkshire or the East Midlands ever comes up for {company}, reply here and we'll pick it up straight away.\n\n"
                    ."[Signature]\n\n[Opt-out line]",
            ],
        ],
    ],
];
