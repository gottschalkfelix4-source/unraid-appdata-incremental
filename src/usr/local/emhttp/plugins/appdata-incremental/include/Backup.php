<?php
declare(strict_types=1);

namespace Aib;

final class Backup
{
    /** Patterns that are always excluded (our own temporary folders). */
    public const ALWAYS_EXCLUDE = ['.aib-snapshots', '.aib-restore-*', '.aib-pre-restore-*', '*.aib-pre-restore-*'];

    /** @var array<string,array> */
    public array $results = [];

    public function __construct(private Config $cfg, private Restic $restic, private bool $dry = false) {}

    /** Container paths that count as appdata. */
    private function appdataSources(array $info): array
    {
        $out = [];
        foreach ($info['mounts'] as $m) if ($this->cfg->isAppdataPath($m['source'])) $out[] = $m['source'];
        return $out;
    }

    public static function buildExcludes(array $patterns, array $paths): array
    {
        $args = []; $anchored = [];
        foreach ($patterns as $e) {
            $e = trim((string)$e);
            if ($e === '') continue;
            if (str_starts_with($e, '/')) { $args[] = $e; continue; }
            if (!str_contains($e, '/') && strpbrk($e, '*?[') !== false) { $args[] = $e; continue; }
            $rel = trim($e, '/');
            foreach ($paths as $p) {
                $args[] = rtrim($p, '/') . '/' . $rel;
                $anchored[$p][] = $rel;
            }
        }
        foreach (self::ALWAYS_EXCLUDE as $x) $args[] = $x;
        return ['args' => array_values(array_unique($args)), 'anchored' => $anchored];
    }

    /**
     * @param string[]|null $only container names (null = all enabled)
     * @return array<string,array{order:int,members:list<array>}>
     */
    public function plan(?array $only = null): array
    {
        $docker = Docker::list();
        if ($only) {
            foreach ($only as $n) if (!isset($docker[$n])) throw new \RuntimeException("Container $n nicht gefunden");
        }
        $groups = [];
        foreach ($docker as $name => $info) {
            $s = $this->cfg->containerSettings($name, $info);
            if ($only !== null && $only !== [] ) {
                if (!in_array($name, $only, true)) continue;
            } elseif (!$s['enabled']) {
                continue;
            }
            if ($s['source'] !== 'config') Log::info("Neuer Container $name (noch nicht in der Konfiguration) – Voreinstellung: " . ($s['enabled'] ? 'sichern' : 'nicht sichern') . " [{$s['source']}]");
            $warn = []; $paths = [];
            $sources = array_merge($this->appdataSources($info), array_map('strval', (array)$s['extra_paths']));
            foreach ($sources as $src) {
                $r = Storage::resolve($src, $this->cfg);
                $paths = array_merge($paths, $r['paths']);
                $warn = array_merge($warn, $r['warnings']);
            }
            $skip = [];
            foreach ((array)$s['skip_paths'] as $sp) $skip = array_merge($skip, Storage::resolve((string)$sp, $this->cfg)['paths'], [(string)$sp]);
            $paths = array_values(array_filter(Storage::dedupe($paths), function ($p) use ($skip) {
                foreach ($skip as $k) if (Fs::within($p, $k)) return false;
                return true;
            }));
            $ex = self::buildExcludes(array_merge((array)$this->cfg->get('global_excludes', []), (array)$s['excludes']), array_filter($paths, 'is_dir'));
            $member = [
                'name' => $name, 'running' => $info['running'], 'stop' => (bool)$s['stop'], 'order' => (int)$s['order'],
                'paths' => $paths, 'excludes' => $ex['args'], 'anchored' => $ex['anchored'], 'warnings' => $warn,
                'settings' => $s, 'info' => $info,
            ];
            $g = (string)$s['group'] !== '' ? 'group:' . $s['group'] : 'single:' . $name;
            $groups[$g]['members'][] = $member;
            $groups[$g]['order'] = min($groups[$g]['order'] ?? PHP_INT_MAX, (int)$s['order']);
        }
        foreach ($groups as &$g) usort($g['members'], fn($a, $b) => [$a['order'], strtolower($a['name'])] <=> [$b['order'], strtolower($b['name'])]);
        unset($g);
        uksort($groups, function ($a, $b) use ($groups) {
            return [$groups[$a]['order'], strtolower($a)] <=> [$groups[$b]['order'], strtolower($b)];
        });
        return $groups;
    }

