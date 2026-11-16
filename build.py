#!/usr/bin/env python3
"""Generate appdata-incremental.plg for a pushed commit.

The .plg lists every file below src/ with its SHA256. On install, Unraid downloads the files from
raw.githubusercontent.com at exactly that commit, checks them and caches them on the flash drive.

Usage:
  python3 build.py --version 2026.11.16 --ref <commit-sha> [--owner OWNER] [--repo REPO]

The files below src/ must be identical to the files of that commit (push src first, then the .plg).
"""
import argparse, hashlib, os, re, sys
from xml.sax.saxutils import escape

NAME = "appdata-incremental"
RESTIC_VERSION = "0.19.1"
# SHA256 of restic_0.19.1_linux_amd64.bz2 from https://github.com/restic/restic/releases/download/v0.19.1/SHA256SUMS
RESTIC_SHA256 = "f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c"

ROOT = os.path.dirname(os.path.abspath(__file__))


def manifest():
    lines = []
    src = os.path.join(ROOT, "src")
    for base, _, files in os.walk(src):
        for f in files:
            full = os.path.join(base, f)
            rel = os.path.relpath(full, src).replace(os.sep, "/")
            if not re.fullmatch(r"[A-Za-z0-9._/-]+", rel):
                sys.exit("Unzulässiger Dateiname (nur A-Z a-z 0-9 . _ / -): " + rel)
            lines.append("%s  %s" % (hashlib.sha256(open(full, "rb").read()).hexdigest(), rel))
    return "\n".join(sorted(lines, key=lambda l: l.split("  ", 1)[1]))


def changes(version):
    path = os.path.join(ROOT, "CHANGELOG.md")
    text = open(path).read() if os.path.exists(path) else "###%s\n" % version
    if "###" + version not in text.replace("### ", "###"):
        sys.exit("CHANGELOG.md hat keinen Eintrag für " + version)
    return escape(text.strip())


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--version", required=True, help="e.g. 2026.11.16 (Unraid compares versions as strings)")
    ap.add_argument("--ref", required=True, help="full commit SHA that contains src/")
    ap.add_argument("--owner", default="gottschalkfelix4-source")
    ap.add_argument("--repo", default="unraid-appdata-incremental")
    a = ap.parse_args()
    if not re.fullmatch(r"[0-9a-f]{40}", a.ref):
        sys.exit("--ref muss ein vollständiger Commit-SHA (40 Hex-Zeichen) sein")
    tpl = open(os.path.join(ROOT, "plugin", NAME + ".plg.in")).read()
    out = (tpl.replace("@OWNER@", a.owner).replace("@REPO@", a.repo).replace("@VERSION@", a.version)
              .replace("@GIT_REF@", a.ref).replace("@RESTIC_VERSION@", RESTIC_VERSION)
              .replace("@RESTIC_SHA256@", RESTIC_SHA256).replace("@MANIFEST@", manifest())
              .replace("@CHANGES@", changes(a.version)))
    if re.search(r"@[A-Z_]+@", out):
        sys.exit("Platzhalter nicht ersetzt")
    dst = os.path.join(ROOT, NAME + ".plg")
    open(dst, "w").write(out)
    print("geschrieben:", dst)


if __name__ == "__main__":
    main()
