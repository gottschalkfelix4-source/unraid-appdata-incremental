# Appdata Incremental für Unraid

Inkrementelle, deduplizierte Backups von Docker-Appdata mit [restic](https://restic.net), als Unraid-Plugin.
Die Bedienung orientiert sich an „Appdata Backup“: Container-Auswahl, Stoppen ja/nein, Gruppen, Reihenfolge und Ausschlüsse pro Container.
Dazu kommen Versionsverlauf, Deduplizierung und Wiederherstellung einzelner Dateien.

> **Status: MVP, nur Kommandozeile.** Eine WebGUI ist geplant. Bitte zuerst parallel zum bisherigen Backup betreiben und eine Wiederherstellung testen.

## Funktionen

- **Alle Container** werden über Docker erkannt, auch solche ohne Unraid-Vorlage (Compose, andere Verwaltungs-Tools).
- **Kurze Ausfallzeit**: Container stoppen → btrfs- oder ZFS-Snapshot (Sekunden) → Container starten → aus dem Snapshot sichern.
- **Inkrementell und dedupliziert** mit restic. Jede Sicherung ist eine vollständige Version; übertragen werden nur Änderungen.
- **Auf mehrere Pools verteilte Appdata** (`/mnt/user/appdata/X` liegt teils auf `cache`, teils auf einem anderen Pool) wird vollständig gesichert und wiederhergestellt.
- **Pro Container**: sichern ja/nein, stoppen ja/nein, Gruppe, Reihenfolge, Ausschlüsse, zusätzliche Pfade, eigene Aufbewahrung.
- **Vorlagen** für neu gefundene Container, z. B. Ausschlüsse oder Abwahl anhand des Mount-Pfads.
- Zusätzlich gesichert werden `docker inspect` und die Unraid-Vorlage des Containers.
- **Wiederherstellung**: ganzer Container in einen Ordner oder an Ort und Stelle (der alte Stand wird aufbewahrt, nicht gelöscht), oder einzelne Dateien aus einer beliebigen Version.
- **Aufbewahrung** täglich, wöchentlich und monatlich; wöchentliches `prune`, monatliches `check`.
- Zeitplan, Logs auf dem USB-Stick, Unraid-Benachrichtigungen bei Fehlern.
- restic wird beim Installieren aus dem **offiziellen Release** geladen und per SHA256 geprüft. Auch die Plugin-Dateien werden per SHA256 geprüft und auf dem USB-Stick zwischengespeichert, damit ein Neustart ohne Internet funktioniert.

## Installation

Unraid → **Plugins** → **Install Plugin** → diese URL eintragen:

```
https://raw.githubusercontent.com/gottschalkfelix4-source/unraid-appdata-incremental/main/appdata-incremental.plg
```

oder in der Konsole:

```bash
plugin install https://raw.githubusercontent.com/gottschalkfelix4-source/unraid-appdata-incremental/main/appdata-incremental.plg
```

Voraussetzung: Unraid 7.0 oder neuer. Updates erscheinen unter Plugins → „Check for updates“.

## Einrichtung

### 1. Ziel vorbereiten

restic unterstützt viele Ziele, unter anderem einen lokalen Pfad, SFTP, S3 und rest-server. Beispiel für ein SFTP-Ziel, etwa eine Storage Box mit SSH auf Port 23:

```bash
D=/boot/config/plugins/appdata-incremental/secrets
mkdir -p $D
ssh-keygen -t ed25519 -N '' -C appdata-incremental -f $D/id_ed25519
ssh-copy-id -p 23 -i $D/id_ed25519.pub USER@HOST   # bei manchen Anbietern zusätzlich -s
openssl rand -base64 32 > $D/repo.pass               # Repo-Passwort
```

**Das Repo-Passwort zusätzlich außerhalb des Servers aufbewahren**, z. B. im Passwortmanager. Ohne dieses Passwort sind alle Backups unbrauchbar.
Der USB-Stick ist FAT32 und speichert keine Dateirechte. Das Plugin nutzt deshalb eine Kopie des SSH-Keys mit Rechten 0600 im RAM.

### 2. Konfiguration

```bash
aib config sync        # legt config.json an und trägt alle Container ein
nano /boot/config/plugins/appdata-incremental/config.json
#   "repository": "sftp:USER@HOST:restic-appdata"
aib doctor             # prüft alles, ändert nichts
aib plan               # zeigt, was gesichert würde
aib selftest-snapshot /mnt/user/appdata/<ordner>   # testet Snapshot + Bind-Mount ohne Container-Stopp
aib init               # restic-Repository anlegen
```

Vorlagen (`presets`, `auto_disable_mount_patterns`) wirken beim ersten Eintragen eines Containers. Trag sie deshalb **vor** `aib config sync` in `config.json` ein; ein Beispiel steht in [docs/examples/config.example.json](docs/examples/config.example.json).
Einstellungen einzelner Container änderst du so:

```bash
aib set <container> enabled=nein
aib set <container> stop=ja group=nextcloud order=10
aib set <container> excludes=cache,logs/old     # ersetzt die Liste
aib set <container> excludes+=tmp               # ergänzt
```

Ausschlussregeln:
- `cache` oder `logs/old` gilt relativ zum Appdata-Ordner des Containers, auf jedem Pool.
- `*.log` ohne Schrägstrich gilt überall.
- `/mnt/...` ist ein absoluter Pfad.

### 3. Sichern

```bash
aib backup --container <name>     # erst einen Container testen
aib backup                        # alle ausgewählten
aib schedule enable               # Zeitplan aus schedule.backup (Standard 02:00 täglich)
aib status ; aib log
```

## Wiederherstellen

```bash
aib snapshots [--container <name>]                       # Versionen mit Größe, neuen und geänderten Dateien
aib ls <snapshot> [pfad]                                 # Inhalt einer Version
aib diff <snapshotA> <snapshotB>                         # Unterschiede

aib restore-container <name> --snapshot <id|latest> --target /mnt/user/restore-test
aib restore-container <name> --snapshot <id|latest> --in-place          # zeigt nur, was passieren würde
aib restore-container <name> --snapshot <id|latest> --in-place --yes    # führt aus
aib restore-files <snapshot> --target /mnt/user/restore-test --include /mnt/cache/appdata/x/config.xml
```

Ablauf beim Wiederherstellen an Ort und Stelle:
1. Der Container wird gestoppt, ebenso andere laufende Container, die denselben Ordner nutzen. Für diese ist `--stop-dependents` nötig.
2. Die Daten werden in einen Zwischenordner geladen.
3. Der bisherige Stand wird nach `<pfad>/.aib-pre-restore-<zeit>` verschoben.
4. Ausgeschlossene Ordner der obersten Ebene bleiben unverändert.

## Wie der Snapshot funktioniert

- **btrfs**: Ein Read-only-Snapshot des Subvolumes, in dem der Ordner liegt, unter `<pool>/appdata/.aib-snapshots/`. Liegt appdata nicht in einem eigenen Subvolume, wird das Top-Level-Subvolume des Pools gesnapshottet. Das geht ebenfalls in Sekunden.
- **ZFS**: `zfs snapshot <dataset>@aib`, gelesen über `.zfs/snapshot/aib`.
- restic läuft in einem privaten Mount-Namespace, in dem der Snapshot per Bind-Mount über dem Originalpfad liegt. Im Repository stehen deshalb die echten Pfade, und Inkremente und Wiederherstellung funktionieren ohne Umweg.
- Andere Dateisysteme (XFS-Array-Disks) haben keinen Snapshot. Diese Container werden im gestoppten Zustand gesichert.
- Übrig gebliebene Snapshots eines abgebrochenen Laufs werden beim nächsten Lauf gelöscht. Container, die ein abgebrochener Lauf gestoppt hatte, werden wieder gestartet.

## Dateien

| Ort | Inhalt |
|---|---|
| `/boot/config/plugins/appdata-incremental/config.json` | Konfiguration |
| `/boot/config/plugins/appdata-incremental/secrets/` | Repo-Passwort, SSH-Key, known_hosts |
| `/boot/config/plugins/appdata-incremental/logs/` | Logs der letzten Läufe |
| `/boot/config/plugins/appdata-incremental/pkg/`, `bin/` | Zwischenspeicher für Plugin-Dateien und restic |
| `/usr/local/sbin/aib`, `/usr/local/bin/aib-restic` | Programme (RAM, bei jedem Start neu installiert) |

Die Deinstallation entfernt nur die Programme. Konfiguration, Schlüssel, Logs und das Repository bleiben.

## Grenzen

- Noch keine WebGUI.
- Docker **Named Volumes** werden nicht gesichert, nur Bind-Mounts unterhalb der Appdata-Ordner. Weitere Pfade sind über `extra_paths` möglich.
- Verschachtelte btrfs-Subvolumes unterhalb eines Appdata-Ordners sind im Snapshot leer.

## Entwicklung

```bash
PHP=php RESTIC=restic tests/run-tests.sh                                    # Kernlogik (Docker-Mock, lokales Repo)
PHP=php RESTIC_BZ2=restic_0.19.1_linux_amd64.bz2 tests/test-plg.sh          # Installer gegen lokalen Server
```

Release:
1. `src/` und `CHANGELOG.md` committen.
2. `python3 build.py --version <JJJJ.MM.TT> --ref <commit-sha>` ausführen.
3. `appdata-incremental.plg` committen.

Die `.plg` lädt die Dateien genau von diesem Commit und prüft jede per SHA256.

## Lizenz

MIT, siehe [LICENSE](LICENSE). restic wird nicht mitgeliefert, sondern aus dem offiziellen Release geladen (BSD-2-Clause).
