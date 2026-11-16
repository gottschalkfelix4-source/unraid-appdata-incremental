<?php
declare(strict_types=1);

namespace Aib;

/**
 * Backend of the WebGUI. Every action validates its input; commands are run without a shell.
 * Secrets are never returned – except the freshly generated repository password (one-time display).
 */
final class Api
{
    private Config $cfg;

    public function __construct() { $this->cfg = Config::load(); }

    public function handle(string $action, array $p): array
    {
        Log::$echo = false;
        $m = 'a_' . $action;
        if (!preg_match('/^[a-z_]{2,40}$/', $action) || !method_exists($this, $m)) throw new \InvalidArgumentException('Unbekannte Aktion');
        return $this->$m($p);
    }

    private static function bool($v): bool { return $v === true || $v === 1 || in_array((string)$v, ['1', 'true', 'yes', 'on'], true); }

    private static function jsonParam(array $p, string $k): array
    {
        $v = $p[$k] ?? '';
        if (is_array($v)) return $v;
        $d = json_decode((string)$v, true);
        if (!is_array($d)) throw new \InvalidArgumentException("$k: ungültiges JSON");
        return $d;
    }

    private function restic(): Restic { return new Restic($this->cfg); }

    private function requireReady(): void
    {
        if ($p = $this->restic()->checkReady()) throw new \RuntimeException(implode('; ', $p));
    }

    // ---------------------------------------------------------------- status & logs

    private function a_state(array $p): array
    {
        $s = State::read();
        $run = Jobs::running();
        $s['running'] = (bool)$run;
        if (!$run) { unset($s['progress']); $s['phase'] = 'idle'; }
        unset($s['stopped_by_aib']);
        $s['configured'] = !$this->restic()->checkReady();
        $s['schedule_active'] = is_file(Schedule::cronFile());
        $s['schedule'] = (string)$this->cfg->get('schedule.backup');
        $next = $s['schedule_active'] ? Schedule::nextRun($s['schedule']) : null;
        $s['next_run'] = $next ? date('c', $next) : null;
        $s['now'] = date('c');
        if (!$run) unset($s['run_total'], $s['run_done']);
        $hist = (array)($s['history'] ?? []);
        if (!$hist && !empty($s['last'])) { $l = $s['last']; $l['log'] = !empty($l['log']) ? basename((string)$l['log']) : null; $hist = [$l]; }
        $s['history'] = array_slice($hist, 0, max(1, min(100, (int)($p['limit'] ?? 30))));
        return $s;
    }

    /** Repository size and free space at the target (cached; refresh=1 re-reads). */
    private function a_storage(array $p): array
    {
        $refresh = self::bool($p['refresh'] ?? false);
        $stats = State::read()['repo_stats'] ?? null;
        if (($refresh || !$stats) && !Jobs::running() && !$this->restic()->checkReady()) $stats = RepoStats::refresh($this->cfg) ?? $stats;
        return ['repo' => $stats, 'target' => $this->restic()->checkReady() ? null : RepoStats::targetSpace($this->cfg, $refresh)];
    }

    private function a_log_tail(array $p): array
    {
        $link = Env::logDir() . '/latest.log';
        $file = is_link($link) ? basename((string)readlink($link)) : '';
        $path = $file !== '' ? Env::logDir() . '/' . $file : '';
        if ($path === '' || !is_file($path)) return ['file' => '', 'offset' => 0, 'data' => ''];
        $size = filesize($path);
        $off = (int)($p['offset'] ?? 0);
        if (($p['file'] ?? '') !== $file || $off > $size) $off = max(0, $size - 65536);
        $fh = fopen($path, 'rb'); fseek($fh, $off);
        $data = (string)fread($fh, min(262144, $size - $off));
        fclose($fh);
        return ['file' => $file, 'offset' => $off + strlen($data), 'data' => $data];
    }

    private function logDirs(): array { return [Env::logDir(), Env::cfgDir() . '/logs']; }

    private function a_logs(array $p): array
    {
        $all = [];
        foreach ($this->logDirs() as $d) {
            foreach (glob("$d/*.log") ?: [] as $f) {
                $b = basename($f);
                if ($b === 'latest.log' || is_link($f)) continue;
                if (!isset($all[$b]) || filesize($f) > $all[$b]['size']) $all[$b] = ['name' => $b, 'size' => filesize($f), 'mtime' => filemtime($f)];
            }
        }
        uasort($all, fn($a, $b) => [$b['mtime'], $b['name']] <=> [$a['mtime'], $a['name']]);
        return ['logs' => array_values($all)];
    }

