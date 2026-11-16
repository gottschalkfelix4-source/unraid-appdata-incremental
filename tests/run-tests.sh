#!/bin/bash
# Local test suite: mock docker, local restic repository, fake pools (no root, no snapshots).
# Usage: PHP=/path/to/php RESTIC=/path/to/restic tests/run-tests.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(dirname "$HERE")"
AIB="$ROOT/src/usr/local/emhttp/plugins/appdata-incremental/scripts/aib"
PHP="${PHP:-php}"
RESTIC="${RESTIC:-restic}"
T="$(mktemp -d /tmp/aib-test.XXXXXX)"
trap '[ -n "${KEEP:-}" ] && echo "Testdaten: $T" || rm -rf "$T"' EXIT
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ok   $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL $1"; }
check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }

export AIB_CFG_DIR="$T/flash" AIB_STATE_DIR="$T/state" AIB_LOG_DIR="$T/log" AIB_TMP_DIR="$T/tmp" \
       AIB_MNT_ROOT="$T/mnt" AIB_TEMPLATES_DIR="$T/templates" AIB_NOTIFY="$T/notify" AIB_UPDATE_CRON="$T/update_cron" \
       AIB_SELF="/usr/local/sbin/aib" AIB_BIN_DOCKER="$HERE/mock-docker.py" \
       MOCK_DOCKER_STATE="$T/docker.json" MOCK_DOCKER_LOG="$T/docker.log"
aib() { "$PHP" "$AIB" "$@"; }
M="$T/mnt"

# ---------- fixture ----------
mk() { mkdir -p "$(dirname "$1")"; printf '%s' "$2" > "$1"; }
mk "$M/cache/appdata/gamepanel/postgres/db.dat" "db-v1"
mk "$M/cache/appdata/gamepanel/maintenance/m.txt" "maint"
mk "$M/cache/appdata/gamepanel/proxy/p.conf" "proxy"
mk "$M/cache/appdata/gamepanel/.jwt-secret" "jwt"
mk "$M/cache/appdata/gamepanel/backups/big.zip" "BACKUPDATA"
mk "$M/cache/appdata/gamepanel/servers/srv1/world.dat" "world-v1"
mk "$M/cache/appdata/gamepanel/cache/c.bin" "cache"
mk "$M/pool2/appdata/gamepanel/nodes/n.json" "nodes-v1"
mk "$M/pool2/appdata/gamepanel/servers/other/w.dat" "w"
mk "$M/cache/appdata/webapp/config.xml" "webapp-v1"
mk "$M/cache/appdata/webapp/logs/a.log" "log"
mk "$M/pool2/appdata/photoapp/settings.json" "photoapp"
mk "$M/cache/appdata/stoppedapp/app.conf" "stoppedapp"
mkdir -p "$M/user/appdata" "$M/disks" "$T/templates"
mk "$T/templates/my-webapp.xml" "<Container><Name>webapp</Name></Container>"
printf '#!/bin/sh\necho "$@" >> %s/notify.log\n' "$T" > "$T/notify"; chmod +x "$T/notify"
printf '#!/bin/sh\necho called >> %s/update_cron.log\n' "$T" > "$T/update_cron"; chmod +x "$T/update_cron"

ct() { # name running mounts...
  local n=$1 r=$2; shift 2; local ms=""
  for m in "$@"; do ms="$ms{\"Type\":\"bind\",\"Source\":\"$m\",\"Destination\":\"/data\",\"RW\":true},"; done
  echo "{\"Id\":\"id-$n\",\"Name\":\"/$n\",\"State\":{\"Running\":$r,\"Status\":\"x\"},\"Config\":{\"Image\":\"img/$n\"},\"HostConfig\":{\"RestartPolicy\":{\"Name\":\"no\"}},\"Mounts\":[${ms%,}]}"
}
{
  echo "["
  ct GamePanel true "$M/user/appdata/gamepanel" "/var/run/docker.sock"; echo ","
  ct game-a true "$M/user/appdata/gamepanel/servers/srv1"; echo ","
  ct game-b false "$M/user/appdata/gamepanel/servers/srv2"; echo ","
  ct webapp true "$M/user/appdata/webapp" "$M/user/media"; echo ","
  ct photoapp true "$M/pool2/appdata/photoapp"; echo ","
  ct stopped-app false "$M/cache/appdata/stoppedapp"; echo ","
  ct no-appdata true "/tmp"
  echo "]"
} > "$T/docker.json"

