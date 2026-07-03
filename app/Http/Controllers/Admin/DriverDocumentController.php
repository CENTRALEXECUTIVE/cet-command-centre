<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriverDocument;
use App\Models\User;
use App\Services\Compliance\DriverDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Admin management of driver documents: see every driver's compliance at a
 * glance, open a driver to view/download their files, approve or reject each
 * upload, and upload a document ON BEHALF of a driver (when they send it in by
 * email/WhatsApp). Approval confirms the expiry back onto the driver/vehicle
 * record so the renewal reminders keep working.
 */
class DriverDocumentController extends Controller
{
    public function __construct(private readonly DriverDocumentService $documents) {}

    public function index(): View
    {
        $drivers = User::query()
            ->whereHas('driverProfile')
            ->with('driverProfile.defaultVehicle', 'driverDocuments')
            ->orderBy('name')
            ->get()
            ->map(fn (User $d) => ['driver' => $d, 'summary' => $this->documents->summaryFor($d)]);

        return view('admin.documents.index', ['drivers' => $drivers]);
    }

    public function show(User $user): View
    {
        return view('admin.documents.show', [
            'driver' => $user,
            'vehicle' => $user->driverProfile?->defaultVehicle,
            'rows' => $this->documents->rowsFor($user),
        ]);
    }

    /** Admin uploads a document on the driver's behalf — auto-approved. */
    public function store(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(DriverDocument::TYPES))],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,heic', 'max:8192'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        $file = $request->file('file');
        $path = $file->storeAs(
            'driver-documents/'.$user->id,
            $validated['type'].'-'.now()->format('YmdHis').'.'.$file->getClientOriginalExtension(),
            'local',
        );

        $existing = $user->driverDocuments()->where('type', $validated['type'])->first();
        if ($existing?->file_path && $existing->file_path !== $path) {
            Storage::disk('local')->delete($existing->file_path);
        }

        $document = $user->driverDocuments()->updateOrCreate(
            ['type' => $validated['type']],
            [
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'expiry_date' => $validated['expiry_date'] ?? null,
                'status' => 'approved', // admin uploaded = verified
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => 'Uploaded by admin',
            ],
        );
        $this->documents->syncExpiryToRecords($document);

        return back()->with('status', DriverDocument::TYPES[$validated['type']]['label'].' uploaded and approved.');
    }

    /** Approve or reject a driver-uploaded document. */
    public function review(Request $request, DriverDocument $document): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $approved = $validated['decision'] === 'approve';
        $document->forceFill([
            'status' => $approved ? 'approved' : 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => $validated['notes'] ?? null,
        ])->save();

        if ($approved) {
            $this->documents->syncExpiryToRecords($document);
        }

        return back()->with('status', $document->label().' '.($approved ? 'approved' : 'rejected').'.');
    }
}
