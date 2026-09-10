<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VehicleType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Manage the marketing photo shown for each vehicle on the public booking page.
 * Photos live at public/images/fleet/{slug}.{ext}; VehicleType::photoUrl() reads
 * them (falling back to a silhouette). Uploads land in the CET app's own public
 * dir — never the live website — and survive deploys (git reset doesn't touch
 * untracked files).
 */
class VehiclePhotoController extends Controller
{
    /** Extensions photoUrl() understands, best first. */
    private const EXTS = ['webp', 'png', 'jpg', 'jpeg'];

    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.fleet-photos.index', [
            'vehicleTypes' => VehicleType::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, VehicleType $vehicleType): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'], // 6 MB
        ]);

        $dir = public_path('images/fleet');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // One photo per vehicle: clear any existing file (any extension) first so
        // the new one wins and photoUrl() never finds a stale duplicate.
        $this->deleteFiles($vehicleType);

        $ext = strtolower($request->file('photo')->getClientOriginalExtension());
        $ext = in_array($ext, self::EXTS, true) ? $ext : 'jpg';
        $request->file('photo')->move($dir, $vehicleType->slug.'.'.$ext);

        return back()->with('status', $vehicleType->name.' photo updated — it’s live on the booking page.');
    }

    public function destroy(Request $request, VehicleType $vehicleType): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $this->deleteFiles($vehicleType);

        return back()->with('status', $vehicleType->name.' photo removed — showing the silhouette again.');
    }

    /** Remove every fleet image file for this vehicle's slug, whatever the extension. */
    private function deleteFiles(VehicleType $vehicleType): void
    {
        foreach (self::EXTS as $ext) {
            $path = public_path('images/fleet/'.$vehicleType->slug.'.'.$ext);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
