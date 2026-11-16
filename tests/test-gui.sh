#!/bin/bash
# WebGUI/API test: starts the harness (php -S) with mock docker + local restic repo and drives the real api.php via HTTP.
# Usage: PHP=/path/php RESTIC=/path/restic JQUERY=/path/jquery.js tests/test-gui.sh   (SERVE=1 keeps the server running)
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(dirname "$HERE")"
PHP="${PHP:-php}"; RESTIC="${RESTIC:-restic}"
T="$(mktemp -d /tmp/aib-gui.XXXXXX)"
cleanup() { [ -n "${SRV:-}" ] && kill $SRV 2>/dev/null; [ -n "${KEEP:-}" ] && echo "Testdaten: $T" || rm -rf "$T"; }
trap cleanup EXIT
PASS=0; FAIL=0
check(){ if eval "$2"; then PASS=$((PASS+1)); echo "  ok   $1"; else FAIL=$((FAIL+1)); echo "  FAIL $1"; fi; }
M="$T/mnt"
mk() { mkdir -p "$(dirname "$1")"; printf '%s' "$2" > "$1"; }

# ---------- fixture (generic names only)
mk "$M/cache/appdata/gamepanel/postgres/db.dat" "db-v1"
mk "$M/cache/appdata/gamepanel/backups/big.zip" "BACKUP"
mk "$M/cache/appdata/gamepanel/servers/srv1/world.dat" "world"
mk "$M/cache/appdata/gamepanel/cache/c.bin" "cache"
mk "$M/pool2/appdata/gamepanel/nodes/n.json" "nodes-v1"
mk "$M/cache/appdata/webapp/config.xml" "web-v1"
mk "$M/cache/appdata/webapp/logs/a.log" "log"
mkdir -p "$M/user/appdata" "$T/icons" "$T/state-icons" "$T/bin"
printf '\x89PNG fake' > "$T/icons/webapp-icon.png"
ct() { local n=$1 r=$2 icon=$3; shift 3; local ms=""
  for m in "$@"; do ms="$ms{\"Type\":\"bind\",\"Source\":\"$m\",\"Destination\":\"/data\",\"RW\":true},"; done
  echo "{\"Id\":\"id-$n\",\"Name\":\"/$n\",\"State\":{\"Running\":$r,\"Status\":\"x\"},\"Config\":{\"Image\":\"img/$n\",\"Labels\":{\"net.unraid.docker.icon\":\"$icon\"}},\"HostConfig\":{\"RestartPolicy\":{\"Name\":\"no\"}},\"Mounts\":[${ms%,}]}"; }
{ echo "["; ct gamepanel true "https://example.org/i.png" "$M/user/appdata/gamepanel"; echo ",";
  ct game-a true "" "$M/user/appdata/gamepanel/servers/srv1"; echo ",";
  ct webapp true "" "$M/user/appdata/webapp"; echo "]"; } > "$T/docker.json"
printf '#!/bin/sh\necho "$@" >> %s/notify.log\n' "$T" > "$T/bin/notify"; chmod +x "$T/bin/notify"
printf '#!/bin/sh\nexec "%s" "%s" "$@"\n' "$PHP" "$ROOT/src/usr/local/emhttp/plugins/appdata-incremental/scripts/aib" > "$T/bin/aib"; chmod +x "$T/bin/aib"
printf '[x]\ncsrf_token="tok-123"\n' > "$T/var.ini"
mkdir -p "$T/flash"
cat > "$T/flash/config.json" <<EOF
{ "restic_bin": "$RESTIC", "appdata_roots": ["$M/user/appdata", "$M/*/appdata"], "snapshot": {"mode": "none"} }
EOF

