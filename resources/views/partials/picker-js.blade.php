{{-- Tapping ANYWHERE on a date / time box opens the native picker — not just the
     tiny calendar icon. Covers date, datetime-local, month, week and time inputs,
     including ones added to the page after load. Shared by the app layout and the
     standalone booking/widget pages so it behaves the same everywhere. --}}
<script>
    (function () {
        var SEL = 'input[type="date"],input[type="datetime-local"],input[type="month"],input[type="week"],input[type="time"]';
        function open(el) {
            if (!el || el.disabled || el.readOnly) return;
            try { if (typeof el.showPicker === 'function') el.showPicker(); } catch (e) { /* needs a gesture / unsupported — ignore */ }
        }
        // Pointer down (not click) so the picker opens on the first tap. Left button only.
        document.addEventListener('pointerdown', function (e) {
            if (e.button && e.button !== 0) return;
            var el = e.target && e.target.closest ? e.target.closest(SEL) : null;
            if (el) open(el);
        });
        // Keyboard focus (tab to it) opens it too.
        document.addEventListener('focusin', function (e) {
            var el = e.target;
            if (el && el.matches && el.matches(SEL)) open(el);
        });
    })();
</script>
