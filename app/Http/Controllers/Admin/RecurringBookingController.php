<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\RecurringBooking;
use App\Models\VehicleType;
use App\Services\Bookings\RecurringBookingGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Standing / recurring bookings — templates that auto-create a booking for each
 * upcoming occurrence (a weekly airport run, a daily school run, etc.).
 */
class RecurringBookingController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.recurring.index', [
            'templates' => RecurringBooking::with(['customer', 'vehicleType'])->orderByDesc('is_active')->latest()->get(),
            'vehicleTypes' => VehicleType::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $this->validated($request);

        $customer = Customer::firstOrCreate(
            ['phone' => $data['customer_phone']],
            ['name' => $data['customer_name']],
        );
        if ($customer->name !== $data['customer_name'] && filled($data['customer_name'])) {
            $customer->forceFill(['name' => $data['customer_name']])->save();
        }

        RecurringBooking::create($this->templateData($data) + [
            'customer_id' => $customer->id,
            'created_by' => $request->user()->id,
            'is_active' => true,
        ]);

        return back()->with('status', 'Standing booking created — the next runs will be generated automatically.');
    }

    public function update(Request $request, RecurringBooking $recurring): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $this->validated($request);
        $recurring->update($this->templateData($data) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', 'Standing booking updated.');
    }

    public function destroy(Request $request, RecurringBooking $recurring): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $recurring->delete();

        return back()->with('status', 'Standing booking removed. Bookings already generated are kept.');
    }

    /** Generate the upcoming occurrences now (don't wait for the nightly run). */
    public function generate(Request $request, RecurringBookingGenerator $generator): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $made = $generator->generateDue();

        return back()->with('status', "Generated {$made} upcoming booking(s) from your standing bookings.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'max:32'],
            'vehicle_type_id' => ['required', Rule::exists('vehicle_types', 'id')],
            'frequency' => ['required', Rule::in(['daily', 'weekdays', 'weekly'])],
            'weekday' => ['nullable', 'integer', 'min:0', 'max:6', 'required_if:frequency,weekly'],
            'pickup_time' => ['required', 'date_format:H:i'],
            'pickup_address' => ['required', 'string', 'max:500'],
            'pickup_postcode' => ['nullable', 'string', 'max:12'],
            'destination_address' => ['required', 'string', 'max:500'],
            'destination_postcode' => ['nullable', 'string', 'max:12'],
            'passengers' => ['required', 'integer', 'min:1', 'max:16'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'account'])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lead_days' => ['required', 'integer', 'min:0', 'max:30'],
        ]);
    }

    /** @return array<string, mixed> */
    private function templateData(array $data): array
    {
        return [
            'vehicle_type_id' => $data['vehicle_type_id'],
            'frequency' => $data['frequency'],
            'weekday' => $data['frequency'] === 'weekly' ? (int) $data['weekday'] : null,
            'pickup_time' => $data['pickup_time'],
            'pickup_address' => $data['pickup_address'],
            'pickup_postcode' => $data['pickup_postcode'] ?? null,
            'destination_address' => $data['destination_address'],
            'destination_postcode' => $data['destination_postcode'] ?? null,
            'passengers' => $data['passengers'],
            'payment_method' => $data['payment_method'],
            'notes' => $data['notes'] ?? null,
            'lead_days' => $data['lead_days'],
        ];
    }
}
