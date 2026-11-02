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

    'signature' => "Central Executive Transfers\n0114 399 8242 · admin@centralexecutivetransfers.co.uk · centralexecutivetransfers.co.uk",

    'opt_out' => "If this isn't relevant, just let us know and we won't contact you again.",

    // Follow-ups follow docs/growth/sales/kit/email-style-guide.md: short, about
    // the reader, one easy question, no feature lists.
    // Follow-ups in the house style (docs/growth/sales/kit/house-email-style.md):
    // formal, confident, about the service, one polite closing question.
    'follow_ups' => [
        'corporate' => [
            'day_4' => [
                'label' => 'Day-4 follow-up',
                'subject' => 'Re: {original_subject}',
                'body' => "Dear {first_name},\n\n"
                    ."We wanted to follow up on our note about a corporate chauffeur account for {company}.\n\n"
                    ."Many of our clients use us for the journeys that matter most: collecting visiting clients from the airport, getting senior staff to meetings on time, and keeping a car on hand throughout roadshows and events. Every journey is at a fixed fare agreed in advance, with one monthly VAT invoice.\n\n"
                    ."Would a short overview of the account service be helpful?\n\n"
                    ."Kind regards,\n[Signature]\n\n[Opt-out line]",
            ],
            'day_10' => [
                'label' => 'Day-10 follow-up',
                'subject' => 'Re: {original_subject}',
                'body' => "Dear {first_name},\n\n"
                    ."We appreciate you will be busy, so this will be our last note for now.\n\n"
                    ."Should {company} need executive transport in the future, whether an airport collection, a day of meetings or an event, we would be pleased to help. Simply reply to this email and we will take care of the rest.\n\n"
                    ."Kind regards,\n[Signature]\n\n[Opt-out line]",
            ],
        ],
        'partner' => [
            'day_4' => [
                'label' => 'Day-4 follow-up',
                'subject' => 'Re: {original_subject}',
                'body' => "Dear {first_name},\n\n"
                    ."We wanted to follow up on our note about a reciprocal partnership.\n\n"
                    ."We would be glad to support your bookings across Sheffield, South Yorkshire and the East Midlands, including Manchester, East Midlands and Leeds Bradford airports, and to refer work in your area to you in return. We are happy to start with a single booking so you can see how we operate.\n\n"
                    ."Would you be open to exchanging operator details?\n\n"
                    ."Kind regards,\n[Signature]\n\n[Opt-out line]",
            ],
            'day_10' => [
                'label' => 'Day-10 follow-up',
                'subject' => 'Re: {original_subject}',
                'body' => "Dear {first_name},\n\n"
                    ."This will be our last note for now. Should you ever need reliable cover in Sheffield, South Yorkshire or the East Midlands, simply reply to this email and we will be pleased to help.\n\n"
                    ."Kind regards,\n[Signature]\n\n[Opt-out line]",
            ],
        ],
    ],
];
