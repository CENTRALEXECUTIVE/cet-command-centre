/* Phone number sense-check (mirrors App\Support\Phone::problem). 24 Nov 2026:
   a website booking arrived with a digit missing and couldn't be reached.
   window.cetPhoneProblem(value) -> message string, or '' when it looks fine.
   Fields with data-phone-check="block" (customer forms) stop the step;
   data-phone-check="warn" (admin) only shows the note and still saves. */
(function () {
  function uk(n) {
    var mobile = n.indexOf('07') === 0;
    if (n.length < (mobile ? 11 : 10)) {
      return mobile ? 'This mobile number looks too short. UK mobiles have 11 digits, for example 07700 900123. Please check it.'
                    : 'This phone number looks too short. UK numbers have 10 or 11 digits. Please check it.';
    }
    if (n.length > 11) return 'This phone number looks too long. UK numbers have 11 digits, for example 07700 900123. Please check it.';
    if (/^0(\d)\1{8,}$/.test(n)) return 'This phone number doesn’t look real. Please check it.';
    return '';
  }
  function problem(v) {
    var raw = String(v || '').trim();
    if (!raw) return '';
    if (/[a-z]/i.test(raw)) return 'Please enter numbers only in the phone number.';
    var d = raw.replace(/\D/g, '');
    var intl = raw.charAt(0) === '+' || d.indexOf('00') === 0;
    if (intl) {
      if (raw.charAt(0) !== '+') d = d.slice(2);
      if (d.indexOf('44') === 0) return uk('0' + d.slice(2).replace(/^0+/, ''));
      return (d.length < 8 || d.length > 15) ? 'This international number doesn’t look complete. Please check it, including the country code.' : '';
    }
    if (d.indexOf('44') === 0 && d.length >= 11) return uk('0' + d.slice(2).replace(/^0+/, ''));
    if (d.charAt(0) !== '0') {
      return (d.length === 10 && d.charAt(0) === '7') ? '' :
        'Please check your phone number. UK numbers start with 0, for example 07700 900123. For a number abroad, start with + and the country code.';
    }
    return uk(d);
  }
  window.cetPhoneProblem = problem;

  function note(input) {
    var el = input.parentNode.querySelector('.cet-phone-note');
    if (!el) {
      el = document.createElement('div');
      el.className = 'cet-phone-note';
      el.setAttribute('role', 'alert');
      el.style.cssText = 'font-size:13px;margin-top:6px;line-height:1.4;color:#b3261e';
      input.insertAdjacentElement('afterend', el);
    }
    return el;
  }
  function check(input) {
    // Admin numbers typed for another country (country picker) aren't UK-checked.
    var cid = input.getAttribute('data-phone-country');
    var csel = cid ? document.getElementById(cid) : null;
    var msg = (csel && csel.value && csel.value !== 'GB' && String(input.value).trim().charAt(0) !== '+') ? '' : problem(input.value);
    var mode = input.getAttribute('data-phone-check');
    var el = note(input);
    el.textContent = msg ? (mode === 'warn' ? '⚠ ' + msg + ' (You can still save.)' : msg) : '';
    el.style.color = mode === 'warn' ? '#8a5a00' : '#b3261e';
    el.hidden = !msg;
    return msg;
  }
  window.cetPhoneCheck = check;

  // Name boxes (data-name-required="message"): our own red note instead of the
  // browser's grey bubble, and the box turns red; clears as soon as they type.
  document.addEventListener('invalid', function (e) {
    var t = e.target;
    if (!t || !t.getAttribute || !t.getAttribute('data-name-required')) return;
    e.preventDefault();
    var f = t.closest('.cet-field'); if (f) f.classList.add('bad');
    var el = note(t); el.textContent = t.getAttribute('data-name-required'); el.style.color = '#b3261e'; el.hidden = false;
    if (!document.querySelector('.cet-field.bad input:focus')) t.focus();
  }, true);
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (t && t.getAttribute && t.getAttribute('data-name-required') && String(t.value).trim()) {
      var f = t.closest('.cet-field'); if (f) f.classList.remove('bad');
      var el = t.parentNode.querySelector('.cet-phone-note'); if (el) el.hidden = true;
    }
  });
  document.addEventListener('blur', function (e) {
    if (e.target && e.target.matches && e.target.matches('[data-phone-check]')) check(e.target);
  }, true);
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (t && t.matches && t.matches('[data-phone-check]')) {
      var el = t.parentNode.querySelector('.cet-phone-note');
      if (!problem(t.value)) {
        if (el) el.hidden = true;
        var f = t.closest && t.closest('.cet-field'); if (f) f.classList.remove('bad');
      }
    }
  });
  // Customer forms (non-AJAX): stop the submit while a blocking number is wrong.
  document.addEventListener('submit', function (e) {
    var bad = Array.prototype.filter.call(e.target.querySelectorAll('[data-phone-check="block"]'), function (i) { return check(i); });
    if (bad.length) { e.preventDefault(); bad[0].focus(); }
  }, true);
})();