mkdir -p "$T/flash/secrets"
echo "test-password-123" > "$T/flash/secrets/repo.pass"
cat > "$T/flash/config.json" <<EOF
{
  "repository": "$T/repo",
  "restic_bin": "$RESTIC",
  "appdata_roots": ["$M/user/appdata", "$M/*/appdata"],
  "presets": [{"match_path": "*/appdata/gamepanel", "excludes": ["backups", "servers", "cache"], "note": "Beispiel-Vorlage"}],
  "auto_disable_mount_patterns": ["*/appdata/gamepanel/servers/*"],
  "snapshot": {"mode": "none"},
  "global_excludes": ["*.log"],
  "retention": {"keep_last": 2, "keep_daily": 0, "keep_weekly": 0, "keep_monthly": 0}
}
EOF

echo "== Konfiguration & Erkennung"
aib config sync > "$T/out" 2>&1
check "config sync trägt Container ein" "grep -q 'GamePanel' '$T/out'"
check "GamePanel-Preset: Ausschlüsse" "\"$PHP\" -r '\$c=json_decode(file_get_contents(\"$T/flash/config.json\"),true); exit(\$c[\"containers\"][\"GamePanel\"][\"excludes\"]===[\"backups\",\"servers\",\"cache\"]?0:1);'"
check "game-a automatisch abgewählt" "\"$PHP\" -r '\$c=json_decode(file_get_contents(\"$T/flash/config.json\"),true); exit(\$c[\"containers\"][\"game-a\"][\"enabled\"]===false && \$c[\"containers\"][\"game-b\"][\"enabled\"]===false ?0:1);'"
check "Config-Datei mit 0600" "[ \"\$(stat -c %a '$T/flash/config.json')\" = 600 ]"
aib plan > "$T/plan" 2>&1
check "Plan: gamepanel auf beiden Pools" "grep -q 'Pfad: $M/cache/appdata/gamepanel' '$T/plan' && grep -q 'Pfad: $M/pool2/appdata/gamepanel' '$T/plan'"
check "Plan: Ausschluss backups pro Pool" "grep -q 'Ausschluss: $M/pool2/appdata/gamepanel/backups' '$T/plan'"
check "Plan: game-a nicht enthalten" "! grep -q 'game-a' '$T/plan'"
check "Plan: media nicht gesichert" "! grep -q 'media' '$T/plan'"
aib set webapp group=media order=10 excludes+=cache > "$T/set" 2>&1
check "aib set schreibt Einstellungen" "\"$PHP\" -r '\$c=json_decode(file_get_contents(\"$T/flash/config.json\"),true)[\"containers\"][\"webapp\"]; exit(\$c[\"group\"]===\"media\" && \$c[\"order\"]===10 && \$c[\"excludes\"]===[\"cache\"]?0:1);'"
aib set webapp stop=vielleicht > /dev/null 2>&1; rc=$?
check "aib set lehnt ungültige Werte ab" "[ $rc = 1 ]"
aib set webapp group= excludes= > /dev/null 2>&1
aib containers > "$T/cts" 2>&1
check "containers listet game-a mit Hinweis" "grep -q 'game-a.*sichern=nein' '$T/cts'"
aib doctor > "$T/doctor" 2>&1; rc=$?
check "doctor läuft (Exit $rc)" "grep -q 'Docker erreichbar' '$T/doctor'"

AIB_CFG_DIR="$T/flash-default" "$PHP" "$AIB" config show > "$T/defaults" 2>&1
check "Standard-Konfiguration ohne Vorlagen" "grep -q '\"presets\": \\[\\]' '$T/defaults' && grep -q '\"auto_disable_mount_patterns\": \\[\\]' '$T/defaults'"
check "Muster /mnt/*/appdata erkennt direkte Pool-Mounts" "grep -q 'Pfad: $M/pool2/appdata/photoapp' '$T/plan'"

