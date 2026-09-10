@extends('layouts.app')
@section('title', 'Vehicle photos')

@section('content')
    <h1 class="page-title">Vehicle photos</h1>
    <p class="page-sub">The photo shown for each vehicle on the public booking page. Upload a clear side-on shot of the car; if none is set, a clean silhouette is shown instead.</p>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
    @endif

    <div class="card">
        <div class="table-scroll">
            <table class="table-modern">
                <thead><tr><th>Photo</th><th>Vehicle</th><th>Upload / replace</th><th></th></tr></thead>
                <tbody>
                    @foreach($vehicleTypes as $type)
                        <tr>
                            <td style="width:140px">
                                @php $photo = $type->photoUrl(); @endphp
                                @if($photo)
                                    <img src="{{ $photo }}" alt="{{ $type->name }}" style="width:120px;height:74px;object-fit:cover;border-radius:8px;background:#f2f2f0">
                                @else
                                    <div style="width:120px;height:74px;border-radius:8px;background:#f2f2f0;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:12px">No photo</div>
                                @endif
                            </td>
                            <td>
                                <strong>{{ $type->name }}</strong>
                                <div class="muted" style="font-size:12px">up to {{ $type->passenger_capacity }} passengers</div>
                            </td>
                            <td>
                                <form method="POST" action="{{ route('fleet-photos.store', $type) }}" enctype="multipart/form-data"
                                      style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                                    @csrf
                                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required
                                           style="font-size:13px;max-width:200px">
                                    <button class="btn btn-primary" style="padding:7px 14px;font-size:13px">Save photo</button>
                                </form>
                                <div class="hint" style="font-size:12px;margin-top:4px">JPG, PNG or WebP · up to 6 MB · side-on shot works best.</div>
                            </td>
                            <td class="right">
                                @if($photo)
                                    <form method="POST" action="{{ route('fleet-photos.destroy', $type) }}"
                                          onsubmit="return confirm('Remove the {{ $type->name }} photo? The booking page will show the silhouette again.')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-ghost" style="padding:6px 12px;font-size:12px">Remove</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