export AIB_CFG_DIR="$T/flash" AIB_STATE_DIR="$T/state" AIB_LOG_DIR="$T/log" AIB_TMP_DIR="$T/tmp" AIB_MNT_ROOT="$M" \
  AIB_TEMPLATES_DIR="$T/templates" AIB_NOTIFY="$T/bin/notify" AIB_UPDATE_CRON="/bin/true" AIB_SELF="$T/bin/aib" \
  AIB_BIN_DOCKER="$HERE/mock-docker.py" MOCK_DOCKER_STATE="$T/docker.json" MOCK_DOCKER_LOG="$T/docker.log" \
  AIB_VAR_INI="$T/var.ini" AIB_TEST_CSRF="tok-123" AIB_DOCKER_ICONS="$T/icons" AIB_STATE_ICONS="$T/state-icons" AIB_JQUERY="${JQUERY:-/dev/null}" AIB_FA_DIR="${FA_DIR:-}"
PORT="${PORT:-$((20000 + RANDOM % 20000))}"
PREPEND=(); [ "${UNRAID_PREPEND:-1}" = 1 ] && PREPEND=(-d "auto_prepend_file=$HERE/harness/unraid_prepend.php")
"$PHP" "${PREPEND[@]}" -S 127.0.0.1:$PORT "$HERE/harness/router.php" > "$T/server.log" 2>&1 & SRV=$!
sleep 1
URL="http://127.0.0.1:$PORT/plugins/appdata-incremental/include/api.php"
if [ -n "${FRESH:-}" ]; then echo "Harness (frischer Stand, ohne Tests): http://127.0.0.1:$PORT/"; wait $SRV; exit 0; fi
# api ACTION [key=value ...]  → JSON on stdout
api() { local a=$1; shift; local args=(--data-urlencode "action=$a" --data-urlencode "csrf_token=tok-123"); for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  curl -s "$URL" "${args[@]}" | tee -a "$T/responses.log"; echo >> "$T/responses.log"; }
j() { "$PHP" -r '$d=json_decode(stream_get_contents(STDIN),true); $v=$d; foreach(explode(".",$argv[1]) as $k){ if($k==="") continue; $v=is_array($v)&&array_key_exists($k,$v)?$v[$k]:null; } echo is_bool($v)?($v?"true":"false"):(is_array($v)?json_encode($v):$v);' "$1"; }
waitjob() { for i in $(seq 1 150); do [ "$(api state | j data.running)" = false ] && return 0; sleep 0.2; done; return 1; }

echo "== Sicherheit"
check "mit gültigem Token funktioniert es (Unraid entfernt das Token vor dem Plugin)" "[ \"\$(api state | j ok)\" = true ]"
check "falsches CSRF-Token → 403" "[ \"\$(curl -s -o /dev/null -w '%{http_code}' $URL --data 'action=state&csrf_token=falsch')\" = 403 ]"
check "ohne CSRF-Token → 403" "[ \"\$(curl -s -o /dev/null -w '%{http_code}' $URL --data 'action=state')\" = 403 ]"
check "GET → 405" "[ \"\$(curl -s -o /dev/null -w '%{http_code}' '$URL?action=state')\" = 405 ]"
check "unbekannte Aktion → Fehler" "[ \"\$(api 'nope' | j ok)\" = false ]"
check "Containername mit Shell-Zeichen abgelehnt" "api browse 'container=x; rm -rf /' | grep -q 'Ungültiger Containername'"
check "Pfad mit .. im Browser abgelehnt" "api browse container=webapp 'rel=../../etc' | grep -q 'kein ..'"
check "Snapshot-ID mit Shell-Zeichen abgelehnt" "api ls 'id=latest; ls' path=/ | grep -qE 'Ungültige Snapshot-ID|nicht eingerichtet'"

