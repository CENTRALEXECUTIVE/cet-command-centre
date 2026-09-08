<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $enquiry ? 'Request received' : 'Booking confirmed' }} · Central Executive Transfers</title>
    <style>
        :root{--gold:#FBBA2A;--ink:#0b0b0b;--paper:#f6f5f2;--muted:#6b6b6b;--line:#e7e4dc}
        *{box-sizing:border-box}html,body{margin:0}
        body{font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:var(--ink);
            background:var(--paper);min-height:100vh;display:grid;place-items:center;padding:24px}
        .card{background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 10px 40px rgba(0,0,0,.08);
            max-width:520px;width:100%;padding:34px;text-align:center}
        .tick{width:64px;height:64px;border-radius:50%;background:{{ $enquiry ? '#fff7e6' : '#eaf6ef' }};
            display:grid;place-items:center;margin:0 auto 18px;font-size:32px}
        h1{font-size:26px;margin:0 0 8px;letter-spacing:-.01em}
        p{color:var(--muted);margin:0 0 8px;font-size:15.5px}
        .brand{margin-top:24px;padding-top:18px;border-top:1px solid var(--line);color:var(--muted);font-size:12.5px}
        .brand b{color:var(--ink)}
        .btn{display:inline-block;margin-top:18px;background:var(--gold);color:#111;font-weight:800;
            text-decoration:none;padding:13px 22px;border-radius:12px}
    </style>
</head>
<body>
    <div class="card">
        @if($enquiry)
            <div class="tick">✉️</div>
            <h1>Request received</h1>
            <p>Thanks — we’ve got your journey details. Our office will confirm the price and get back to you shortly to take payment and lock it in.</p>
        @else
            <div class="tick">✓</div>
            <h1>Booking confirmed</h1>
            <p>Thank you — your payment was successful and your chauffeur is booked.</p>
            <p>A confirmation and receipt are on their way to your email. We’ll be in touch with your driver’s details before pick-up.</p>
        @endif
        <a class="btn" href="{{ route('public.book') }}">Book another journey</a>
        <div class="brand">
            <b>Central Executive Transfers Ltd</b><br>
            Operator Licence OP037 · 07405 172435 · centralexecutivetransfers.co.uk
        </div>
    </div>
</body>
</html>