    private function snapshotPlan(array $group): array
    {
        $all = [];
        foreach ($group['members'] as $m) $all = array_merge($all, $m['paths']);
        return Storage::plan(Storage::dedupe($all), $this->cfg);
    }

    public function describePlan(array $groups): string
    {
        $o = '';
        foreach ($groups as $gname => $g) {
            $o .= "== " . (str_starts_with($gname, 'group:') ? 'Gruppe ' . substr($gname, 6) : 'Einzeln') . " ==\n";
            if ($this->cfg->get('snapshot.mode') === 'auto') {
                $sp = $this->snapshotPlan($g);
                foreach ($sp['units'] as $u) $o .= "  Snapshot: " . $u->describe() . "\n";
                foreach ($sp['unsupported'] as $u) $o .= "  ! kein Snapshot möglich für $u (Dateisystem) – Sicherung im gestoppten Zustand\n";
            }
            foreach ($g['members'] as $m) {
                $o .= sprintf("  - %s [%s] stoppen=%s reihenfolge=%d\n", $m['name'], $m['running'] ? 'läuft' : 'gestoppt', $m['stop'] && $m['paths'] ? 'ja' : 'nein', $m['order']);
                foreach ($m['paths'] as $p) $o .= "      Pfad: $p\n";
                if (!$m['paths']) $o .= "      (keine Appdata-Pfade – nur Container-Konfiguration)\n";
                foreach ($m['excludes'] as $e) if (!in_array($e, self::ALWAYS_EXCLUDE, true)) $o .= "      Ausschluss: $e\n";
                foreach ($m['warnings'] as $w) $o .= "      ! $w\n";
            }
        }
        return $o === '' ? "Keine Container ausgewählt.\n" : $o;
    }