echo "== Repository & erstes Backup"
aib init > "$T/init" 2>&1
check "restic init" "[ -f '$T/repo/config' ]"
aib backup > "$T/b1" 2>&1; rc=$?
check "backup Exit 0" "[ $rc = 0 ]"
check "GamePanel gestoppt und gestartet" "grep -q 'stop -t 60 GamePanel' '$T/docker.log' && grep -q 'start GamePanel' '$T/docker.log'"
check "stopped-app (gestoppt) nicht gestartet" "! grep -q 'start stopped-app' '$T/docker.log'"
check "Container ohne Appdata nicht gestoppt" "! grep -qE '^stop.*no-appdata' '$T/docker.log'"
check "game-a nie gestoppt" "! grep -qE '^(stop|start).*game-a' '$T/docker.log'"
check "GamePanel läuft danach" "grep -A3 '\"/GamePanel\"' '$T/docker.json' | grep -q '\"Running\": true'"
"$RESTIC" -r "$T/repo" --password-file "$T/flash/secrets/repo.pass" snapshots --json --tag aib,ct:GamePanel > "$T/s.json" 2>/dev/null
SID=$("$PHP" -r '$s=json_decode(file_get_contents($argv[1]),true); echo end($s)["id"];' "$T/s.json")
"$RESTIC" -r "$T/repo" --password-file "$T/flash/secrets/repo.pass" ls "$SID" > "$T/ls" 2>/dev/null
check "postgres gesichert" "grep -q 'gamepanel/postgres/db.dat' '$T/ls'"
check ".jwt-secret gesichert" "grep -q 'gamepanel/.jwt-secret' '$T/ls'"
check "pool2-Teil (nodes) gesichert" "grep -q 'pool2/appdata/gamepanel/nodes/n.json' '$T/ls'"
check "backups/servers/cache ausgeschlossen" "! grep -qE 'gamepanel/(backups|servers|cache)' '$T/ls'"
check "Container-Konfiguration (docker inspect) gesichert" "grep -q 'meta/GamePanel/docker-inspect.json' '$T/ls'"
"$RESTIC" -r "$T/repo" --password-file "$T/flash/secrets/repo.pass" ls latest --tag ct:webapp > "$T/ls2" 2>/dev/null
check "Unraid-Vorlage mitgesichert" "grep -q 'meta/webapp/unraid-template.xml' '$T/ls2'"
check "*.log global ausgeschlossen" "! grep -q 'a.log' '$T/ls2'"
check "Status-Datei: ok" "aib status | grep -q 'Letzter Lauf: Backup'"
check "Log auf Flash kopiert" "ls '$T/flash/logs/'backup-*.log >/dev/null 2>&1"
check "Snapshot-Zusammenfassung im Log" "grep -q 'GamePanel: fertig' '$T/b1'"

echo "== Inkrementell & Verlauf"
sleep 1
mk "$M/cache/appdata/gamepanel/postgres/db.dat" "db-v2-changed"
aib backup --container GamePanel > "$T/b2" 2>&1
check "zweites Backup nur GamePanel" "grep -q 'Sichere GamePanel' '$T/b2' && ! grep -q 'Sichere webapp' '$T/b2'"
check "Änderung erkannt, keine neuen Dateien" "grep -qE 'GamePanel: fertig.*0 neue / [1-9][0-9]* geänderte' '$T/b2'"
aib snapshots --container GamePanel > "$T/snaps"
check "2 GamePanel-Snapshots" "[ \$(grep -c GamePanel '$T/snaps') = 2 ]"
sleep 1; aib backup --container GamePanel > /dev/null 2>&1
check "Aufbewahrung keep_last=2" "[ \$(aib snapshots --container GamePanel | grep -c GamePanel) = 2 ]"

