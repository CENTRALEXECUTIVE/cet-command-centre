<?php

namespace App\Services\Gdpr;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Consent;
use App\Models\Customer;
use App\Models\DataErasureRequest;
use App\Models\DriverLocation;
use App\Models\Message;
use App\Models\MissedCall;
use App\Models\User;
use App\Models\WaitingListEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * UK GDPR right-to-erasure. Removes a customer's personal data across the system
 * while retaining anonymised transactional records required for accounting/VAT.
 *
 * Personal data (name, contacts, addresses, message bodies, GPS) is deleted or
 * redacted; bookings are kept but stripped of PII and linked to the now-
 * anonymised customer. Model events are suppressed during the wipe and the
 * customer's existing audit-log rows are purged, so no PII survives in the
 * audit trail. A single clean audit entry records that erasure occurred.
 */
class DataErasureService
{
    /**
     * @return array<string, int> Summary of what was erased.
     */
    public function eraseCustomer(Customer $customer, ?User $processedBy = null, ?DataErasureRequest $request = null): array
    {
        return DB::transaction(function () use ($customer, $processedBy, $request) {
            $bookingIds = $customer->bookings()->pluck('id');
            $email = $customer->email;
            $phone = $customer->phone;

            $summary = [];

            // Suppress model events so redactions do not create new PII audit logs.
            Model::withoutEvents(function () use ($customer, $bookingIds, $email, $phone, &$summary) {
                $summary['addresses'] = $customer->addresses()->delete();

                $summary['messages'] = Message::where('customer_id', $customer->id)
                    ->orWhereIn('booking_id', $bookingIds)
                    ->update(['body' => '[erased]', 'to_address' => null]);

                $summary['missed_calls'] = MissedCall::where('customer_id', $customer->id)
                    ->when($phone, fn ($q) => $q->orWhere('from_number', $phone))
                    ->delete();

                $summary['waiting_list'] = WaitingListEntry::where('customer_id', $customer->id)->delete();

                $summary['gps_pings'] = DriverLocation::whereIn('booking_id', $bookingIds)->delete();

                $summary['consents'] = Consent::where(fn ($q) => $q
                    ->where(fn ($w) => $w->where('subject_type', 'customer')->where('subject_id', $customer->id))
                    ->when($email, fn ($w) => $w->orWhere('email', $email)))
                    ->update(['granted' => false, 'withdrawn_at' => now()]);

                // Keep bookings for accounting; strip their PII.
                $summary['bookings'] = Booking::whereIn('id', $bookingIds)->update([
                    'pickup_address' => '[erased]',
                    'destination_address' => '[erased]',
                    'pickup_postcode' => null,
                    'destination_postcode' => null,
                    'flight_number' => null,
                    'special_requests' => null,
                ]);

                // Anonymise then soft-delete the customer record itself.
                $customer->forceFill([
                    'name' => 'Erased customer #'.$customer->id,
                    'phone' => null,
                    'email' => null,
                    'preferred_pickup_address' => null,
                    'preferred_vehicle_type_id' => null,
                    'marketing_consent' => false,
                    'marketing_consent_at' => null,
                    'notes' => null,
                ])->save();
                $customer->delete(); // soft delete
            });

            // Purge existing audit-log rows that may contain this customer's PII.
            $summary['audit_logs'] = AuditLog::where(fn ($q) => $q
                ->where(fn ($w) => $w->where('auditable_type', Customer::class)->where('auditable_id', $customer->id))
                ->orWhere(fn ($w) => $w->where('auditable_type', Booking::class)->whereIn('auditable_id', $bookingIds)))
                ->delete();

            // One clean, PII-free record that erasure happened.
            AuditLog::create([
                'user_id' => $processedBy?->id,
                'action' => 'erased',
                'auditable_type' => Customer::class,
                'auditable_id' => $customer->id,
                'new_values' => ['summary' => $summary],
                'created_at' => now(),
            ]);

            $request?->update([
                'status' => 'completed',
                'processed_by' => $processedBy?->id,
                'processed_at' => now(),
                'summary' => $summary,
            ]);

            return $summary;
        });
    }
}
