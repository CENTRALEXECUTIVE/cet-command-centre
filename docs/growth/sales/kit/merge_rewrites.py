"""Merge rewrite/<file>.csv (company,email_subject,email_body) into the original
prospect CSV, replacing only those two columns by company. Usage:
python3 merge_rewrites.py <original.csv> <rewrite.csv>"""
import csv
import sys

orig_path, rw_path = sys.argv[1], sys.argv[2]
with open(orig_path, encoding="utf-8-sig", newline="") as f:
    reader = csv.DictReader(f)
    fields, rows = reader.fieldnames, list(reader)
new = {r["company"]: r for r in csv.DictReader(open(rw_path, encoding="utf-8-sig"))}
changed = 0
for r in rows:
    n = new.get(r["company"])
    if n and n["email_body"].strip():
        r["email_subject"], r["email_body"] = n["email_subject"].strip(), n["email_body"].strip()
        changed += 1
with open(orig_path, "w", encoding="utf-8", newline="") as f:
    w = csv.DictWriter(f, fieldnames=fields, quoting=csv.QUOTE_MINIMAL)
    w.writeheader()
    w.writerows(rows)
print(f"{orig_path}: {changed}/{len(rows)} emails replaced")