echo "== Wiederherstellung"
aib restore-container GamePanel --snapshot latest --target "$T/restore1" > "$T/r1" 2>&1
check "restore --target" "[ \"\$(cat '$T/restore1$M/cache/appdata/gamepanel/postgres/db.dat')\" = db-v2-changed ]"
FIRST=$(aib snapshots --container GamePanel --json | "$PHP" -r '$s=json_decode(stream_get_contents(STDIN),true); echo $s[0]["id"];')
mk "$M/cache/appdata/gamepanel/postgres/db.dat" "db-v3-broken"
mk "$M/cache/appdata/gamepanel/newfile" "junk"
aib restore-container GamePanel --in-place > "$T/r2" 2>&1; rc=$?
check "in-place ohne --yes fragt nur (Exit 2)" "[ $rc = 2 ] && [ \"\$(cat '$M/cache/appdata/gamepanel/postgres/db.dat')\" = db-v3-broken ]"
aib restore-container GamePanel --in-place --yes > "$T/r3" 2>&1; rc=$?
check "in-place Exit 0 (game-a nutzt nur ausgeschlossenes servers/)" "[ $rc = 0 ]"
check "postgres wiederhergestellt" "[ \"\$(cat '$M/cache/appdata/gamepanel/postgres/db.dat')\" = db-v2-changed ]"
check "servers/ unangetastet" "[ \"\$(cat '$M/cache/appdata/gamepanel/servers/srv1/world.dat')\" = world-v1 ]"
check "backups/ unangetastet" "[ -f '$M/cache/appdata/gamepanel/backups/big.zip' ]"
check "alter Stand aufbewahrt" "ls -d '$M/cache/appdata/gamepanel/'.aib-pre-restore-* >/dev/null 2>&1 && [ -f \"\$(ls -d '$M/cache/appdata/gamepanel/'.aib-pre-restore-*)/newfile\" ]"
check "neue Datei nicht mehr im Live-Stand" "[ ! -e '$M/cache/appdata/gamepanel/newfile' ]"
check "pool2-Teil wiederhergestellt" "[ \"\$(cat '$M/pool2/appdata/gamepanel/nodes/n.json')\" = nodes-v1 ]"
check "game-a nicht gestoppt bei Restore" "! grep -q 'stop.*game-a' '$T/docker.log'"
check "Restore-Benachrichtigung" "grep -q 'Wiederherstellung GamePanel erfolgreich' '$T/notify.log'"
aib restore-files "$FIRST" --target "$T/restore2" --include "$M/cache/appdata/gamepanel/postgres/db.dat" > /dev/null 2>&1
check "einzelne Datei aus älterer Version" "[ \"\$(cat '$T/restore2$M/cache/appdata/gamepanel/postgres/db.dat')\" = db-v2-changed ] || [ \"\$(cat '$T/restore2$M/cache/appdata/gamepanel/postgres/db.dat')\" = db-v1 ]"
sleep 1; aib backup --container GamePanel > "$T/b4" 2>&1
check "pre-restore-Ordner nicht mitgesichert" "! \"$RESTIC\" -r '$T/repo' --password-file '$T/flash/secrets/repo.pass' ls latest --tag ct:GamePanel 2>/dev/null | grep -q aib-pre-restore"

echo "== Fehlerfälle"
MOCK_DOCKER_FAIL_STOP=webapp aib backup --container webapp > "$T/b5" 2>&1; rc=$?
check "Stopp-Fehler → Exit 1, nicht gesichert" "[ $rc = 1 ] && grep -q 'nicht gesichert, weil er sich nicht stoppen ließ' '$T/b5'"
check "Fehler-Benachrichtigung" "grep -q 'fehlgeschlagen' '$T/notify.log'"
"$PHP" -r '$f=$argv[1]; $s=json_decode(file_get_contents($f),true); $s["stopped_by_aib"]=["webapp"]; file_put_contents($f,json_encode($s));' "$T/state/status.json"
"$HERE/mock-docker.py" stop webapp > /dev/null
aib backup --container photoapp > "$T/b6" 2>&1
check "abgebrochener Lauf: Container wieder gestartet" "grep -q 'abgebrochenen Lauf gestoppt' '$T/b6' && grep -A3 '\"/webapp\"' '$T/docker.json' | grep -q '\"Running\": true'"
( exec 9>"$T/state/aib.lock"; flock 9; aib backup > "$T/b7" 2>&1; echo $? > "$T/rc7" )
check "Sperre verhindert Parallel-Lauf" "grep -q 'läuft bereits' '$T/b7'"
aib selftest-snapshot "$M/cache/appdata/webapp" > "$T/st" 2>&1
check "selftest ohne Snapshot-Möglichkeit bricht sauber ab" "grep -qE 'Kein btrfs/ZFS|nicht möglich' '$T/st' && ! ls -d /appdata/.aib-snapshots 2>/dev/null"

echo "== Zeitplan"
aib schedule apply > /dev/null
check "Zeitplan standardmäßig aus" "[ ! -f '$T/flash/appdata-incremental.cron' ]"
aib schedule enable > /dev/null
check "Cron-Datei nach enable" "grep -q '^0 2 \\* \\* \\* /usr/local/sbin/aib cron' '$T/flash/appdata-incremental.cron' && [ -s '$T/update_cron.log' ]"
aib schedule apply > /dev/null
check "apply (Neustart) behält aktiven Zeitplan" "[ -f '$T/flash/appdata-incremental.cron' ]"
aib schedule disable > /dev/null; aib schedule apply > /dev/null
check "disable bleibt nach apply aus" "[ ! -f '$T/flash/appdata-incremental.cron' ]"
aib cron > /dev/null 2>&1; rc=$?
check "cron-Lauf Exit 0" "[ $rc = 0 ]"

echo
echo "Ergebnis: $PASS ok, $FAIL fehlgeschlagen"
[ $FAIL = 0 ]
