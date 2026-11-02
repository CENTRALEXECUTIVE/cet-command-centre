"""Check outreach emails against cold-email-rules-v2.md.

Usage: python3 lint_emails.py <rewrite.csv> [<original-batch.csv>]
Prints one line per problem; exit code 1 if any email fails.
"""
import csv
import re
import sys

BANNED = [
    "—", "–", "hope this finds", "hope this email", "my name is", "we are a leading",
    "experienced", "discreet", "dependable", "premium", "bespoke", "seamless", "hassle-free",
    "hassle free", "peace of mind", "tailored", "solution", "leverage", "elevate", "look no further",
    "don't hesitate", "reach out", "touch base", "just following up", "just checking in",
    "it's not just", "not just a", "world-class", "best-in-class", "unparalleled", "top-notch",
    "synergy", "circle back", "game-changer", "unlock", "here's the thing", "i'll be honest",
]
OPENS_ABOUT_US = re.compile(r"^(hello[^\\]*\\n\\n)?\s*(we run|we are|we're the|central executive transfers is|my name)", re.I)


def body_text(raw: str) -> tuple[str, str]:
    """Split body into (message, tail) — tail = signature + opt-out."""
    parts = raw.split("\\n\\nAbdi & Maj")
    return parts[0], ("Abdi & Maj" + parts[1]) if len(parts) > 1 else ""


def main() -> int:
    rows = list(csv.DictReader(open(sys.argv[1], encoding="utf-8-sig")))
    originals = {}
    if len(sys.argv) > 2:
        originals = {r["company"]: r for r in csv.DictReader(open(sys.argv[2], encoding="utf-8-sig"))}
    bad = 0
    seen = set()
    for r in rows:
        co, subj, body = r["company"], r["email_subject"], r["email_body"]
        msg, tail = body_text(body)
        words = len(re.sub(r"\\n", " ", msg).split())
        probs = []
        low = (subj + " " + body).lower()
        probs += [f"banned: {b!r}" for b in BANNED if b in low]
        if not tail:
            probs.append("signature missing")
        if "won't contact you again" not in body:
            probs.append("opt-out line missing")
        if not 35 <= words <= 100:
            probs.append(f"length {words} words")
        if subj != subj.lower() or len(subj.split()) > 5:
            probs.append(f"subject not short/lowercase: {subj!r}")
        if OPENS_ABOUT_US.search(msg):
            probs.append("opens about us")
        if msg.count("?") < 1:
            probs.append("no question")
        if msg.count(",") >= 4 and re.search(r"(, [a-z ]+){3,} and ", msg):
            probs.append("reads like a service list")
        if msg in seen:
            probs.append("duplicate body")
        seen.add(msg)
        if originals and co not in originals:
            probs.append("company not in original batch")
        if originals and co in originals:
            o_tail = body_text(originals[co]["email_body"])[1]
            if o_tail and tail.strip() != o_tail.strip():
                probs.append("signature/opt-out changed")
        if probs:
            bad += 1
            print(f"{co}: " + "; ".join(probs))
    if originals:
        missing = set(originals) - {r["company"] for r in rows}
        for m in sorted(missing):
            bad += 1
            print(f"{m}: missing from rewrite")
    print(f"-- {len(rows)} emails, {bad} with problems")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