echo "== Ersteinrichtung per GUI"
check "Status ohne Einrichtung: configured=false" "[ \"\$(api state | j data.configured)\" = false ]"
check "Ziel lokal außerhalb /mnt abgelehnt" "api save_target 'target={\"type\":\"local\",\"local_path\":\"/etc/x\"}' | grep -q 'unter /mnt/'"
check "SFTP mit ungültigem Host abgelehnt" "api save_target 'target={\"type\":\"sftp\",\"host\":\"a b;c\",\"user\":\"u\",\"port\":23,\"path\":\"r\"}' | grep -q 'Hostname'"
check "Einrichtung anfangs nicht abgeschlossen" "[ \"\$(api settings | j data.setup_completed)\" = false ]"
check "SFTP-Ziel speichern" "[ \"\$(api save_target 'target={\"type\":\"sftp\",\"host\":\"backup.example.com\",\"user\":\"u1\",\"port\":23,\"path\":\"restic-appdata\"}' | j data.repository)\" = 'sftp:u1@backup.example.com:restic-appdata' ]"
R=$(api key_generate)
check "SSH-Schlüssel erzeugen" "echo '$R' | grep -q 'ssh-ed25519' && [ -f '$T/flash/secrets/id_ed25519' ]"
check "privater Schlüssel nie in Antwort" "! grep -q 'PRIVATE KEY' '$T/responses.log'"
check "zweites Erzeugen ohne Bestätigung abgelehnt" "api key_generate | grep -q 'Bestätigen\\|bestätigen'"
check "Anmeldetest: Server nicht erreichbar → verständliche Meldung" "api test_login | grep -qE 'nicht gefunden|nicht erreichbar|fehlgeschlagen|klappt noch nicht'"
check "Schlüssel hinterlegen ohne Passwort abgelehnt" "api key_install mode=storagebox password= | grep -q 'Passwort fehlt'"
check "lokales Ziel speichern" "[ \"\$(api save_target 'target={\"type\":\"local\",\"local_path\":\"$M/user/backups/repo\"}' | j ok)\" = true ]"
PW=$(api password_generate | j data.password)
check "Passwort erzeugen (einmalige Anzeige)" "[ \${#PW} -ge 30 ] && [ \"\$(cat '$T/flash/secrets/repo.pass')\" = \"\$PW\" ]"
check "Passwort-Datei 0600" "[ \"\$(stat -c %a '$T/flash/secrets/repo.pass')\" = 600 ]"
check "zweites Erzeugen ohne Bestätigung abgelehnt" "api password_generate | grep -q 'Ersetzen nur mit Bestätigung'"
check "Einstellungen enthalten kein Passwort" "! api settings | grep -q \"\$PW\" && [ \"\$(api settings | j data.password.set)\" = true ]"
check "zu kurzes eigenes Passwort abgelehnt" "api password_set password=kurz force=1 | grep -q '12 Zeichen'"
check "Verbindung testen: noch kein Repository" "[ \"\$(api test_connection | j data.initialized)\" = false ]"
check "Repository initialisieren" "[ \"\$(api init_repo | j ok)\" = true ] && [ -f '$M/user/backups/repo/config' ]"
check "Verbindung testen: Repository vorhanden" "[ \"\$(api test_connection | j data.initialized)\" = true ]"
check "Status: configured=true" "[ \"\$(api state | j data.configured)\" = true ]"

echo "== Container"
C=$(api containers)
check "3 Container gelistet" "[ \"\$(echo '$C' | j data.containers | grep -o '\"name\"' | wc -l)\" = 3 ]"
check "Icon aus Docker-Cache über api.php" "echo '$C' | grep -q 'action=icon&name=webapp'"
check "Icon-Label als Fallback" "echo '$C' | grep -q 'https://example.org/i.png'"
check "Icon-Endpunkt liefert PNG" "curl -s '$URL?action=icon&name=webapp' | grep -q PNG"
check "Icon-Endpunkt: ungültiger Name 404" "[ \"\$(curl -s -o /dev/null -w '%{http_code}' '$URL?action=icon&name=../x')\" = 404 ]"
check "Vorlagen geliefert" "echo '$C' | grep -q 'Game-Panel'"
B=$(api browse container=gamepanel rel=)
check "Ordner-Browser zeigt beide Pools (nodes von pool2)" "echo '$B' | grep -q '\"nodes\"' && echo '$B' | grep -q '\"backups\"'"
check "Container speichern (Vorlage + Abwahl)" "[ \"\$(api save_containers 'containers={\"gamepanel\":{\"enabled\":true,\"stop\":true,\"group\":\"\",\"order\":10,\"excludes\":[\"backups\",\"servers\",\"cache\"],\"extra_paths\":[],\"note\":\"\"},\"game-a\":{\"enabled\":false,\"stop\":true,\"group\":\"\",\"order\":100,\"excludes\":[],\"extra_paths\":[],\"note\":\"\"},\"webapp\":{\"enabled\":true,\"stop\":true,\"group\":\"web\",\"order\":20,\"excludes\":[\"logs\"],\"extra_paths\":[],\"note\":\"\"}}' | j data.saved)\" = 3 ]"
check "Ungültige Gruppe abgelehnt" "api save_containers 'containers={\"webapp\":{\"group\":\"a;b\"}}' | grep -q 'Gruppe'"
check "Ausschluss mit .. abgelehnt" "api save_containers 'containers={\"webapp\":{\"excludes\":[\"../x\"]}}' | grep -q 'kein ..'"
check "Plan zeigt Ausschlüsse" "api plan | grep -q 'gamepanel/backups'"

