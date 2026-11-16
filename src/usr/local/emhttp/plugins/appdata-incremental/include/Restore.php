<?php
declare(strict_types=1);

namespace Aib;

final class Restore
{
    public function __construct(private Config $cfg, private Restic $restic) {}

    public function findSnapshot(string $container, string $which): array
    {
        $snaps = $this->restic->snapshots($container);
        if (!$snaps) throw new \RuntimeException("Keine Snapshots für $container");
        if ($which === 'latest') return end($snaps);
        foreach ($snaps as $s) {
            if ($s['id'] === $which || ($s['short_id'] ?? '') === $which || str_starts_with($s['id'], $which)) return $s;
        }
        throw new \RuntimeException("Snapshot $which gehört nicht zu $container");
    }

    private function dataPaths(array $snap): array
    {
        return array_values(array_filter((array)$snap['paths'], fn($p) => !Fs::within($p, Env::metaRoot())));
    }

    private function metaOf(array $snap, string $container): array
    {
        $file = Env::metaRoot() . "/$container/aib-container.json";
        [$c, $o] = $this->restic->run(['dump', $snap['id'], $file], null, false);
        $d = $c === 0 ? json_decode($o, true) : null;
        return is_array($d) ? $d : [];
    }

    public function restoreFiles(string $snapId, string $target, array $includes): bool
    {
        $sub = ['restore', $snapId, '--target', $target];
        foreach ($includes as $i) { $sub[] = '--include'; $sub[] = $i; }
        Log::info("Stelle aus $snapId nach $target wieder her" . ($includes ? ': ' . implode(', ', $includes) : ''));
        [$c] = $this->restic->run($sub);
        if ($c !== 0) { Log::error("restic restore fehlgeschlagen (Exit $c)"); return false; }
        Log::info('Fertig.');
        return true;
    }

    /**
     * Restore single files/folders to their original place. Existing versions are renamed to
     * <name>.aib-pre-restore-<time> (nothing is deleted). Optionally stops containers around it.
     */
    public function filesInPlace(string $snapId, array $paths, array $stopContainers = []): bool
    {
        if (!$paths) throw new \RuntimeException('Keine Dateien gewählt');
        $paths = Storage::dedupe(array_map(fn($p) => V::absPath((string)$p), $paths));
        $ts = date('Ymd-His');
        $stopped = [];
        $ok = true;
        try {
            foreach ($stopContainers as $n) {
                if (!Docker::isRunning($n)) continue;
                Log::info("Stoppe $n");
                if (!Docker::stop($n, (int)$this->cfg->get('stop_timeout', 60))) throw new \RuntimeException("$n ließ sich nicht stoppen");
                $stopped[] = $n;
            }
            foreach ($paths as $p) {
                $parent = dirname($p);
                if (!is_dir($parent) && !mkdir($parent, 0755, true)) { Log::error("Kann $parent nicht anlegen"); $ok = false; continue; }
                $staging = "$parent/.aib-restore-$ts";
                Log::info("Stelle $p wieder her");
                [$c] = $this->restic->run(['restore', $snapId, '--target', $staging, '--include', $p]);
                if ($c !== 0 || !file_exists($staging . $p) && !is_link($staging . $p)) {
                    Log::error("Wiederherstellung von $p fehlgeschlagen" . ($c !== 0 ? " (Exit $c)" : ' (nicht im Snapshot)'));
                    Fs::rmTree($staging); $ok = false; continue;
                }
                $old = "$p.aib-pre-restore-$ts";
                if (file_exists($p) || is_link($p)) {
                    if (!rename($p, $old)) { Log::error("Kann $p nicht verschieben"); Fs::rmTree($staging); $ok = false; continue; }
                }
                if (!rename($staging . $p, $p)) { Log::error("Kann wiederhergestellte Version nicht nach $p verschieben – sie liegt in $staging"); $ok = false; continue; }
                Fs::rmTree($staging);
                Log::info("$p wiederhergestellt" . (file_exists($old) || is_link($old) ? " (alter Stand: $old)" : ''));
            }
        } finally {
            foreach (array_reverse($stopped) as $n) { Log::info("Starte $n"); Docker::start($n); }
        }
        return $ok;
    }

    /** Restore all paths of a container snapshot into a separate folder (nothing live is touched). */
    public function containerToTarget(string $container, string $which, string $target): bool
    {
        $snap = $this->findSnapshot($container, $which);
        return $this->restoreFiles($snap['id'], $target, array_merge($this->dataPaths($snap), [Env::metaRoot() . "/$container"]));
    }

