@extends('layouts.app')
@section('title', 'Users')

@section('content')
    <div class="list-head" style="align-items:center">
        <div class="form-hero" style="flex:1;margin-bottom:0">
            <div class="form-hero-glow"></div>
            <div class="fh-eyebrow">Fleet &amp; admin · access</div>
            <div class="fh-title">Users</div>
            <div class="fh-sub">Logins for the office, drivers and corporate clients.</div>
        </div>
        <a href="{{ route('users.create') }}" class="btn btn-primary" style="padding:10px 18px">+ Add user</a>
    </div>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    @if(session('new_credentials'))
        @php $cred = session('new_credentials'); @endphp
        <div class="card" id="cred-card" style="border:1px solid var(--gold);background:#fffdf5">
            <h2 style="margin:0 0 4px">🔑 Login for {{ $cred['name'] }}</h2>
            <p class="hint" style="margin:0 0 12px">Share this with them, then it disappears when you leave the page. They’ll be asked to set their own password on first sign-in.</p>
            <div class="grid grid-2" style="margin-bottom:12px">
                <div class="field" style="margin:0"><label>Email (login)</label><input id="cred-email" type="text" value="{{ $cred['email'] }}" readonly onclick="this.select()"></div>
                <div class="field" style="margin:0"><label>Password</label><input id="cred-pass" type="text" value="{{ $cred['password'] }}" readonly onclick="this.select()"></div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <button type="button" class="btn btn-primary" id="cred-copy" data-text="{{ $cred['share_text'] }}" style="padding:9px 16px;font-size:14px">Copy login</button>
                @if($cred['wa_link'])
                    <a class="btn btn-light" href="{{ $cred['wa_link'] }}" target="_blank" rel="noopener" style="padding:9px 16px;font-size:14px">Send on WhatsApp</a>
                @endif
                <span id="cred-copied" class="hint" style="color:#1f8b4c"></span>
            </div>
        </div>
        <script>
            (function () {
                var btn = document.getElementById('cred-copy');
                if (!btn) return;
                btn.addEventListener('click', function () {
                    var text = btn.dataset.text || '';
                    var done = function () { document.getElementById('cred-copied').textContent = '✓ Copied — paste it to them'; };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(done).catch(function () {
                            var f = document.getElementById('cred-pass'); f.select(); document.execCommand('copy'); done();
                        });
                    } else {
                        var f = document.getElementById('cred-pass'); f.select(); document.execCommand('copy'); done();
                    }
                });
            })();
        </script>
    @endif

    <div class="card">
        <div class="table-scroll">
            <table class="table-modern">
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @foreach($users as $u)
                        <tr style="{{ $u->is_active ? '' : 'opacity:.5' }}">
                            <td>{{ $u->name }}@if($u->nickname())<span class="muted" style="font-size:12px"> · {{ $u->nickname() }}</span>@endif</td>
                            <td class="muted" style="font-size:13px">{{ $u->email }}</td>
                            <td>
                                @if($u->is_super_admin)<span class="badge" style="background:#0b0b0b;color:#FBBA2A">Super admin</span>
                                @else<span class="badge">{{ $u->role->label() }}</span>@endif
                            </td>
                            <td>{{ $u->is_active ? 'Active' : 'Inactive' }}</td>
                            <td class="right">
                                @if(! (($u->isAdmin() || $u->is_super_admin) && ! $isSuper))
                                    <a href="{{ route('users.edit', $u) }}" style="font-size:13px">Edit</a>
                                @else
                                    <span class="muted" style="font-size:12px">admin</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top:16px">{{ $users->links() }}</div>
    </div>

    @unless($isSuper)
        <p class="hint">You can add drivers and corporate logins. Only a <strong>super admin</strong> can create or edit admin accounts.</p>
    @endunless
@endsection
