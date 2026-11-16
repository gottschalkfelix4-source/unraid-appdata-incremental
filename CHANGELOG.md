###2026.11.16
- Erste Version (MVP, nur Kommandozeile): Kern-CLI `aib`, restic 0.19.1
- Alle Docker-Container werden erkannt, auch ohne Unraid-Vorlage
- Kurze Ausfallzeit: Container stoppen → btrfs/ZFS-Snapshot → starten → aus dem Snapshot sichern
- Appdata, die auf mehrere Pools verteilt ist, wird vollständig gesichert und wiederhergestellt
- Ausschlüsse, Gruppen, Reihenfolge und Aufbewahrung pro Container; Vorlagen für neue Container
- Wiederherstellung ganzer Container (in einen Ordner oder an Ort und Stelle) und einzelner Dateien
- Zeitplan, Log, Unraid-Benachrichtigungen
