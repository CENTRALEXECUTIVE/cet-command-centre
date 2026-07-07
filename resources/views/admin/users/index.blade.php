@extends('layouts.app')
@section('title', 'Users')

@section('content')
    <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <div>
            <h1 class="page-title" style="margin-bottom:2px">Users</h1>
            <p class="page-sub">Logins for the office, drivers and corporate clients.</p>
        </div>
        <a href="{{ route('users.create') }}" class="btn btn-primary" style="padding:9px 16px">+ Add user</a>
    </div>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <div class="card">
        <div class="table-scroll">
            <table>
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @foreach($users as $u)
                        <tr style="{{ $u->is_active ? '' : 'opacity:.5' }}">
                            <td>{{ $u->name }}</td>
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
