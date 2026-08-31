#!/usr/bin/env python3
"""
SSMS mojibake repair tool.
Fixes UTF-8 -> cp1252 -> UTF-8 double-encoding corruption in:
  - admin/dashboards/edu_dept.php
  - admin/dashboards/info-dept.php
  - admin/reports.php

Strategy: within each line, find runs of "mojibake candidate" characters
(cp1252 upper-range punctuation + C1 controls + accented Latin), re-encode
each run to bytes using cp1252 with identity mapping for the 5 undefined
slots (0x81 0x8D 0x8F 0x90 0x9D), and decode as UTF-8. Runs that fail to
decode are left untouched (they're probably genuine punctuation).

Usage:
  python3 tools/fix_mojibake.py            # dry run
  python3 tools/fix_mojibake.py --apply    # write in place
  python3 tools/fix_mojibake.py --apply --backup-dir=/tmp/ssms-bak
"""
import argparse
import os
import re
import shutil

FILES = [
    "admin/dashboards/edu_dept.php",
    "admin/dashboards/info-dept.php",
    "admin/reports.php",
]

# chars that appear when UTF-8 bytes are mis-read as cp1252
CP1252_UPPER = (
    "\u20ac\u201a\u0192\u201e\u2026\u2020\u2021\u02c6\u2030\u0160\u2039\u0152"
    "\u017d\u2018\u2019\u201c\u201d\u2022\u2013\u2014\u02dc\u2122\u0161"
    "\u203a\u0153\u017e\u0178"
)
CANDIDATES = re.compile(
    "[\u0080-\u00ff" + CP1252_UPPER.replace("]", "") + " ]+"
)

# cp1252 holes: bytes that map to nothing, stored as C1 controls
HOLES = {0x81, 0x8D, 0x8F, 0x90, 0x9D}


def run_to_bytes(run: str):
    out = bytearray()
    for ch in run:
        cp = ord(ch)
        if cp in HOLES:
            out.append(cp)
            continue
        try:
            out += ch.encode("cp1252")
        except UnicodeEncodeError:
            return None
    return bytes(out)


def repair_run(run: str):
    stripped = run.strip(" ")
    lead = " " if run.startswith(" ") else ""
    trail = " " if run.endswith(" ") else ""
    b = run_to_bytes(stripped)
    if b is None or not b:
        return None
    try:
        dec = b.decode("utf-8")
    except UnicodeDecodeError:
        return None
    # A clean cp1252->UTF-8 round-trip on a punctuation/accent run is real
    # mojibake with near-certainty; single genuine chars (—, …, ’) fail to
    # decode alone, so they are never touched.
    if dec and dec != stripped and "\ufffd" not in dec:
        return lead + dec + trail
    return None


def repair_line(line: str):
    out, changed, failed = [], False, []
    pos = 0
    for m in CANDIDATES.finditer(line):
        rep = repair_run(m.group(0))
        out.append(line[pos:m.start()])
        if rep is not None and rep != m.group(0):
            out.append(rep)
            changed = True
        else:
            if any(ord(c) > 0x7f for c in m.group(0)):
                failed.append(m.group(0))
            out.append(m.group(0))
        pos = m.end()
    out.append(line[pos:])
    return "".join(out), changed, failed


def process(path: str, apply: bool, backup_dir):
    with open(path, encoding="utf-8") as f:
        text = f.read()
    lines = text.split("\n")
    n_changed, leftovers = 0, []
    new_lines = []
    for i, ln in enumerate(lines, 1):
        rep, changed, failed = repair_line(ln)
        if changed:
            n_changed += 1
        for f_ in failed:
            leftovers.append((i, f_[:30]))
        new_lines.append(rep)
    verb = "WOULD FIX" if not apply else "FIXED"
    print(f"{verb}: {path}  ({n_changed} lines changed)")
    for i, frag in leftovers[:10]:
        print(f"   ?? line {i}: un-repaired fragment {frag!r} (likely genuine punctuation)")
    if apply and n_changed:
        if backup_dir:
            dst = os.path.join(backup_dir, path.lstrip("./"))
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            shutil.copy2(path, dst)
        with open(path, "w", encoding="utf-8") as f:
            f.write("\n".join(new_lines))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--backup-dir")
    args = ap.parse_args()
    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    os.chdir(root)
    for p in FILES:
        if os.path.exists(p):
            process(p, args.apply, args.backup_dir)
        else:
            print(f"SKIP (missing): {p}")
    if not args.apply:
        print("\nDry run only. Re-run with --apply to write changes.")


if __name__ == "__main__":
    main()
