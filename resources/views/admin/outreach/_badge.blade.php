@php
    $c = [
        'new' => ['#e0ecff', '#1d4ed8'], 'emailed' => ['#fff4d6', '#8a6d00'],
        'follow_up_1' => ['#fde9c8', '#b25e00'], 'follow_up_2' => ['#fde9c8', '#b25e00'],
        'replied' => ['#e7e0ff', '#5b2bc7'], 'meeting' => ['#e7e0ff', '#5b2bc7'], 'proposal' => ['#e7e0ff', '#5b2bc7'],
        'account_opened' => ['#d9f3e2', '#1f7a44'], 'not_interested' => ['#eee', '#555'], 'do_not_contact' => ['#fbe0e0', '#b32020'],
    ][$status] ?? ['#eee', '#555'];
@endphp
<span class="badge" style="background:{{ $c[0] }};color:{{ $c[1] }};white-space:nowrap">{{ \App\Models\Prospect::STATUSES[$status] ?? $status }}</span>
