###2026.11.16d
- Fix: Ein Lauf galt als „fehlgeschlagen“, wenn der kurzzeitige ZFS-Snapshot direkt nach der Sicherung noch belegt war („dataset is busy“). Der Snapshot wird jetzt ausgehängt und das Löschen einige Sekunden lang wiederholt; klappt es trotzdem nicht, gibt es nur eine Warnung und er wird beim nächsten Snapshot bzw. Lauf entfernt.

###2026.11.16c
- Status-Seite neu: Kacheln für Zustand, letztes Backup, nächstes Backup und Speicher (belegt im Backup-Speicher, Versionen, freier Platz am Ziel mit Balken)
- Laufender Vorgang mit „Container x von y“, Gesamtfortschritt, Laufzeit und Abbrechen
- Liste der letzten Läufe mit Ergebnis, Container-Details und Log je Lauf (optional auch Wiederherstellungen und Wartung)
- Speicherstatistik wird nach jedem Backup aktualisiert; freier Platz per SFTP „df“ bzw. lokal

###2026.11.16b
- Fix: In Unraid kam bei jeder Aktion „Ungültiges CSRF-Token“ (Unraid prüft das Token selbst und entfernt es danach – das Plugin prüft jetzt nur noch, wenn diese Prüfung fehlt)
- Neu: Einrichtungs-Assistent als erster Tab „Einrichtung“ – Schritt für Schritt: Ziel (Storage Box, SFTP oder Ordner), Anmeldung mit Schlüssel, Backup-Passwort, Verbindung, Container, Zeitplan, Fertig
- Storage Box: nur Benutzername eingeben, Server und Port werden automatisch gesetzt; Anmeldung wird nach dem Hinterlegen des Schlüssels geprüft

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
