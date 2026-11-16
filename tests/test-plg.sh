#!/bin/bash
# Runs the install/remove scripts of the generated .plg in a temporary root against a local HTTP server.
# Usage: RESTIC_BZ2=/path/restic_X_linux_amd64.bz2 PHP=/path/php tests/test-plg.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(dirname "$HERE")"
PHP="${PHP:-php}"
T="$(mktemp -d /tmp/aib-plg.XXXXXX)"; trap 'kill $SRV 2>/dev/null; [ -n "${KEEP:-}" ] && echo "Testdaten: $T" || rm -rf "$T"' EXIT
PASS=0; FAIL=0
check(){ if eval "$2"; then PASS=$((PASS+1)); echo "  ok   $1"; else FAIL=$((FAIL+1)); echo "  FAIL $1"; fi; }
REF=0123456789abcdef0123456789abcdef01234567

# fake GitHub: /OWNER/REPO/<ref>/src/... and the restic release path
W="$T/www"; mkdir -p "$W/o/r/$REF" "$W/rel/v0.19.1"
cp -r "$ROOT/src" "$W/o/r/$REF/src"
cp "${RESTIC_BZ2:?RESTIC_BZ2 fehlt}" "$W/rel/v0.19.1/restic_0.19.1_linux_amd64.bz2"
PORT=$((20000 + RANDOM % 20000))
(cd "$W" && exec python3 -m http.server "$PORT" --bind 127.0.0.1 >/dev/null 2>&1) & SRV=$!
sleep 1

cp -r "$ROOT" "$T/src-copy" 2>/dev/null
grep -q '###2099.01.01' "$T/src-copy/CHANGELOG.md" 2>/dev/null || printf '###2099.01.01\n- test\n\n' | cat - "$T/src-copy/CHANGELOG.md" > "$T/cl" && mv "$T/cl" "$T/src-copy/CHANGELOG.md"
python3 "$T/src-copy/build.py" --version 2099.01.01 --ref $REF --owner o --repo r > /dev/null || { echo "build fehlgeschlagen"; exit 1; }
PLG="$T/src-copy/appdata-incremental.plg"

# extract scripts with expanded entities, redirect absolute paths into $T/root
python3 - "$PLG" "$T" "$PORT" <<'EOF'
import sys, xml.dom.minidom as md
plg, t, port = sys.argv[1:]
d = md.parse(plg)
root = t + "/root"
def fix(s):
    s = s.replace("https://raw.githubusercontent.com/o/r", "http://127.0.0.1:%s/o/r" % port)
    s = s.replace("https://github.com/restic/restic/releases/download", "http://127.0.0.1:%s/rel" % port)
    import re
    # only absolute paths, not "$PKG/usr/local/..."
    return re.sub(r'(?<![\w}])(/boot/|/usr/local/|/var/lib/|/var/log/)', lambda m: root + m.group(1), s)
n = 0
for f in d.getElementsByTagName("FILE"):
    inline = f.getElementsByTagName("INLINE")[0].firstChild.data
    if f.getAttribute("Run"):
        kind = "remove" if f.getAttribute("Method") == "remove" else "install"
        open("%s/%s.sh" % (t, kind), "w").write(fix(inline))
    else:
        name = fix(f.getAttribute("Name"))
        import os; os.makedirs(os.path.dirname(name), exist_ok=True)
        open(name, "w").write(inline)
    n += 1
print("plg:", d.documentElement.getAttribute("version"), d.documentElement.getAttribute("pluginURL"), file=open(t + "/attrs", "w"))
EOF
R="$T/root"; mkdir -p "$R/usr/local/sbin" "$R/usr/local/bin" "$R/usr/local/emhttp/plugins"
# the script calls /usr/local/sbin/aib with the system php → use a php shim
mkdir -p "$T/shim"; printf '#!/bin/sh\nexec "%s" "$@"\n' "$PHP" > "$T/shim/php"; chmod +x "$T/shim/php"
command -v bunzip2 >/dev/null || { printf '#!/bin/sh\n[ "$1" = -c ] && shift\nexec python3 -c "import bz2,sys;sys.stdout.buffer.write(bz2.open(sys.argv[1]).read())" "$1"\n' > "$T/shim/bunzip2"; chmod +x "$T/shim/bunzip2"; }
sed -i "1s|^|export PATH=$T/shim:\$PATH\n|" "$T/install.sh"