echo "== Zeitplan & Regeln"
check "wöchentlich Mi 03:30" "[ \"\$(api save_settings 'settings={\"schedule\":{\"enabled\":true,\"frequency\":\"weekly\",\"weekday\":3,\"hour\":3,\"minute\":30}}' | j data.cron)\" = '30 3 * * 3' ] && grep -q '^30 3 \\* \\* 3 ' '$T/flash/appdata-incremental.cron'"
check "ungültiger Cron abgelehnt" "api save_settings 'settings={\"schedule\":{\"enabled\":true,\"frequency\":\"custom\",\"custom\":\"* * * *; reboot\"}}' | grep -q 'Cron'"
check "Aufbewahrung ohne Regel abgelehnt" "api save_settings 'settings={\"retention\":{\"keep_daily\":0}}' | grep -q 'Mindestens eine'"
check "Regeln speichern" "[ \"\$(api save_settings 'settings={\"rules\":[{\"match_path\":\"*/appdata/gamepanel\",\"excludes\":[\"backups\"],\"stop\":true},{\"match_path\":\"*/appdata/gamepanel/servers/*\",\"disable\":true}],\"retention\":{\"keep_daily\":7,\"keep_weekly\":4,\"keep_monthly\":6},\"notify\":{\"on_error\":true,\"on_warning\":true,\"on_success\":true}}' | j ok)\" = true ]"
check "Einrichtung als abgeschlossen speichern" "api save_settings 'settings={\"setup_completed\":true}' >/dev/null; [ \"\$(api settings | j data.setup_completed)\" = true ]"
check "Anmeldetest bei lokalem Ziel ok" "[ \"\$(api test_login | j data.ok)\" = true ]"
check "Regel-Muster mit Steuerzeichen abgelehnt" "api save_settings 'settings={\"rules\":[{\"match_path\":\"a\$(id)\"}]}' | grep -q 'Ungültiges Muster'"
check "Zeitplan ausschalten" "api save_settings 'settings={\"schedule\":{\"enabled\":false}}' > /dev/null; [ ! -f '$T/flash/appdata-incremental.cron' ]"
api notify_test > /dev/null
check "Testbenachrichtigung" "grep -q 'Test' '$T/notify.log'"

