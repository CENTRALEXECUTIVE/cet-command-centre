<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ETO-style "Additional files": attach documents to a booking (flight docs,
 * meet-and-greet notes, receipts…). Files hold customer PII, so they live on the
 * PRIVATE local disk and are only ever served back through this admin controller —
 * never a public URL. The file list is kept in meta['files'] (no migration).
 */
class BookingFileController extends Controller
{
    private const DISK = 'local';
    private const MAX_KB = 10240; // 10 MB

    public function store(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_KB,
                'mimes:pdf,jpg,jpeg,png,heic,webp,gif,doc,docx,xls,xlsx,csv,txt,eml'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $file = $data['file'];
        $id = (string) Str::uuid();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'dat');
        $path = 'booking-files/'.$booking->id.'/'.$id.'.'.$ext;
        Storage::disk(self::DISK)->putFileAs('booking-files/'.$booking->id, $file, $id.'.'.$ext);

        $meta = $booking->meta ?? [];
        $files = $meta['files'] ?? [];
        $files[] = [
            'id' => $id,
            'name' => $file->getClientOriginalName(),
            'label' => trim((string) ($data['label'] ?? '')) ?: null,
            'path' => $path,
            'size' => $file->getSize(),
            'mime' => $file->getClientMimeType(),
            'at' => now()->toIso8601String(),
            'by' => $request->user()->name,
        ];
        $meta['files'] = $files;
        $booking->forceFill(['meta' => $meta])->save();

        return back()->with('status', 'File added — '.$file->getClientOriginalName())->with('scroll', 'files');
    }

    public function download(Request $request, Booking $booking, string $file): StreamedResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $entry = collect($booking->meta['files'] ?? [])->firstWhere('id', $file);
        abort_unless($entry && Storage::disk(self::DISK)->exists($entry['path']), 404);

        return Storage::disk(self::DISK)->download($entry['path'], $entry['name'] ?? 'file');
    }

    public function destroy(Request $request, Booking $booking, string $file): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $files = $booking->meta['files'] ?? [];
        $entry = collect($files)->firstWhere('id', $file);
        abort_unless($entry, 404);

        if (! empty($entry['path'])) {
            Storage::disk(self::DISK)->delete($entry['path']);
        }
        $meta = $booking->meta ?? [];
        $meta['files'] = array_values(array_filter($files, fn ($f) => ($f['id'] ?? null) !== $file));
        $booking->forceFill(['meta' => $meta])->save();

        return back()->with('status', 'File removed.')->with('scroll', 'files');
    }
}