    public function logPath(string $name): string
    {
        if (!preg_match('/^[a-z]+-\d{8}-\d{6}\.log$/', $name)) throw new \InvalidArgumentException('Ungültiger Log-Name');
        foreach ($this->logDirs() as $d) if (is_file("$d/$name")) return "$d/$name";
        throw new \RuntimeException('Log nicht gefunden');
    }

    private function a_log(array $p): array
    {
        $f = $this->logPath((string)($p['name'] ?? ''));
        $size = filesize($f);
        $fh = fopen($f, 'rb');
        if ($size > 2097152) fseek($fh, $size - 2097152);
        $d = (string)stream_get_contents($fh);
        fclose($fh);
        return ['name' => basename($f), 'data' => $d, 'truncated' => $size > 2097152];
    }

    // ---------------------------------------------------------------- containers

    public static function iconUrl(string $name, string $label): string
    {
        $state = Env::get('AIB_STATE_ICONS', '/usr/local/emhttp/state/plugins/dynamix.docker.manager/images');
        if (is_file("$state/$name-icon.png")) return '/state/plugins/dynamix.docker.manager/images/' . rawurlencode($name) . '-icon.png';
        if (is_file(self::cacheIcon($name))) return '/plugins/' . PLUGIN . '/include/api.php?action=icon&name=' . rawurlencode($name);
        if (preg_match('#^https?://[^\s"\'<>]+$#', $label)) return $label;
        return '';
    }

    public static function cacheIcon(string $name): string
    {
        return Env::get('AIB_DOCKER_ICONS', '/var/lib/docker/unraid/images') . "/$name-icon.png";
    }

    private function a_containers(array $p): array
    {
        $out = [];
        foreach (Docker::list() as $n => $info) {
            $s = $this->cfg->containerSettings($n, $info);
            $paths = []; $warn = []; $sources = [];
            foreach ($info['mounts'] as $m) {
                if (!$this->cfg->isAppdataPath($m['source'])) continue;
                $sources[] = $m['source'];
                $r = Storage::resolve($m['source'], $this->cfg);
                $paths = array_merge($paths, $r['paths']);
                $warn = array_merge($warn, $r['warnings']);
            }
            foreach ((array)$s['extra_paths'] as $x) $paths = array_merge($paths, Storage::resolve((string)$x, $this->cfg)['paths']);
            $out[] = [
                'name' => $n, 'running' => $info['running'], 'image' => $info['image'], 'icon' => self::iconUrl($n, $info['icon_label']),
                'enabled' => (bool)$s['enabled'], 'stop' => (bool)$s['stop'], 'group' => (string)$s['group'], 'order' => (int)$s['order'],
                'excludes' => array_values((array)$s['excludes']), 'extra_paths' => array_values((array)$s['extra_paths']),
                'note' => (string)$s['note'], 'source' => $s['source'], 'sources' => array_values(array_unique($sources)),
                'paths' => Storage::dedupe($paths), 'warnings' => $warn,
            ];
        }
        return ['containers' => $out, 'templates' => Templates::all()];
    }

    private function a_save_containers(array $p): array
    {
        $in = self::jsonParam($p, 'containers');
        $known = Docker::list();
        foreach ($in as $name => $c) {
            $name = V::containerName((string)$name);
            if (!isset($known[$name]) && !$this->cfg->hasContainer($name)) throw new \InvalidArgumentException("Container $name unbekannt");
            if (!is_array($c)) throw new \InvalidArgumentException("$name: ungültig");
            $group = V::text((string)($c['group'] ?? ''), 40);
            if ($group !== '' && !preg_match('/^[A-Za-z0-9 _.-]+$/', $group)) throw new \InvalidArgumentException("$name: Gruppe nur aus Buchstaben, Ziffern, Leerzeichen, _ . -");
            $prev = $this->cfg->data['containers'][$name] ?? Config::containerDefaults();
            $this->cfg->data['containers'][$name] = array_merge($prev, [
                'enabled' => self::bool($c['enabled'] ?? false),
                'stop' => self::bool($c['stop'] ?? false),
                'group' => $group,
                'order' => max(0, min(9999, (int)($c['order'] ?? 100))),
                'excludes' => array_values(array_unique(array_map(fn($x) => V::relPath((string)$x), (array)($c['excludes'] ?? [])))),
                'extra_paths' => array_values(array_unique(array_map(fn($x) => V::absPath((string)$x, 'Zusatzpfad'), (array)($c['extra_paths'] ?? [])))),
                'note' => V::text((string)($c['note'] ?? ''), 200),
            ]);
        }
        if ($e = $this->cfg->validate()) throw new \InvalidArgumentException(implode('; ', $e));
        $this->cfg->save();
        return ['saved' => count($in)];
    }

