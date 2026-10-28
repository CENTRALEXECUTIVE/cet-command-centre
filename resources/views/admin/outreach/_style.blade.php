<style>
    .ox-due { border-left: 4px solid #FBBA2A; }
    .ox-due-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;
        padding: 10px 0; border-top: 1px solid rgba(127,127,127,.18); color: inherit; text-decoration: none; }
    .ox-due-row:first-of-type { border-top: 0; }
    .ox-due-main { display: flex; flex-direction: column; min-width: 0; }
    .ox-due-side { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-size: 13px; }
    .ox-when { font-weight: 600; }
    .ox-when.late { color: #b32020; }
    .ox-action { font-weight: 600; color: var(--accent, #FBBA2A); }
    .ox-filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .ox-filters input[type=search] { flex: 1 1 220px; min-width: 0; }
    .ox-filters select { flex: 0 1 auto; }
    .ox-import { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .ox-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; }
    .ox-actions .btn, .ox-actions form { margin: 0; }
    .ox-actions .btn { min-height: 44px; display: inline-flex; align-items: center; } /* tablet/phone tap size */
    .ox-email { white-space: pre-wrap; font-family: inherit; font-size: 14px; line-height: 1.5; margin: 0;
        padding: 12px; border-radius: 10px; background: rgba(127,127,127,.08); max-height: 420px; overflow: auto; word-break: break-word; }
    .ox-subject { font-weight: 600; margin: 0 0 6px; word-break: break-word; }
    .ox-meta dt { font-size: 12px; color: var(--muted, #888); margin-top: 8px; }
    .ox-meta dd { margin: 0; word-break: break-word; }
    .ox-log { list-style: none; padding: 0; margin: 0; }
    .ox-log li { padding: 8px 0; border-top: 1px solid rgba(127,127,127,.18); font-size: 13px; }
    .ox-log li:first-child { border-top: 0; }
    .ox-dnc { border-left: 4px solid #b32020; background: rgba(179,32,32,.08); }
    .ox-form textarea { width: 100%; min-height: 90px; }
    .ox-form .field input, .ox-form .field select, .ox-form .field textarea { width: 100%; box-sizing: border-box; }
    @media (max-width: 640px) {
        .ox-actions .btn { flex: 1 1 calc(50% - 8px); justify-content: center; }
        .ox-due-side { width: 100%; }
    }
</style>
