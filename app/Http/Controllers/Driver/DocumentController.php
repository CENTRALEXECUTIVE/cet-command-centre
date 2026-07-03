<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Models\DriverDocument;
use App\Services\Compliance\DriverDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function __construct(private readonly DriverDocumentService $documents) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('driver.documents', [
            'vehicle' => $user->driverProfile?->defaultVehicle,
            'rows' => $this->documents->rowsFor($user),
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
}