echo "== Backup per Knopf"
check "Backup starten" "[ \"\$(api run kind=backup containers= | j ok)\" = true ]"
waitjob
check "Lauf fertig, Ergebnis ok" "[ \"\$(api state | j data.last.result)\" = ok ]"
check "game-a nicht gesichert" "! api state | j data.last.containers | grep -q 'game-a'"
check "Live-Log liefert Inhalt" "api log_tail | j data.data | grep -q 'Sichere gamepanel'"
check "Gruppe/Reihenfolge: gamepanel vor webapp" "grep -n 'Sichere' \"\$(ls '$T'/log/backup-*.log | head -1)\" | head -1 | grep -q gamepanel"
sleep 1; mk "$M/cache/appdata/gamepanel/postgres/db.dat" "db-v2"
check "Einzelnen Container sichern" "[ \"\$(api run kind=backup 'containers=[\"gamepanel\"]' | j ok)\" = true ]"
waitjob
( exec 9>"$T/state/aib.lock"; flock 9; sleep 3 ) & LK=$!; sleep 0.5
check "Start während laufendem Vorgang abgelehnt (Sperre)" "api run kind=backup containers= | grep -q 'läuft bereits'"
wait $LK
S=$(api snapshots refresh=1)
check "Verlauf: 3 Versionen" "[ \"\$(echo '$S' | j data.snapshots | grep -o '\"short_id\"' | wc -l)\" = 3 ]"
NEW=$(echo "$S" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["data"]["snapshots"] as $s) if($s["container"]==="gamepanel"){echo $s["id"]; break;}')
OLD=$(echo "$S" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN),true); $o=""; foreach($d["data"]["snapshots"] as $s) if($s["container"]==="gamepanel") $o=$s["id"]; echo $o;')
check "Verlauf enthält Größe und neue Daten" "echo '$S' | grep -q '\"data_added\":[0-9]'"
L=$(api ls id=$NEW path=$M/cache/appdata/gamepanel)
check "Dateibaum: Kinder von gamepanel" "echo '$L' | grep -q 'postgres' && ! echo '$L' | grep -q '\"backups\"'"
check "Dateibaum: Metadaten ausgeblendet" "! api ls id=$NEW path=$T/tmp | grep -q 'docker-inspect'"
D=$(api diff a=$OLD b=$NEW)
check "Vergleich: db.dat geändert" "echo '$D' | grep -q 'postgres/db.dat'"
check "Abbrechen ohne Lauf" "api cancel | grep -q 'Kein Vorgang'"

echo "== Wiederherstellen per GUI"
mk "$M/cache/appdata/gamepanel/postgres/db.dat" "kaputt"
check "Version eines anderen Containers abgelehnt" "api restore container=webapp snapshot=$NEW mode=container target=folder folder=$M/user/restore/x | grep -q 'gehört nicht'"
check "Zielordner außerhalb /mnt abgelehnt" "api restore container=gamepanel snapshot=$NEW mode=container target=folder folder=/etc/x | grep -q 'unter /mnt'"
check "Datei außerhalb der Sicherung abgelehnt" "api restore container=gamepanel snapshot=$NEW mode=files target=inplace 'paths=[\"/etc/passwd\"]' | grep -q 'nicht in dieser Sicherung'"
check "Einzeldatei an Ort und Stelle starten" "[ \"\$(api restore container=gamepanel snapshot=$OLD mode=files target=inplace stop_container=1 'paths=[\"$M/cache/appdata/gamepanel/postgres/db.dat\"]' | j ok)\" = true ]"
waitjob
check "Datei aus alter Version zurück" "[ \"\$(cat '$M/cache/appdata/gamepanel/postgres/db.dat')\" = db-v1 ]"
check "kaputte Version als .aib-pre-restore aufbewahrt" "cat '$M/cache/appdata/gamepanel/postgres/'db.dat.aib-pre-restore-* | grep -q kaputt"
check "Container dafür gestoppt und gestartet" "tail -3 '$T/docker.log' | grep -q 'start gamepanel'"
check "Erfolgsmeldung als Benachrichtigung" "grep -q 'Wiederherstellung erfolgreich' '$T/notify.log'"
check "Ganzer Container in Ordner" "[ \"\$(api restore container=gamepanel snapshot=$NEW mode=container target=folder folder=$M/user/restore/gp | j ok)\" = true ]"
waitjob
check "Ordner-Wiederherstellung enthält beide Pools" "[ -f '$M/user/restore/gp$M/cache/appdata/gamepanel/postgres/db.dat' ] && [ -f '$M/user/restore/gp$M/pool2/appdata/gamepanel/nodes/n.json' ]"

