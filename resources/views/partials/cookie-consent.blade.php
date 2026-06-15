<div id="cookie-banner" style="display:none;position:fixed;left:0;right:0;bottom:0;z-index:9999;
     background:#0b0b0b;color:#fff;padding:14px 20px;font-size:13px;box-shadow:0 -2px 12px rgba(0,0,0,.3)">
  <div style="max-width:1080px;margin:0 auto;display:flex;gap:16px;align-items:center;flex-wrap:wrap;justify-content:space-between">
    <span style="flex:1;min-width:240px">
      We use essential cookies to run this service and, with your consent, analytics to improve it.
      See our <a href="#" style="color:#FBBA2A">Privacy Notice</a>. Your data is processed under UK GDPR.
    </span>
    <span style="display:flex;gap:8px">
      <button type="button" id="cookie-reject" style="padding:8px 16px;border:1px solid #555;background:transparent;color:#fff;border-radius:6px;cursor:pointer">Essential only</button>
      <button type="button" id="cookie-accept" style="padding:8px 16px;border:none;background:#FBBA2A;color:#0b0b0b;font-weight:600;border-radius:6px;cursor:pointer">Accept all</button>
    </span>
  </div>
</div>
@verbatim
<script>
(function () {
  var KEY = 'cet_cookie_consent';
  if (document.cookie.indexOf(KEY + '=') !== -1) return;
  var banner = document.getElementById('cookie-banner');
  banner.style.display = 'block';
  function choose(granted) {
    document.cookie = KEY + '=' + (granted ? '1' : '0') + ';path=/;max-age=' + (60 * 60 * 24 * 365);
    banner.style.display = 'none';
    fetch('/consent/cookies', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ granted: granted ? 1 : 0 })
    }).catch(function () {});
  }
  document.getElementById('cookie-accept').addEventListener('click', function () { choose(true); });
  document.getElementById('cookie-reject').addEventListener('click', function () { choose(false); });
})();
</script>
@endverbatim
