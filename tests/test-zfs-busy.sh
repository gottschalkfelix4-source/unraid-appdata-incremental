#!/bin/bash
# ZFS snapshot cleanup when the snapshot is still busy right after a backup (mock zfs/umount).
# Usage: PHP=/path/php tests/test-zfs-busy.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(dirname "$HERE")"
PHP="${PHP:-php}"
T="$(mktemp -d /tmp/aib-zfs.XXXXXX)"; trap 'rm -rf "$T"' EXIT
PASS=0; FAIL=0
check(){ if eval "$2"; then PASS=$((PASS+1)); echo "  ok   $1"; else FAIL=$((FAIL+1)); echo "  FAIL $1"; fi; }

# mock zfs: "destroy" answers "dataset is busy" until $T/busy reaches 0
cat > "$T/zfs" <<EOF
#!/bin/sh
echo "\$@" >> "$T/zfs.log"
if [ "\$1" = destroy ]; then
  n=\$(cat "$T/busy"); if [ "\$n" -gt 0 ]; then echo \$((n-1)) > "$T/busy"; echo "cannot destroy snapshot \$2: dataset is busy" >&2; exit 1; fi
  exit 0
fi
exit 0
EOF
printf '#!/bin/sh\necho "$@" >> %s/umount.log\n' "$T" > "$T/umount"
chmod +x "$T/zfs" "$T/umount"
echo "pool2 /mnt/pool2/.zfs/snapshot/aib zfs ro 0 0" > "$T/mounts"

run() { # busy-count → prints warnings/errors
  echo "$1" > "$T/busy"; : > "$T/zfs.log"; : > "$T/umount.log"
  AIB_BIN_ZFS="$T/zfs" AIB_BIN_UMOUNT="$T/umount" AIB_PROC_MOUNTS="$T/mounts" AIB_RETRY_SCALE=0.01 \
  AIB_STATE_DIR="$T/state" AIB_LOG_DIR="$T/log" AIB_CFG_DIR="$T/cfg" AIB_TMP_DIR="$T/tmp" \
  "$PHP" -r '
    namespace Aib; foreach (["Util","Config","Docker","Storage"] as $f) require $argv[1] . "/include/$f.php";
    Log::$echo = false;
    $u = new SnapUnit("zfs", "pool2", "/mnt/pool2", "pool2@aib", "/mnt/pool2/.zfs/snapshot/aib");
    $u->created = true;
    Storage::destroy($u);
    echo "errors=" . Log::$errors . " warnings=" . Log::$warnings . "\n";
  ' "$ROOT/src/usr/local/emhttp/plugins/appdata-incremental"
}

echo "== ZFS-Snapshot kurz belegt"
R=$(run 2)
check "nach 2× busy gelöscht, kein Fehler, keine Warnung" "[ '$R' = 'errors=0 warnings=0' ]"
check "3 Löschversuche" "[ \$(grep -c '^destroy pool2@aib' '$T/zfs.log') = 3 ]"
check "Snapshot-Mount vorher ausgehängt" "grep -q '/mnt/pool2/.zfs/snapshot/aib' '$T/umount.log'"

echo "== ZFS-Snapshot dauerhaft belegt"
R=$(run 99)
check "nur Warnung, kein Fehler (Backup gilt nicht als fehlgeschlagen)" "[ '$R' = 'errors=0 warnings=1' ]"
check "8 Versuche, dann aufgegeben" "[ \$(grep -c '^destroy pool2@aib' '$T/zfs.log') = 8 ]"

echo; echo "Ergebnis: $PASS ok, $FAIL fehlgeschlagen"; [ $FAIL = 0 ]