echo "== Abbrechen während eines Laufs"
printf '#!/bin/sh\nfor a in "$@"; do [ "$a" = backup ] && sleep 60; done\nexec "%s" "$@"\n' "$RESTIC" > "$T/bin/slow-restic"; chmod +x "$T/bin/slow-restic"
"$PHP" -r '$f=$argv[1]; $c=json_decode(file_get_contents($f),true); $c["restic_bin"]=$argv[2]; file_put_contents($f,json_encode($c));' "$T/flash/config.json" "$T/bin/slow-restic"
api run kind=backup 'containers=["gamepanel"]' > /dev/null
for i in $(seq 1 50); do [ "$(api state | j data.phase)" = backup ] && break; sleep 0.2; done
check "gamepanel während des Laufs gestoppt" "grep -A3 '\"/gamepanel\"' '$T/docker.json' | grep -q '\"Running\": false'"
check "Abbrechen meldet Erfolg" "api cancel | grep -q 'abgebrochen'"
check "kein Lauf mehr aktiv" "[ \"\$(api state | j data.running)\" = false ]"
check "gamepanel wieder gestartet" "grep -A3 '\"/gamepanel\"' '$T/docker.json' | grep -q '\"Running\": true'"
check "kein restic-Prozess übrig" "! pgrep -f '$T/bin/slow-restic' >/dev/null"
"$PHP" -r '$f=$argv[1]; $c=json_decode(file_get_contents($f),true); $c["restic_bin"]=$argv[2]; file_put_contents($f,json_encode($c));' "$T/flash/config.json" "$RESTIC"

echo "== Logs, Import/Export"
LG=$(api logs)
check "Log-Historie" "echo '$LG' | grep -q 'restore-'"
N0=$(echo "$LG" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["logs"][0]["name"];')
N=$(echo "$LG" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["data"]["logs"] as $l) if(str_starts_with($l["name"],"restore-")){echo $l["name"]; break;}')
check "neuestes Log zuerst (abgebrochenes Backup, mit Abbruch-Vermerk)" "echo '$N0' | grep -q '^backup-' && api log name=$N0 | grep -q 'Abbruch'"
check "Wiederherstellungs-Log lesbar" "api log name=$N | grep -q 'Wiederherstellung'"
check "Log-Name mit Pfad abgelehnt" "api log 'name=../flash/config.json' | grep -q 'Ungültiger Log-Name'"
E=$(api export_config)
check "Export ohne Pfade zu Geheimnissen" "echo '$E' | grep -q gamepanel && ! echo '$E' | grep -q 'password_file\\|key_file'"
check "Import (Regeln, fremde Schlüssel ignoriert)" "api import_config 'json={\"presets\":[{\"match_path\":\"*/appdata/other\",\"excludes\":[\"tmp\"]}],\"password_file\":\"/tmp/evil\",\"ssh\":{\"key_file\":\"/tmp/evil\",\"port\":2222}}' | grep -q 'password_file' && ! grep -q evil '$T/flash/config.json'"
check "Import ungültiges JSON abgelehnt" "api import_config 'json={kaputt' | grep -q 'Keine gültige JSON'"
check "Systemcheck liefert Prüfungen" "api doctor | j data.checks | grep -q 'restic'"
check "Passwort nie außerhalb der Erzeugung in Antworten" "[ \"\$(grep -c \"\$PW\" '$T/responses.log')\" = 1 ]"

echo "== Seiten"
H=$(curl -s "http://127.0.0.1:$PORT/")
check "alle 8 Tabs gerendert (Einrichtung zuerst)" "[ \$(echo \"\$H\" | grep -o 'class=\"tabpage' | wc -l) = 8 ] && echo \"\$H\" | grep -o 'fa-[a-z-]*\"></i> [A-Za-zä]*' | head -1 | grep -q Einrichtung"
check "Assets nur einmal eingebunden" "[ \$(echo \"\$H\" | grep -c 'js/aib.js') = 1 ]"
check "PHP-Fehler im Server-Log" "! grep -qiE 'fatal|warning|deprecated' '$T/server.log'"

echo; echo "Ergebnis: $PASS ok, $FAIL fehlgeschlagen"
if [ -n "${SERVE:-}" ]; then echo "Harness läuft: http://127.0.0.1:$PORT/ (Strg+C beendet)"; wait $SRV; fi
[ $FAIL = 0 ]
