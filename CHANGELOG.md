###2026.11.16a
- Neu: WebGUI unter Einstellungen → User Utilities → Appdata Incremental (7 Tabs, Unraid-Stil, Hilfetexte)
- Einrichtung ohne Terminal: Ziel (SFTP/lokal/benutzerdefiniert), SSH-Schlüssel erzeugen und hinterlegen, Repository-Passwort erzeugen (Einmal-Anzeige, Download), Verbindung testen, Repository initialisieren
- Container-Auswahl mit Icon, Status und Pfaden; Ausschlüsse per Ordner-Browser und Vorlagen; „Jetzt sichern“
- Zeitplan, Aufbewahrung, Benachrichtigungen, Regeln für neue Container, Import/Export der Konfiguration
- Verlauf mit Details und geänderten Dateien; Wiederherstellen per Dateibaum (Ordner oder an Ort und Stelle)
- Live-Log mit Fortschritt, Abbrechen-Knopf (startet gestoppte Container sofort wieder), Log-Historie, Systemcheck, Snapshot-Selbsttest
- CLI: Einzeldateien an Ort und Stelle wiederherstellen (`restore-files --in-place`), Jobs mit `--quiet`

###2026.11.16
- Erste Version (MVP, nur Kommandozeile): Kern-CLI `aib`, restic 0.19.1
- Alle Docker-Container werden erkannt, auch ohne Unraid-Vorlage
- Kurze Ausfallzeit: Container stoppen → btrfs/ZFS-Snapshot → starten → aus dem Snapshot sichern
- Appdata, die auf mehrere Pools verteilt ist, wird vollständig gesichert und wiederhergestellt
- Ausschlüsse, Gruppen, Reihenfolge und Aufbewahrung pro Container; Vorlagen für neue Container
- Wiederherstellung ganzer Container (in einen Ordner oder an Ort und Stelle) und einzelner Dateien
- Zeitplan, Log, Unraid-Benachrichtigungen