echo "== Installation"
bash "$T/install.sh" > "$T/inst1" 2>&1; rc=$?
cat "$T/inst1" | sed 's/^/     /'
check "Installation Exit 0" "[ $rc = 0 ]"
check "pluginURL zeigt auf main" "grep -q 'raw.githubusercontent.com/o/r/main/appdata-incremental.plg' '$T/attrs'"
check "Dateien installiert" "[ -f '$R/usr/local/emhttp/plugins/appdata-incremental/include/Backup.php' ]"
check "VERSION-Datei" "[ \"\$(cat '$R/usr/local/emhttp/plugins/appdata-incremental/VERSION')\" = 2099.01.01 ]"
check "aib version" "[ \"\$('$PHP' '$R/usr/local/emhttp/plugins/appdata-incremental/scripts/aib' version)\" = 2099.01.01 ]"
check "restic installiert" "'$R/usr/local/bin/aib-restic' version | grep -q 0.19.1"
check "Cache auf Flash" "[ -f '$R/boot/config/plugins/appdata-incremental/pkg/2099.01.01/usr/local/emhttp/plugins/appdata-incremental/scripts/aib' ] && [ -s '$R/boot/config/plugins/appdata-incremental/bin/restic-0.19.1' ]"

echo "== Neustart ohne Internet"
kill $SRV; sleep 0.5
rm -rf "$R/usr/local/emhttp/plugins/appdata-incremental" "$R/usr/local/bin/aib-restic"
bash "$T/install.sh" > "$T/inst2" 2>&1; rc=$?
check "Installation aus Flash-Cache ohne Netz" "[ $rc = 0 ] && [ -f '$R/usr/local/emhttp/plugins/appdata-incremental/include/Backup.php' ]"

echo "== Manipulierte Datei"
echo "// evil" >> "$R/boot/config/plugins/appdata-incremental/pkg/2099.01.01/usr/local/emhttp/plugins/appdata-incremental/include/Util.php"
(cd "$W" && exec python3 -m http.server "$PORT" --bind 127.0.0.1 >/dev/null 2>&1) & SRV=$!; sleep 1
bash "$T/install.sh" > "$T/inst3" 2>&1; rc=$?
check "geänderte Cache-Datei wird neu geladen" "[ $rc = 0 ] && ! grep -q evil '$R/usr/local/emhttp/plugins/appdata-incremental/include/Util.php'"
echo "// evil" >> "$W/o/r/$REF/src/usr/local/emhttp/plugins/appdata-incremental/include/Util.php"
rm -rf "$R/boot/config/plugins/appdata-incremental/pkg"
bash "$T/install.sh" > "$T/inst4" 2>&1; rc=$?
check "falsche Prüfsumme vom Server → Abbruch" "[ $rc = 1 ] && grep -q 'Prüfsumme von usr/local/emhttp/plugins/appdata-incremental/include/Util.php' '$T/inst4'"

echo "== Deinstallation"
bash "$T/remove.sh" > "$T/rm" 2>&1
check "Programmdateien entfernt, Konfiguration bleibt" "[ ! -e '$R/usr/local/emhttp/plugins/appdata-incremental' ] && [ ! -e '$R/usr/local/bin/aib-restic' ] && [ -d '$R/boot/config/plugins/appdata-incremental/secrets' ]"

echo; echo "Ergebnis: $PASS ok, $FAIL fehlgeschlagen"; [ $FAIL = 0 ]
