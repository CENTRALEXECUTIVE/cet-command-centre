<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Mail\CustomerPasswordResetMail;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Self-service "forgot password" for the customer My Account widget. Deliberately
 * isolated from staff auth: it only ever touches the customers table, and it never
 * reveals whether an email is registered (a neutral message either way). The link
 * carries a hashed, one-hour token; using it lets the customer choose a new password.
 *
 * This is transactional and strictly user-initiated (the customer asked to reset
 * their OWN password) — it is not the "nothing auto-sends to customers" booking
 * comms, and it fails soft if mail isn't configured.
 */
class CustomerPasswordController extends Controller
{
    private function csp(): string
    {
        return CustomerAccountController::frameAncestorsPolicy();
    }

    public function forgot(): \Illuminate\Http\Response
    {
        abort_unless(Customer::passwordLoginAvailable(), 404);

        return response()->view('widget.password-forgot', ['sent' => false])
            ->header('Content-Security-Policy', $this->csp());
    }

    public function sendReset(Request $request): \Illuminate\Http\Response
    {
        abort_unless(Customer::passwordLoginAvailable(), 404);

        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:160']]);
        $email = Str::lower(trim($data['email']));
        $customer = Customer::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($customer) {
            $raw = $customer->startPasswordReset(60);
            if ($raw) {
                $link = route('widget.account.reset.show', ['token' => $raw, 'email' => $customer->email]);
                try {
                    Mail::to($customer->email)->send(new CustomerPasswordResetMail($customer, $link));
                } catch (\Throwable $e) {
                    Log::warning('[widget] customer password reset email failed: '.$e->getMessage());
                }
            }
        }

        // Neutral response regardless — no account enumeration.
        return response()->view('widget.password-forgot', ['sent' => true])
            ->header('Content-Security-Policy', $this->csp());
    }

    public function showReset(Request $request): \Illuminate\Http\Response
    {
        abort_unless(Customer::passwordLoginAvailable(), 404);

        $email = Str::lower(trim((string) $request->query('email')));
        $token = (string) $request->query('token');
        $customer = $email !== '' ? Customer::whereRaw('LOWER(email) = ?', [$email])->first() : null;
        $valid = $customer && $token !== '' && $customer->resetTokenValid($token);

        return response()->view('widget.password-reset', [
            'valid' => $valid,
            'email' => $request->query('email'),
            'token' => $token,
        ])->header('Content-Security-Policy', $this->csp());
    }

    public function reset(Request $request): \Illuminate\Http\Response
    {
        abort_unless(Customer::passwordLoginAvailable(), 404);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:160'],
            'token' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        $email = Str::lower(trim($data['email']));
        $customer = Customer::whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $customer || ! $customer->resetTokenValid($data['token'])) {
            return response()->view('widget.password-reset', [
                'valid' => false,
                'email' => $data['email'],
                'token' => $data['token'],
                'error' => 'That reset link is invalid or has expired. Please request a new one.',
            ])->header('Content-Security-Policy', $this->csp());
        }

        $customer->setLoginPassword($data['password']);

        // Log them straight in and send them to their bookings.
        $request->session()->put(CustomerAccountController::SESSION_KEY, $customer->id);

        return response()->view('widget.password-reset', ['valid' => true, 'done' => true])
            ->header('Content-Security-Policy', $this->csp());
    }
}