    private function writeMeta(array $m): string
    {
        $dir = Env::metaRoot() . '/' . $m['name'];
        Fs::rmTree($dir);
        mkdir($dir, 0700, true);
        $raw = Docker::inspectRaw($m['name']);
        if ($raw !== null) file_put_contents("$dir/docker-inspect.json", $raw);
        $tpl = Env::templatesDir() . '/my-' . $m['name'] . '.xml';
        if (is_file($tpl)) copy($tpl, "$dir/unraid-template.xml");
        $s = $m['settings']; unset($s['source']);
        file_put_contents("$dir/aib-container.json", json_encode([
            'container' => $m['name'], 'time' => date('c'), 'plugin_version' => VERSION, 'image' => $m['info']['image'],
            'paths' => $m['paths'], 'excludes' => $m['excludes'], 'anchored_excludes' => $m['anchored'], 'settings' => $s,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $dir;
    }

    private function backupMember(array $m, array $binds): void
    {
        $name = $m['name'];
        $t0 = microtime(true);
        State::update(['phase' => 'backup', 'current' => $name]);
        $meta = $this->writeMeta($m);
        $sub = ['backup', '--json', '--host', (string)$this->cfg->get('host'), '--tag', 'aib', '--tag', 'ct:' . $name];
        foreach ($m['excludes'] as $e) { $sub[] = '--exclude'; $sub[] = $e; }
        if ($this->dry) $sub[] = '--dry-run';
        $sub = array_merge($sub, $m['paths'], [$meta]);
        $mb = self::bindsFor($m['paths'], $binds);
        $summary = null; $lastLog = microtime(true);
        $onOut = function (string $line) use (&$summary, &$lastLog, $name) {
            $j = json_decode($line, true);
            if (!is_array($j)) { if (trim($line) !== '') Log::info("restic: $line"); return; }
            $t = $j['message_type'] ?? '';
            if ($t === 'summary') $summary = $j;
            elseif ($t === 'error') Log::warn("$name: " . ($j['error']['message'] ?? $line) . (isset($j['item']) ? " ({$j['item']})" : ''));
            elseif ($t === 'status') {
                static $lastState = 0.0;
                if (microtime(true) - $lastState > 3) {
                    $lastState = microtime(true);
                    State::update(['progress' => ['container' => $name, 'percent' => round(100 * ($j['percent_done'] ?? 0), 1),
                        'bytes_done' => $j['bytes_done'] ?? 0, 'total_bytes' => $j['total_bytes'] ?? 0,
                        'files_done' => $j['files_done'] ?? 0, 'total_files' => $j['total_files'] ?? 0]]);
                }
                if (microtime(true) - $lastLog < 60) return;
                $lastLog = microtime(true);
                Log::info(sprintf('%s: %.1f%% (%s von %s)', $name, 100 * ($j['percent_done'] ?? 0), Fs::human((float)($j['bytes_done'] ?? 0)), Fs::human((float)($j['total_bytes'] ?? 0))));
            }
        };
        Log::info("Sichere $name: " . implode(', ', $m['paths'] ?: ['(nur Konfiguration)']) . ($mb ? ' (aus Snapshot)' : ' (live)'));
        [$code] = $this->restic->run($sub, $onOut, true, $mb ?: null);
        $dur = microtime(true) - $t0;
        $res = ['status' => 'ok', 'duration' => round($dur), 'snapshot' => $summary['snapshot_id'] ?? null,
                'data_added' => $summary['data_added'] ?? null, 'files_new' => $summary['files_new'] ?? null,
                'files_changed' => $summary['files_changed'] ?? null, 'total_bytes' => $summary['total_bytes_processed'] ?? null];
        if ($code === 3) { $res['status'] = 'warning'; Log::warn("$name: Snapshot unvollständig – einige Dateien waren nicht lesbar"); }
        elseif ($code !== 0) { $res['status'] = 'error'; Log::error("$name: restic backup fehlgeschlagen (Exit $code)"); }
        if ($summary) {
            Log::info(sprintf('%s: fertig in %ds – neu %s, %d neue / %d geänderte Dateien, gelesen %s, Snapshot %s',
                $name, $dur, Fs::human((float)$summary['data_added']), $summary['files_new'] ?? 0, $summary['files_changed'] ?? 0,
                Fs::human((float)($summary['total_bytes_processed'] ?? 0)), substr((string)($summary['snapshot_id'] ?? '-'), 0, 8)));
        }
        $this->results[$name] = array_merge($this->results[$name] ?? [], $res);
    }

    /**
     * Bind mounts needed for $paths: the closest bind covering each path (same path or a parent
     * path from another group member) plus nested binds below it (ZFS child datasets).
     */
    public static function bindsFor(array $paths, array $binds): array
    {
        $out = [];
        foreach ($paths as $p) {
            $cover = null;
            foreach ($binds as $b) {
                if (Fs::within($p, $b[1]) && ($cover === null || strlen($b[1]) > strlen($cover[1]))) $cover = $b;
            }
            if ($cover === null) return []; // not covered → no consistent snapshot, caller backs up live
            $out[$cover[1]] = $cover;
            foreach ($binds as $b) if ($b[1] !== $p && Fs::within($b[1], $p)) $out[$b[1]] = $b;
        }
        $out = array_values($out);
        usort($out, fn($a, $b) => strlen($a[1]) <=> strlen($b[1]));
        return $out;
    }

    /** Start containers that an aborted earlier run left stopped. */
    public function recoverAbortedRun(): void
    {
        $left = (array)(State::read()['stopped_by_aib'] ?? []);
        foreach ($left as $n) {
            if (!Docker::isRunning($n)) {
                Log::warn("Container $n wurde von einem abgebrochenen Lauf gestoppt – wird wieder gestartet");
                Docker::start($n);
            }
        }
        State::update(['stopped_by_aib' => []]);
    }

    public function run(?array $only = null): bool
    {
        $this->recoverAbortedRun();
        if (!$this->dry) Storage::cleanupStale($this->cfg);
        $groups = $this->plan($only);
        Log::info("Plan:\n" . rtrim($this->describePlan($groups)));
        $useSnap = $this->cfg->get('snapshot.mode') === 'auto' && !$this->dry;
        $timeout = (int)$this->cfg->get('stop_timeout', 60);
        foreach ($groups as $gname => $g) {
            $stopped = []; $units = [];
            $sp = $useSnap ? $this->snapshotPlan($g) : ['units' => [], 'binds' => [], 'unsupported' => []];
            $groupSnap = $useSnap && !$sp['unsupported'];
            if ($useSnap && $sp['unsupported']) Log::warn("$gname: kein Snapshot möglich für " . implode(', ', $sp['unsupported']) . ' – Sicherung im gestoppten Zustand');
            try {
                $t0 = null;
                $skip = [];
                foreach ($g['members'] as $m) {
                    $this->results[$m['name']] = ['status' => 'pending', 'downtime' => 0];
                    foreach ($m['warnings'] as $w) Log::warn("{$m['name']}: $w");
                    // containers without appdata paths only get their configuration saved – never stop them
                    if ($this->dry || !$m['stop'] || !$m['paths'] || !Docker::isRunning($m['name'])) continue;
                    $t0 ??= microtime(true);
                    State::update(['phase' => 'stop', 'current' => $m['name']]);
                    Log::info("Stoppe {$m['name']}");
                    if (Docker::stop($m['name'], $timeout)) {
                        $stopped[] = $m['name'];
                        State::update(['stopped_by_aib' => $stopped]);
                    } else {
                        $skip[$m['name']] = true; // never back up a container that should be stopped but is not
                    }
                }
                $snapOk = false;
                if ($groupSnap) {
                    State::update(['phase' => 'snapshot', 'current' => $gname]);
                    $snapOk = true;
                    foreach ($sp['units'] as $u) {
                        Log::info('Erstelle ' . $u->describe());
                        if (!Storage::create($u)) { $snapOk = false; break; }
                        $units[] = $u;
                    }
                    foreach ($stopped as $n) if (Docker::isRunning($n)) Log::warn("$n lief beim Snapshot schon wieder (von außen gestartet?)");
                    if (!$snapOk) { foreach ($units as $u) Storage::destroy($u); $units = []; }
                }
                if ($snapOk) {
                    $this->startAll($stopped, $t0);
                    $stopped = [];
                } elseif ($groupSnap && $this->cfg->get('snapshot.on_failure') === 'skip') {
                    $this->startAll($stopped, $t0);
                    $stopped = [];
                    foreach ($g['members'] as $m) { $this->results[$m['name']]['status'] = 'error'; Log::error("{$m['name']}: übersprungen, weil der Snapshot fehlschlug"); }
                    continue;
                } elseif ($stopped) {
                    Log::info('Sichere im gestoppten Zustand: ' . implode(', ', $stopped));
                }
                foreach ($g['members'] as $m) {
                    if (isset($skip[$m['name']])) { $this->results[$m['name']]['status'] = 'error'; Log::error("{$m['name']}: nicht gesichert, weil er sich nicht stoppen ließ"); continue; }
                    $this->backupMember($m, $snapOk ? $sp['binds'] : []);
                }
            } finally {
                if ($stopped) $this->startAll($stopped, $t0);
                foreach ($units as $u) Storage::destroy($u);
            }
        }
        $ok = true;
        foreach ($this->results as $r) if ($r['status'] === 'error') $ok = false;
        return $ok;
    }

    private function startAll(array $names, ?float $t0): void
    {
        foreach (array_reverse($names) as $n) {
            Log::info("Starte $n");
            if (!Docker::start($n)) continue;
            if ($t0 !== null) {
                $down = (int)round(microtime(true) - $t0);
                $this->results[$n]['downtime'] = $down;
                Log::info("$n war {$down}s gestoppt");
            }
        }
        $left = array_values(array_diff((array)(State::read()['stopped_by_aib'] ?? []), $names));
        State::update(['stopped_by_aib' => $left]);
    }

    /** Apply retention per container (forget without prune). */
    public function forget(): bool
    {
        $snaps = $this->restic->snapshots();
        $names = array_unique(array_filter(array_map([Restic::class, 'containerOf'], $snaps)));
        $ok = true;
        foreach ($names as $n) {
            $override = $this->cfg->data['containers'][$n]['retention'] ?? null;
            $args = $this->restic->retentionArgs(is_array($override) ? $override : null);
            if (!$args) { Log::info("$n: keine Aufbewahrungsregel – alle Snapshots bleiben"); continue; }
            Log::info("Aufbewahrung für $n: " . implode(' ', $args));
            [$c] = $this->restic->run(array_merge(['forget', '--tag', "aib,ct:$n", '--host', (string)$this->cfg->get('host'), '--group-by', 'host,tags'], $args));
            if ($c !== 0) { $ok = false; Log::error("forget für $n fehlgeschlagen (Exit $c)"); }
        }
        return $ok;
    }
}