    /**
     * Restore a container in place. Live data is not deleted but moved to <path>/.aib-pre-restore-<time>.
     * Top-level entries that were excluded from the backup (e.g. servers/ or backups/ of a game panel) stay untouched.
     */
    public function containerInPlace(string $container, string $which, bool $stopDependents): bool
    {
        $snap = $this->findSnapshot($container, $which);
        $meta = $this->metaOf($snap, $container);
        $anchored = (array)($meta['anchored_excludes'] ?? []);
        $paths = $this->dataPaths($snap);
        if (!$paths) throw new \RuntimeException('Snapshot enthält keine Appdata-Pfade');
        Log::info("Wiederherstellung von $container aus Snapshot " . substr($snap['id'], 0, 8) . " vom {$snap['time']}");

        // containers that use data inside the restored paths (outside of untouched excluded folders)
        $docker = Docker::list();
        $toStop = [];
        foreach ($docker as $n => $info) {
            if (!$info['running']) continue;
            if ($n === $container) { $toStop[] = $n; continue; }
            foreach ($info['mounts'] as $mnt) {
                foreach (Storage::resolve($mnt['source'], $this->cfg)['paths'] as $real) {
                    foreach ($paths as $p) {
                        // only containers that mount the restored folder or something inside it;
                        // containers mounting a parent (file managers etc.) are not stopped
                        if (!Fs::within($real, $p)) continue;
                        $top = $real !== $p ? explode('/', substr($real, strlen($p) + 1))[0] : null;
                        $untouched = $top !== null && in_array($top, (array)($anchored[$p] ?? []), true);
                        if (!$untouched) $toStop[] = $n;
                    }
                }
            }
        }
        $toStop = array_values(array_unique($toStop));
        $others = array_values(array_diff($toStop, [$container]));
        if ($others && !$stopDependents) {
            throw new \RuntimeException('Diese laufenden Container nutzen Daten im Wiederherstellungsbereich: ' . implode(', ', $others) . ' – mit --stop-dependents werden sie mit gestoppt');
        }
        $stopped = [];
        $ok = true;
        try {
            foreach ($toStop as $n) {
                Log::info("Stoppe $n");
                if (!Docker::stop($n, (int)$this->cfg->get('stop_timeout', 60))) throw new \RuntimeException("$n ließ sich nicht stoppen");
                $stopped[] = $n;
            }
            $ts = date('Ymd-His');
            foreach ($paths as $p) {
                $ok = $this->restorePath($snap['id'], $p, (array)($anchored[$p] ?? []), $ts) && $ok;
            }
        } finally {
            foreach (array_reverse($stopped) as $n) { Log::info("Starte $n"); Docker::start($n); }
        }
        return $ok;
    }

    private function restorePath(string $snapId, string $p, array $anchored, string $ts): bool
    {
        $isDir = $this->snapshotIsDir($snapId, $p);
        if (!$isDir) {
            $tmp = dirname($p) . "/.aib-restore-$ts";
            [$c] = $this->restic->run(['restore', $snapId, '--target', $tmp, '--include', $p]);
            if ($c !== 0 || !file_exists($tmp . $p)) { Log::error("Wiederherstellung von $p fehlgeschlagen"); Fs::rmTree($tmp); return false; }
            if (file_exists($p)) rename($p, "$p.aib-pre-restore-$ts");
            rename($tmp . $p, $p);
            Fs::rmTree($tmp);
            Log::info("$p wiederhergestellt" . (file_exists("$p.aib-pre-restore-$ts") ? " (alter Stand: $p.aib-pre-restore-$ts)" : ''));
            return true;
        }
        if (!is_dir($p) && !mkdir($p, 0755, true)) { Log::error("Kann $p nicht anlegen"); return false; }
        $keepTop = array_values(array_filter($anchored, fn($r) => !str_contains($r, '/')));
        $nested = array_values(array_filter($anchored, fn($r) => str_contains($r, '/')));
        $staging = "$p/.aib-restore-$ts";
        $old = "$p/.aib-pre-restore-$ts";
        $isOurs = fn(string $e) => str_starts_with($e, '.aib-restore-') || str_starts_with($e, '.aib-pre-restore-');

        // refuse if something to be moved is a mount point (e.g. a ZFS child dataset)
        $dev = stat($p)['dev'];
        foreach (scandir($p) ?: [] as $e) {
            if ($e === '.' || $e === '..' || $isOurs($e) || in_array($e, $keepTop, true)) continue;
            $st = @lstat("$p/$e");
            if ($st && is_dir("$p/$e") && !is_link("$p/$e") && $st['dev'] !== $dev) { Log::error("$p/$e ist ein eigener Mountpoint – bitte manuell wiederherstellen"); return false; }
        }

        Log::info("Stelle $p wieder her (Zwischenordner $staging)");
        [$c] = $this->restic->run(['restore', "$snapId:$p", '--target', $staging]);
        if ($c !== 0) { Log::error("restic restore für $p fehlgeschlagen (Exit $c) – Live-Daten unverändert"); Fs::rmTree($staging); return false; }

        mkdir($old, 0700);
        foreach (scandir($p) ?: [] as $e) {
            if ($e === '.' || $e === '..' || $isOurs($e) || in_array($e, $keepTop, true)) continue;
            if (!rename("$p/$e", "$old/$e")) { Log::error("Kann $p/$e nicht verschieben – Abbruch, bitte $old prüfen"); return false; }
        }
        foreach (scandir($staging) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            if (file_exists("$p/$e")) { Log::warn("$p/$e existiert schon (ausgeschlossener Ordner?) – wiederhergestellte Version liegt unter $old/restored-$e"); rename("$staging/$e", "$old/restored-$e"); continue; }
            rename("$staging/$e", "$p/$e");
        }
        @rmdir($staging);
        foreach ($nested as $rel) {
            if (file_exists("$old/$rel") && !file_exists("$p/$rel")) {
                @mkdir(dirname("$p/$rel"), 0755, true);
                rename("$old/$rel", "$p/$rel");
                Log::info("Ausgeschlossenen Pfad $rel aus dem Live-Stand übernommen");
            }
        }
        if (count(scandir($old) ?: []) <= 2) { rmdir($old); Log::info("$p wiederhergestellt"); }
        else Log::info("$p wiederhergestellt – alter Stand liegt in $old (nach Prüfung löschen)");
        if ($keepTop) Log::info('Unverändert gelassen (nicht im Backup): ' . implode(', ', $keepTop));
        return true;
    }

    private function snapshotIsDir(string $snapId, string $p): bool
    {
        [$c, $o] = $this->restic->run(['ls', '--json', $snapId, $p], null, false);
        if ($c !== 0) return true;
        foreach (explode("\n", $o) as $l) {
            $j = json_decode($l, true);
            if (is_array($j) && ($j['path'] ?? null) === $p) return ($j['type'] ?? 'dir') === 'dir';
        }
        return true;
    }
}
