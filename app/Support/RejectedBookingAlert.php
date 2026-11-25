<?php

namespace App\Support;

use App\Services\Watchdog\AdminAlerts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Directors (24 Nov 2026): when the website REFUSES a booking (the red box), tell us
 * straight away so we can ring the customer and help. A US customer tried several times
 * and gave up after the form silently rejected him. Push to both directors (critical, so
 * it gets through "critical only") + an email to the office with everything they typed.
 * Never throws: an alert failure must not change what the customer sees.
 */
class RejectedBookingAlert
{
    public static function send(Request $request, ValidationException $e, string $form): void
    {
        try {
            // Only a real person we can help: a name AND a phone or email (an empty
            // anonymous post used to page both directors — QA 25 Nov 2026).
            $typedName = trim((string) ($request->input('customer_name')
                ?: trim($request->input('customer_first_name').' '.$request->input('customer_surname'))));
            $hasContact = filled($request->input('customer_phone')) || filled($request->input('customer_email'));
            if ($typedName === '' || ! $hasContact) {
                return;
            }
            // At most one a minute for the same person, and 3 per 10 minutes from one
            // connection whatever details they type.
            $who = strtolower(trim((string) ($request->input('customer_email') ?: $request->input('customer_phone'))));
            if (! Cache::add('rejected-booking:'.md5($who), 1, 60)) {
                return;
            }
            $ipKey = 'rejected-booking-ip:'.md5((string) $request->ip());
            Cache::add($ipKey, 0, 600);
            if (Cache::increment($ipKey) > 3) {
                return;
            }

            $name = trim((string) ($request->input('customer_name')
                ?: trim($request->input('customer_first_name').' '.$request->input('customer_surname')))) ?: 'Unknown name';
            $phone = trim((string) $request->input('customer_phone')) ?: '—';
            $email = trim((string) $request->input('customer_email')) ?: '—';
            $from = trim((string) $request->input('pickup_address')) ?: '?';
            $to = trim((string) $request->input('destination_address')) ?: '?';
            $when = trim((string) $request->input('pickup_at')) ?: '?';
            $return = trim((string) $request->input('return_pickup_at'));
            $errors = collect($e->errors())->flatten()->unique()->values();

            $title = 'Website booking REFUSED: '.$name;
            $body = $name.' ('.$phone.') couldn’t book '.$from.' → '.$to.' on '.str_replace('T', ' ', $when)
                .'. Reason: '.$errors->first().' Ring them to help.';

            app(AdminAlerts::class)->notifyDirectors('web_rejected', $title, $body, 'critical');

            $ops = trim((string) config('cet.ops_email'));
            if ($ops !== '') {
                $lines = [
                    'The website just refused a booking. The customer saw a red box with the reason below.',
                    'Please contact them so we don\'t lose the booking.',
                    '',
                    'Form: '.$form,
                    'Customer: '.$name,
                    'Phone: '.$phone,
                    'Email: '.$email,
                    'Journey: '.($request->input('journey_type') ?: 'one_way'),
                    'Pickup: '.$from.' '.trim((string) $request->input('pickup_postcode')),
                    'Drop-off: '.$to.' '.trim((string) $request->input('destination_postcode')),
                    'Pickup time: '.str_replace('T', ' ', $when),
                    'Return time: '.($return !== '' ? str_replace('T', ' ', $return) : '—'),
                    'Flight: '.(trim((string) $request->input('flight_number')) ?: '—').' landing '.(str_replace('T', ' ', (string) $request->input('flight_landing_at')) ?: '—'),
                    'Passengers: '.($request->input('passengers') ?: '—'),
                    'Payment: '.($request->input('payment_method') ?: '—'),
                    '',
                    'Why it was refused:',
                ];
                foreach ($errors as $msg) {
                    $lines[] = ' - '.$msg;
                }
                Mail::raw(implode("\n", $lines), function ($m) use ($ops, $name, $email) {
                    $m->to($ops)->subject('Website booking refused — '.$name.' — please contact them');
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $m->replyTo($email, $name);
                    }
                });
            }
        } catch (\Throwable $t) {
            Log::warning('Rejected-booking alert failed: '.$t->getMessage());
        }
    }
}