    /** Sub folders of a container's appdata (for choosing exclusions). */
    private function a_browse(array $p): array
    {
        $name = V::containerName((string)($p['container'] ?? ''));
        $rel = trim((string)($p['rel'] ?? ''), '/');
        if ($rel !== '') V::relPath($rel);
        $known = Docker::list();
        if (!isset($known[$name])) throw new \InvalidArgumentException('Container unbekannt');
        $bases = [];
        foreach ($known[$name]['mounts'] as $m) {
            if ($this->cfg->isAppdataPath($m['source'])) $bases = array_merge($bases, Storage::resolve($m['source'], $this->cfg)['paths']);
        }
        $entries = [];
        foreach (Storage::dedupe($bases) as $b) {
            $dir = rtrim($b, '/') . ($rel !== '' ? "/$rel" : '');
            if (!is_dir($dir) || is_link($dir)) continue;
            $n = 0;
            foreach (scandir($dir) ?: [] as $e) {
                if ($e === '.' || $e === '..' || str_starts_with($e, '.aib-')) continue;
                if (++$n > 1000) break;
                $isDir = is_dir("$dir/$e") && !is_link("$dir/$e");
                $entries[$e] = ['name' => $e, 'dir' => $isDir || ($entries[$e]['dir'] ?? false)];
            }
        }
        uasort($entries, fn($a, $b) => [!$a['dir'], strtolower($a['name'])] <=> [!$b['dir'], strtolower($b['name'])]);
        return ['rel' => $rel, 'entries' => array_values($entries)];
    }

    // ---------------------------------------------------------------- settings

    private function a_settings(array $p): array
    {
        $d = $this->cfg->data;
        unset($d['containers'], $d['password_file'], $d['ssh']['key_file'], $d['ssh']['known_hosts']);
        $d['repository_effective'] = Target::repository($this->cfg);
        $d['key'] = Secrets::keyStatus($this->cfg);
        $d['password'] = Secrets::passwordStatus($this->cfg);
        $d['schedule_active'] = is_file(Schedule::cronFile());
        $d['templates'] = Templates::all();
        $d['config_exists'] = is_file(Config::file());
        return $d;
    }

