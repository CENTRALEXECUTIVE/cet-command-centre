<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Models\DriverDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The driver's "Vehicle & Documents" page — each required document shown as a
 * days-left card, with upload. Backed by DriverDocument for files, and it falls
 * back to the driver/vehicle expiry dates already on file so the cards are
 * populated from day one.
 */
class DocumentController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $profile = $user->driverProfile;
        $vehicle = $profile?->defaultVehicle;
        $documents = $user->driverDocuments()->get()->keyBy('type');

        $rows = [];
        foreach (DriverDocument::TYPES as $type => $meta) {
            $doc = $documents->get($type);
            $expiry = $doc?->expiry_date ?? $this->existingExpiry($type, $profile, $vehicle);
            $rows[] = $this->row($type, $meta, $doc, $expiry);
        }

        return view('driver.documents', [
            'vehicle' => $vehicle,
            'rows' => $rows,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(DriverDocument::TYPES))],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,heic', 'max:8192'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $file = $request->file('file');
        $path = $file->storeAs(
            'driver-documents/'.$user->id,
            $validated['type'].'-'.now()->format('YmdHis').'.'.$file->getClientOriginalExtension(),
            'local',
        );

        // Replace any previous file for this type.
        $existing = $user->driverDocuments()->where('type', $validated['type'])->first();
        if ($existing?->file_path && $existing->file_path !== $path) {
            Storage::disk('local')->delete($existing->file_path);
        }

        $user->driverDocuments()->updateOrCreate(
            ['type' => $validated['type']],
            [
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'expiry_date' => $validated['expiry_date'] ?? null,
                'status' => 'pending', // awaits admin verification
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_notes' => null,
            ],
        );

        return back()->with('status', DriverDocument::TYPES[$validated['type']]['label'].' uploaded — pending review.');
    }

    /** Stream a document file to its owner (or an admin). */
    public function download(Request $request, DriverDocument $document): StreamedResponse
    {
        abort_unless($request->user()->isAdmin() || $document->user_id === $request->user()->id, 403);
        abort_unless($document->file_path && Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->file_name);
    }

    /** Existing expiry already on the driver/vehicle record (pre-upload display). */
    private function existingExpiry(string $type, $profile, $vehicle): ?Carbon
    {
        return match ($type) {
            'plate' => $vehicle?->phv_licence_expiry,
            'mot' => $vehicle?->mot_expiry,
            'insurance' => $vehicle?->insurance_expiry,
            'badge' => $profile?->phv_badge_expiry,
            'driving_licence' => $profile?->driving_licence_expiry,
            'dbs' => $profile?->dbs_expiry,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $type, array $meta, ?DriverDocument $doc, ?Carbon $expiry): array
    {
        $days = $expiry ? (int) Carbon::today()->diffInDays($expiry, false) : null;

        $status = match (true) {
            $doc?->status === 'rejected' => 'rejected',
            $doc && $doc->status === 'pending' => 'pending',
            $days === null => $doc ? 'valid' : 'missing',
            $days < 0 => 'expired',
            $days <= DriverDocument::EXPIRING_SOON_DAYS => 'expiring',
            default => 'valid',
        };

        return [
            'type' => $type,
            'label' => $meta['label'],
            'subject' => $meta['subject'],
            'expiry' => $expiry,
            'days' => $days,
            'status' => $status,
            'hasFile' => (bool) $doc?->file_path,
            'document' => $doc?->file_path ? $doc : null,
        ];
    }
}