    private function a_save_target(array $p): array
    {
        $t = self::jsonParam($p, 'target');
        $type = (string)($t['type'] ?? '');
        $new = ['type' => $type, 'host' => '', 'user' => '', 'port' => 23, 'path' => 'restic-appdata', 'local_path' => '', 'custom' => ''];
        switch ($type) {
            case 'sftp':
                $new['host'] = V::host((string)($t['host'] ?? ''));
                $new['user'] = V::user((string)($t['user'] ?? ''));
                $new['port'] = V::port($t['port'] ?? 23);
                $new['path'] = V::repoPath((string)($t['path'] ?? 'restic-appdata'));
                break;
            case 'local':
                $new['local_path'] = V::absPath((string)($t['local_path'] ?? ''), 'Lokaler Pfad');
                if (!str_starts_with($new['local_path'], rtrim(Env::mntRoot(), '/') . '/')) throw new \InvalidArgumentException('Lokaler Pfad muss unter /mnt/ liegen');
                break;
            case 'custom':
                $c = trim((string)($t['custom'] ?? ''));
                if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9+.:/@_=~-]{2,300}$#', $c)) throw new \InvalidArgumentException('Ungültige Repository-Angabe');
                $new['custom'] = $c;
                break;
            default:
                throw new \InvalidArgumentException('Ziel-Typ wählen');
        }
        $this->cfg->data['target'] = $new;
        $this->cfg->save();
        return ['repository' => Target::repository($this->cfg)];
    }

    private function a_save_settings(array $p): array
    {
        $s = self::jsonParam($p, 'settings');
        $c = &$this->cfg->data;
        if (isset($s['schedule'])) {
            $sc = (array)$s['schedule'];
            $c['schedule']['enabled'] = self::bool($sc['enabled'] ?? false);
            foreach (['frequency', 'hour', 'minute', 'weekday', 'monthday'] as $k) if (isset($sc[$k])) $c['schedule'][$k] = $k === 'frequency' ? (string)$sc[$k] : (int)$sc[$k];
            if (!in_array($c['schedule']['frequency'], ['hourly', 'daily', 'weekly', 'monthly', 'custom'], true)) throw new \InvalidArgumentException('Ungültige Häufigkeit');
            $c['schedule']['backup'] = Schedule::fromFields(array_merge($c['schedule'], ['custom' => (string)($sc['custom'] ?? $c['schedule']['backup'])]));
            $c['schedule']['prune_weekday'] = max(-1, min(6, (int)($sc['prune_weekday'] ?? $c['schedule']['prune_weekday'])));
            $c['schedule']['check_day'] = max(0, min(28, (int)($sc['check_day'] ?? $c['schedule']['check_day'])));
            $sub = (string)($sc['check_subset'] ?? $c['schedule']['check_subset']);
            if (!preg_match('/^(100|[1-9]?[0-9])(\.[0-9]+)?%$/', $sub)) throw new \InvalidArgumentException('Prüfumfang z. B. 2%');
            $c['schedule']['check_subset'] = $sub;
        }
        if (isset($s['retention'])) {
            foreach (['keep_last', 'keep_daily', 'keep_weekly', 'keep_monthly', 'keep_yearly'] as $k) $c['retention'][$k] = max(0, min(9999, (int)($s['retention'][$k] ?? 0)));
            $w = trim((string)($s['retention']['keep_within'] ?? ''));
            if ($w !== '' && !preg_match('/^(\d+[ymdh])+$/', $w)) throw new \InvalidArgumentException('„Alles behalten innerhalb“ z. B. 14d oder 1m');
            $c['retention']['keep_within'] = $w;
            if (!array_filter($c['retention'], fn($v) => $v !== 0 && $v !== '')) throw new \InvalidArgumentException('Mindestens eine Aufbewahrungsregel setzen');
        }
        if (isset($s['notify'])) foreach (['on_error', 'on_warning', 'on_success'] as $k) $c['notify'][$k] = self::bool($s['notify'][$k] ?? false);
        if (isset($s['global_excludes'])) $c['global_excludes'] = array_values(array_unique(array_map(fn($x) => V::relPath((string)$x), (array)$s['global_excludes'])));
        if (isset($s['snapshot'])) {
            $mode = (string)($s['snapshot']['mode'] ?? 'auto');
            $of = (string)($s['snapshot']['on_failure'] ?? 'backup_stopped');
            if (!in_array($mode, ['auto', 'none'], true) || !in_array($of, ['backup_stopped', 'skip'], true)) throw new \InvalidArgumentException('Ungültige Snapshot-Einstellung');
            $c['snapshot']['mode'] = $mode; $c['snapshot']['on_failure'] = $of;
        }
        if (isset($s['setup_completed'])) $c['setup_completed'] = self::bool($s['setup_completed']);
        if (isset($s['stop_timeout'])) $c['stop_timeout'] = max(5, min(600, (int)$s['stop_timeout']));
        if (isset($s['compression'])) {
            if (!in_array($s['compression'], ['auto', 'max', 'off'], true)) throw new \InvalidArgumentException('Ungültige Kompression');
            $c['compression'] = $s['compression'];
        }
        if (isset($s['new_containers'])) foreach (['enabled', 'stop'] as $k) $c['new_containers'][$k] = self::bool($s['new_containers'][$k] ?? false);
        if (isset($s['rules'])) {
            $presets = []; $disable = [];
            foreach ((array)$s['rules'] as $r) {
                $pat = trim((string)($r['match_path'] ?? ''));
                if ($pat === '') continue;
                if (!preg_match('#^[A-Za-z0-9*?/_. -]{1,200}$#', $pat)) throw new \InvalidArgumentException("Ungültiges Muster: $pat");
                if (self::bool($r['disable'] ?? false)) { $disable[] = $pat; continue; }
                $presets[] = ['match_path' => $pat, 'excludes' => array_values(array_map(fn($x) => V::relPath((string)$x), (array)($r['excludes'] ?? []))),
                              'stop' => self::bool($r['stop'] ?? true), 'note' => V::text((string)($r['note'] ?? ''), 200)];
            }
            $c['presets'] = $presets; $c['auto_disable_mount_patterns'] = $disable;
        }
        unset($c);
        if ($e = $this->cfg->validate()) throw new \InvalidArgumentException(implode('; ', $e));
        $this->cfg->save();
        return ['schedule' => Schedule::apply($this->cfg), 'cron' => $this->cfg->get('schedule.backup')];
    }

    private const IMPORT_KEYS = ['target', 'repository', 'ssh', 'presets', 'auto_disable_mount_patterns', 'global_excludes', 'retention', 'schedule',
        'notify', 'containers', 'new_containers', 'snapshot', 'stop_timeout', 'compression', 'host', 'appdata_roots', 'log_keep'];

    private function a_import_config(array $p): array
    {
        $in = json_decode((string)($p['json'] ?? ''), true);
        if (!is_array($in)) throw new \InvalidArgumentException('Keine gültige JSON-Datei');
        $unknown = array_diff(array_keys($in), self::IMPORT_KEYS);
        foreach ($unknown as $k) unset($in[$k]);
        if (isset($in['ssh'])) $in['ssh'] = array_intersect_key((array)$in['ssh'], ['port' => 1]); // key paths stay local
        foreach (['presets', 'auto_disable_mount_patterns', 'global_excludes', 'containers'] as $k) if (isset($in[$k]) && !is_array($in[$k])) throw new \InvalidArgumentException("$k muss eine Liste/ein Objekt sein");
        $merged = new Config(array_replace_recursive($this->cfg->data, array_diff_key($in, ['presets' => 1, 'auto_disable_mount_patterns' => 1, 'global_excludes' => 1, 'appdata_roots' => 1])));
        foreach (['presets', 'auto_disable_mount_patterns', 'global_excludes', 'appdata_roots'] as $k) if (isset($in[$k])) $merged->data[$k] = array_values($in[$k]);
        if ($e = $merged->validate()) throw new \InvalidArgumentException(implode('; ', $e));
        $this->cfg = $merged;
        $added = $this->cfg->sync(Docker::list());
        $this->cfg->save();
        Schedule::apply($this->cfg);
        return ['imported' => array_values(array_intersect(array_keys($in), self::IMPORT_KEYS)), 'ignored' => array_values($unknown), 'new_containers' => $added];
    }

    private function a_export_config(array $p): array
    {
        $d = $this->cfg->data;
        unset($d['password_file'], $d['ssh']['key_file'], $d['ssh']['known_hosts'], $d['restic_bin'], $d['restic_cache_dir']);
        return ['json' => json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    private function a_notify_test(array $p): array
    {
        Notify::send('normal', 'Appdata Incremental: Test', 'Benachrichtigungen funktionieren.');
        return ['sent' => true];
    }

    // ---------------------------------------------------------------- secrets & target

    private function a_key_generate(array $p): array { return Secrets::generateKey($this->cfg, self::bool($p['force'] ?? false)); }

    private function a_key_install(array $p): array
    {
        $mode = (string)($p['mode'] ?? 'storagebox');
        if (!in_array($mode, ['storagebox', 'standard'], true)) throw new \InvalidArgumentException('Ungültige Methode');
        $msg = Secrets::installKey($this->cfg, (string)($p['password'] ?? ''), $mode);
        return ['message' => $msg];
    }

    private function a_password_generate(array $p): array
    {
        $pw = Secrets::generatePassword();
        Secrets::setPassword($this->cfg, $pw, self::bool($p['force'] ?? false));
        return ['password' => $pw];
    }

    private function a_password_set(array $p): array
    {
        Secrets::setPassword($this->cfg, (string)($p['password'] ?? ''), self::bool($p['force'] ?? false));
        return Secrets::passwordStatus($this->cfg);
    }

    /** Key-based login check for SFTP targets (no repository password needed yet). */
    private function a_test_login(array $p): array
    {
        if (($this->cfg->get('target.type') ?? '') !== 'sftp') return ['ok' => true, 'message' => 'Kein SSH-Ziel.'];
        $host = V::host((string)$this->cfg->get('target.host'));
        $user = V::user((string)$this->cfg->get('target.user'));
        $port = V::port($this->cfg->get('target.port'));
        $key = (string)$this->cfg->get('ssh.key_file');
        if (!is_file($key)) return ['ok' => false, 'message' => 'Noch kein Schlüssel erzeugt.'];
        if (!is_dir(Env::tmpDir())) mkdir(Env::tmpDir(), 0700, true);
        $batch = Env::tmpDir() . '/sftp-test.batch';
        file_put_contents($batch, "pwd\n");
        [$c, $o, $e] = Cmd::run([Env::bin('sftp'), '-b', $batch, '-P', (string)$port, '-i', $this->restic()->privateKeyCopy($key),
            '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'UserKnownHostsFile=' . $this->cfg->get('ssh.known_hosts'), '-o', 'ConnectTimeout=20', "$user@$host"]);
        if ($c === 0) return ['ok' => true, 'message' => 'Anmeldung mit Schlüssel funktioniert.'];
        $err = trim($e);
        if (stripos($err, 'Permission denied') !== false) return ['ok' => false, 'message' => 'Anmeldung mit Schlüssel klappt noch nicht – Schlüssel noch nicht hinterlegt oder SSH auf dem Server nicht aktiviert.'];
        if (preg_match('/Could not resolve|Name or service not known/i', $err)) return ['ok' => false, 'message' => 'Server nicht gefunden – Hostname prüfen.'];
        if (preg_match('/timed out|Connection refused|No route/i', $err)) return ['ok' => false, 'message' => 'Server nicht erreichbar – Port und SSH-Freigabe prüfen.'];
        return ['ok' => false, 'message' => 'Anmeldung fehlgeschlagen: ' . mb_substr($err, 0, 300)];
    }

    private function a_test_connection(array $p): array
    {
        $this->requireReady();
        [$c, $o, $e] = $this->restic()->run(['cat', 'config', '--no-lock'], null, false);
        if ($c === 0) return ['ok' => true, 'initialized' => true, 'message' => 'Verbindung OK – Repository vorhanden und Passwort passt.'];
        $err = trim($e);
        if ($c === 10 || preg_match('/repository does not exist|Is there a repository|unable to open config file|no such file/i', $err))
            return ['ok' => true, 'initialized' => false, 'message' => 'Verbindung OK – hier gibt es noch kein Repository. Jetzt „Repository initialisieren“.'];
        if ($c === 12 || stripos($err, 'wrong password') !== false) return ['ok' => false, 'message' => 'Repository vorhanden, aber das Passwort passt nicht.'];
        if (stripos($err, 'Permission denied') !== false) return ['ok' => false, 'message' => 'Anmeldung abgelehnt – SSH-Schlüssel noch nicht hinterlegt?'];
        return ['ok' => false, 'message' => 'Keine Verbindung: ' . mb_substr($err, 0, 400)];
    }

    private function a_init_repo(array $p): array
    {
        $this->requireReady();
        if (Jobs::running()) throw new \RuntimeException('Es läuft bereits ein Vorgang');
        [$c, $o, $e] = $this->restic()->run(['init'], null, false);
        if ($c !== 0) throw new \RuntimeException('Initialisieren fehlgeschlagen: ' . mb_substr(trim($e), 0, 400));
        return ['message' => 'Repository angelegt.'];
    }

    // ---------------------------------------------------------------- actions

    private function a_run(array $p): array
    {
        $kind = (string)($p['kind'] ?? '');
        $cts = array_map(fn($n) => V::containerName((string)$n), (array)self::jsonParamOr($p, 'containers', []));
        $args = match ($kind) {
            'backup' => ['backup', '--quiet'],
            'dryrun' => ['backup', '--dry-run', '--quiet'],
            'forget', 'prune', 'check', 'unlock' => [$kind, '--quiet'],
            default => throw new \InvalidArgumentException('Unbekannter Vorgang'),
        };
        $this->requireReady();
        if (in_array($kind, ['backup', 'dryrun'], true)) foreach ($cts as $n) { $args[] = '--container'; $args[] = $n; }
        Jobs::start($args);
        return ['started' => $kind];
    }

    private static function jsonParamOr(array $p, string $k, array $def): array
    {
        if (!isset($p[$k]) || $p[$k] === '') return $def;
        return self::jsonParam($p, $k);
    }

    private function a_cancel(array $p): array { return ['message' => Jobs::cancel($this->cfg)]; }

    private function a_doctor(array $p): array
    {
        $plan = '';
        try { $b = new Backup($this->cfg, $this->restic()); $plan = $b->describePlan($b->plan()); }
        catch (\Throwable $e) { $plan = 'Plan nicht möglich: ' . $e->getMessage(); }
        return ['checks' => Doctor::run($this->cfg), 'plan' => $plan];
    }

    private function a_plan(array $p): array
    {
        $cts = array_map(fn($n) => V::containerName((string)$n), self::jsonParamOr($p, 'containers', []));
        $b = new Backup($this->cfg, $this->restic());
        return ['plan' => $b->describePlan($b->plan($cts ?: null))];
    }

    private function a_selftest(array $p): array
    {
        $path = V::absPath((string)($p['path'] ?? ''));
        if (!$this->cfg->isAppdataPath($path)) throw new \InvalidArgumentException('Pfad muss in einem Appdata-Ordner liegen');
        if (Jobs::running()) throw new \RuntimeException('Es läuft bereits ein Vorgang');
        return Doctor::selftest($this->cfg, $path);
    }

    // ---------------------------------------------------------------- history & restore

    private function snapshotsCached(bool $refresh): array
    {
        $f = Env::tmpDir() . '/snapshots.json';
        $repo = md5(Target::repository($this->cfg));
        if (!$refresh && is_file($f) && time() - filemtime($f) < 30) {
            $d = json_decode((string)file_get_contents($f), true);
            if (is_array($d) && ($d['repo'] ?? '') === $repo && ($d['last'] ?? null) === (State::read()['last']['finished'] ?? null)) return $d['list'];
        }
        $list = $this->restic()->snapshots();
        if (!is_dir(Env::tmpDir())) mkdir(Env::tmpDir(), 0700, true);
        file_put_contents($f, json_encode(['repo' => $repo, 'last' => State::read()['last']['finished'] ?? null, 'list' => $list]));
        return $list;
    }

    private function a_snapshots(array $p): array
    {
        $this->requireReady();
        $out = [];
        foreach ($this->snapshotsCached(self::bool($p['refresh'] ?? false)) as $s) {
            $sum = $s['summary'] ?? [];
            $out[] = ['id' => $s['id'], 'short_id' => $s['short_id'] ?? substr($s['id'], 0, 8), 'time' => $s['time'],
                      'container' => Restic::containerOf($s), 'paths' => array_values(array_filter((array)$s['paths'], fn($x) => !Fs::within($x, Env::metaRoot()))),
                      'total_bytes' => $sum['total_bytes_processed'] ?? null, 'data_added' => $sum['data_added'] ?? null,
                      'files_new' => $sum['files_new'] ?? null, 'files_changed' => $sum['files_changed'] ?? null,
                      'files_unmodified' => $sum['files_unmodified'] ?? null, 'total_files' => $sum['total_files_processed'] ?? null,
                      'duration' => isset($sum['backup_start'], $sum['backup_end']) ? max(0, strtotime($sum['backup_end']) - strtotime($sum['backup_start'])) : null];
        }
        return ['snapshots' => array_reverse($out)];
    }

    private function a_ls(array $p): array
    {
        $this->requireReady();
        $id = V::snapshotId((string)($p['id'] ?? ''));
        $path = V::absPath((string)($p['path'] ?? '/'));
        [$c, $o, $e] = $this->restic()->run(['ls', '--json', '--no-lock', $id, $path], null, false);
        if ($c !== 0) throw new \RuntimeException('Inhalt nicht lesbar: ' . mb_substr(trim($e), 0, 300));
        $entries = [];
        foreach (explode("\n", $o) as $l) {
            $j = json_decode($l, true);
            if (!is_array($j) || ($j['struct_type'] ?? '') !== 'node' || !isset($j['path'])) continue;
            if ($j['path'] === $path || dirname($j['path']) !== $path) continue;
            if (Fs::within($j['path'], Env::metaRoot())) continue;
            $entries[] = ['name' => $j['name'], 'path' => $j['path'], 'type' => $j['type'], 'size' => $j['size'] ?? null, 'mtime' => $j['mtime'] ?? null];
            if (count($entries) >= 5000) break;
        }
        usort($entries, fn($a, $b) => [$a['type'] !== 'dir', strtolower($a['name'])] <=> [$b['type'] !== 'dir', strtolower($b['name'])]);
        return ['path' => $path, 'entries' => $entries];
    }

    private function a_diff(array $p): array
    {
        $this->requireReady();
        $a = V::snapshotId((string)($p['a'] ?? '')); $b = V::snapshotId((string)($p['b'] ?? ''));
        [$c, $o, $e] = $this->restic()->run(['diff', '--json', '--no-lock', $a, $b], null, false);
        if ($c !== 0) throw new \RuntimeException('Vergleich fehlgeschlagen: ' . mb_substr(trim($e), 0, 300));
        $changes = []; $stats = null; $n = 0; $counts = [];
        foreach (explode("\n", $o) as $l) {
            $j = json_decode($l, true);
            if (!is_array($j)) continue;
            if (($j['message_type'] ?? '') === 'statistics') $stats = $j;
            elseif (($j['message_type'] ?? '') === 'change') {
                if (Fs::within($j['path'], Env::metaRoot())) continue; // container configuration, not data
                $n++;
                $m = $j['modifier'][0] ?? '?';
                $counts[$m] = ($counts[$m] ?? 0) + 1;
                if (count($changes) < 500) $changes[] = ['path' => $j['path'], 'modifier' => $j['modifier']];
            }
        }
        return ['stats' => $stats, 'changes' => $changes, 'total' => $n,
                'added' => $counts['+'] ?? 0, 'removed' => $counts['-'] ?? 0, 'modified' => ($counts['M'] ?? 0) + ($counts['T'] ?? 0) + ($counts['U'] ?? 0)];
    }

    private function a_restore(array $p): array
    {
        $this->requireReady();
        $name = V::containerName((string)($p['container'] ?? ''));
        $snap = V::snapshotId((string)($p['snapshot'] ?? ''));
        $mode = (string)($p['mode'] ?? '');       // container | files
        $target = (string)($p['target'] ?? '');   // inplace | folder
        $folder = '';
        if ($target === 'folder') {
            $folder = V::absPath((string)($p['folder'] ?? ''), 'Zielordner');
            $mnt = rtrim(Env::mntRoot(), '/');
            if (!str_starts_with($folder, "$mnt/") || substr_count(substr($folder, strlen($mnt)), '/') < 2) throw new \InvalidArgumentException('Zielordner muss unter /mnt/<pool oder share>/… liegen');
        } elseif ($target !== 'inplace') {
            throw new \InvalidArgumentException('Ziel wählen');
        }
        // the snapshot must belong to the container
        $snaps = array_filter($this->snapshotsCached(false), fn($s) => Restic::containerOf($s) === $name && ($s['id'] === $snap || str_starts_with($s['id'], $snap)));
        if (!$snaps) throw new \InvalidArgumentException('Diese Version gehört nicht zu dem Container');
        $s = array_values($snaps)[0];
        if ($mode === 'container') {
            $args = ['restore-container', $name, '--snapshot', $s['id'], '--quiet'];
            if ($target === 'folder') { $args[] = '--target'; $args[] = $folder; }
            else { $args[] = '--in-place'; $args[] = '--yes'; if (self::bool($p['stop_dependents'] ?? false)) $args[] = '--stop-dependents'; }
        } elseif ($mode === 'files') {
            $inc = array_map(fn($x) => V::absPath((string)$x), self::jsonParamOr($p, 'paths', []));
            if (!$inc) throw new \InvalidArgumentException('Dateien oder Ordner auswählen');
            $roots = array_filter((array)$s['paths'], fn($x) => !Fs::within($x, Env::metaRoot()));
            foreach ($inc as $i) {
                $okRoot = false;
                foreach ($roots as $r) if (Fs::within($i, $r)) $okRoot = true;
                if (!$okRoot) throw new \InvalidArgumentException("$i liegt nicht in dieser Sicherung");
            }
            $args = ['restore-files', $s['id'], '--quiet'];
            foreach ($inc as $i) { $args[] = '--include'; $args[] = $i; }
            if ($target === 'folder') { $args[] = '--target'; $args[] = $folder; }
            else {
                $args[] = '--in-place'; $args[] = '--yes';
                if (self::bool($p['stop_container'] ?? true)) { $args[] = '--stop'; $args[] = $name; }
            }
        } else {
            throw new \InvalidArgumentException('Art der Wiederherstellung wählen');
        }
        Jobs::start($args);
        return ['started' => 'restore'];
    }
}
