<?php
declare(strict_types=1);

namespace Aib;

/** Input validation helpers (everything that reaches a command line goes through here). */
final class V
{
    public static function containerName(string $s): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $s)) throw new \InvalidArgumentException("Ungültiger Containername");
        return $s;
    }

    public static function snapshotId(string $s): string
    {
        if ($s === 'latest' || preg_match('/^[0-9a-f]{8,64}$/', $s)) return $s;
        throw new \InvalidArgumentException('Ungültige Snapshot-ID');
    }

    public static function absPath(string $s, string $what = 'Pfad'): string
    {
        if ($s === '' || $s[0] !== '/' || preg_match('/[\x00-\x1f\x7f]/', $s)) throw new \InvalidArgumentException("$what muss ein absoluter Pfad sein");
        $n = Fs::norm($s);
        if (str_contains($s, '/../') || str_ends_with($s, '/..')) throw new \InvalidArgumentException("$what darf kein .. enthalten");
        return $n;
    }

    public static function relPath(string $s): string
    {
        $s = trim($s);
        if ($s === '' || preg_match('/[\x00-\x1f\x7f]/', $s)) throw new \InvalidArgumentException('Ungültiger Ausschluss');
        foreach (explode('/', $s) as $seg) if ($seg === '..') throw new \InvalidArgumentException('Ausschluss darf kein .. enthalten');
        return $s;
    }

    public static function host(string $s): string
    {
        $s = trim($s);
        if (!preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/', $s)) throw new \InvalidArgumentException('Ungültiger Hostname');
        return $s;
    }

    public static function user(string $s): string
    {
        $s = trim($s);
        if (!preg_match('/^[A-Za-z0-9._][A-Za-z0-9._-]{0,63}$/', $s)) throw new \InvalidArgumentException('Ungültiger Benutzername');
        return $s;
    }

    public static function repoPath(string $s): string
    {
        $s = trim($s);
        if (!preg_match('#^[A-Za-z0-9._/-]{1,200}$#', $s) || str_contains("/$s/", '/../')) throw new \InvalidArgumentException('Ungültiger Repository-Pfad (erlaubt: A-Z a-z 0-9 . _ / -)');
        return $s;
    }

    public static function port($p): int
    {
        $p = (int)$p;
        if ($p < 1 || $p > 65535) throw new \InvalidArgumentException('Ungültiger Port');
        return $p;
    }

    public static function cron(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        $f = explode(' ', $s);
        if (count($f) !== 5) throw new \InvalidArgumentException('Cron-Ausdruck braucht 5 Felder');
        foreach ($f as $x) if (!preg_match('#^[0-9*,/-]+$#', $x)) throw new \InvalidArgumentException('Ungültiger Cron-Ausdruck');
        return $s;
    }

    public static function text(string $s, int $max = 200): string
    {
        $s = trim(preg_replace('/[\x00-\x1f\x7f]/', ' ', $s));
        return mb_substr($s, 0, $max);
    }
}

/** Backup target → restic repository string. */
final class Target
{
    public static function repository(Config $cfg): string
    {
        $t = (array)$cfg->get('target', []);
        switch ($t['type'] ?? '') {
            case 'sftp':
                if (empty($t['host']) || empty($t['user'])) return '';
                return 'sftp:' . $t['user'] . '@' . $t['host'] . ':' . ($t['path'] ?: 'restic-appdata');
            case 'local':
                return (string)($t['local_path'] ?? '');
            case 'custom':
                return (string)($t['custom'] ?? '');
        }
        return (string)$cfg->get('repository', '');
    }

    public static function sshPort(Config $cfg): int
    {
        if (($cfg->get('target.type') ?? '') === 'sftp') return (int)($cfg->get('target.port') ?: 22);
        return (int)$cfg->get('ssh.port', 22);
    }

    public static function isSftp(Config $cfg): bool { return str_starts_with(self::repository($cfg), 'sftp:'); }
}

final class Secrets
{
    public static function dir(): string { return Env::cfgDir() . '/secrets'; }

    private static function ensureDir(): void
    {
        if (!is_dir(self::dir())) mkdir(self::dir(), 0700, true);
    }

    public static function keyStatus(Config $cfg): array
    {
        $key = (string)$cfg->get('ssh.key_file');
        $pub = $key . '.pub';
        $r = ['exists' => is_file($key), 'public' => is_file($pub) ? trim((string)file_get_contents($pub)) : '', 'fingerprint' => ''];
        if ($r['exists'] && Cmd::exists(Env::bin('ssh-keygen'))) {
            [$c, $o] = Cmd::run([Env::bin('ssh-keygen'), '-l', '-f', is_file($pub) ? $pub : $key]);
            if ($c === 0) $r['fingerprint'] = trim($o);
        }
        return $r;
    }

    public static function generateKey(Config $cfg, bool $force): array
    {
        self::ensureDir();
        $key = (string)$cfg->get('ssh.key_file');
        if (is_file($key) && !$force) throw new \RuntimeException('Es gibt schon einen Schlüssel. Zum Ersetzen bestätigen.');
        @unlink($key); @unlink("$key.pub");
        [$c, , $e] = Cmd::run([Env::bin('ssh-keygen'), '-t', 'ed25519', '-N', '', '-C', 'appdata-incremental', '-f', $key, '-q']);
        if ($c !== 0) throw new \RuntimeException('ssh-keygen fehlgeschlagen: ' . trim($e));
        return self::keyStatus($cfg);
    }

    /**
     * Put the public key on the server with a one-time password. The password is handed to ssh via
     * SSH_ASKPASS (environment of the child only); it is never written to disk or logs.
     * mode: storagebox = "install-ssh-key" command (Hetzner Storage Box), standard = append to ~/.ssh/authorized_keys
     */
    public static function installKey(Config $cfg, string $password, string $mode): string
    {
        if (($cfg->get('target.type') ?? '') !== 'sftp') throw new \RuntimeException('Erst ein SFTP-Ziel speichern');
        $host = V::host((string)$cfg->get('target.host'));
        $user = V::user((string)$cfg->get('target.user'));
        $port = V::port($cfg->get('target.port'));
        $st = self::keyStatus($cfg);
        if (!$st['exists'] || $st['public'] === '') throw new \RuntimeException('Erst einen SSH-Schlüssel erzeugen');
        if ($password === '') throw new \RuntimeException('Passwort fehlt');
        self::ensureDir();
        $askpass = Env::tmpDir() . '/askpass.sh';
        if (!is_dir(Env::tmpDir())) mkdir(Env::tmpDir(), 0700, true);
        file_put_contents($askpass, "#!/bin/sh\nprintf '%s\\n' \"\$AIB_ONE_TIME_PW\"\n");
        chmod($askpass, 0700);
        $remote = $mode === 'storagebox' ? 'install-ssh-key'
            : 'umask 077; mkdir -p .ssh && cat >> .ssh/authorized_keys';
        $argv = [Env::bin('setsid'), '-w', Env::bin('ssh'), '-p', (string)$port,
            '-o', 'StrictHostKeyChecking=accept-new', '-o', 'UserKnownHostsFile=' . $cfg->get('ssh.known_hosts'),
            '-o', 'PubkeyAuthentication=no', '-o', 'PreferredAuthentications=password,keyboard-interactive',
            '-o', 'NumberOfPasswordPrompts=1', '-o', 'ConnectTimeout=20', "$user@$host", $remote];
        $env = ['SSH_ASKPASS' => $askpass, 'SSH_ASKPASS_REQUIRE' => 'force', 'DISPLAY' => ':0', 'AIB_ONE_TIME_PW' => $password];
        $pubFile = $cfg->get('ssh.key_file') . '.pub';
        [$c, $o, $e] = self::runWithStdin($argv, $env, $pubFile);
        if ($c !== 0) {
            $msg = trim($e !== '' ? $e : $o);
            if (stripos($msg, 'Permission denied') !== false) $msg = 'Anmeldung abgelehnt – Passwort oder Benutzer falsch, oder SSH ist auf dem Server nicht aktiviert';
            throw new \RuntimeException('Schlüssel konnte nicht hinterlegt werden: ' . $msg);
        }
        return trim($o) ?: 'Schlüssel hinterlegt.';
    }

    private static function runWithStdin(array $argv, array $env, string $stdinFile): array
    {
        $full = getenv();
        foreach ($env as $k => $v) $full[$k] = $v;
        $p = proc_open($argv, [0 => ['file', $stdinFile, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $full);
        if (!is_resource($p)) return [127, '', 'ssh nicht ausführbar'];
        $o = stream_get_contents($pipes[1]); $e = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($p), (string)$o, (string)$e];
    }

    public static function passwordStatus(Config $cfg): array
    {
        $f = (string)$cfg->get('password_file');
        $pw = is_file($f) ? trim((string)file_get_contents($f)) : '';
        return ['set' => $pw !== '', 'length' => strlen($pw)];
    }

    public static function setPassword(Config $cfg, string $pw, bool $force): void
    {
        $pw = trim($pw);
        if (strlen($pw) < 12) throw new \RuntimeException('Das Passwort muss mindestens 12 Zeichen haben');
        if (preg_match('/[\x00-\x1f\x7f]/', $pw)) throw new \RuntimeException('Das Passwort enthält Steuerzeichen');
        if (self::passwordStatus($cfg)['set'] && !$force) throw new \RuntimeException('Es ist schon ein Passwort gesetzt. Ersetzen nur mit Bestätigung – ein bestehendes Repository ist danach nicht mehr lesbar.');
        self::ensureDir();
        Fs::writeAtomic((string)$cfg->get('password_file'), $pw . "\n", 0600);
    }

    public static function generatePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
    }
}

final class Schedule
{
    public static function cronFile(): string { return Env::cfgDir() . '/' . PLUGIN . '.cron'; }

    /** Cron line from the GUI fields. */
    public static function fromFields(array $s): string
    {
        $min = max(0, min(59, (int)($s['minute'] ?? 0)));
        $hour = max(0, min(23, (int)($s['hour'] ?? 2)));
        switch ($s['frequency'] ?? 'daily') {
            case 'hourly': return "$min * * * *";
            case 'daily': return "$min $hour * * *";
            case 'weekly': return "$min $hour * * " . max(0, min(6, (int)($s['weekday'] ?? 0)));
            case 'monthly': return "$min $hour " . max(1, min(28, (int)($s['monthday'] ?? 1))) . ' * *';
            case 'custom': return V::cron((string)($s['custom'] ?? ''));
        }
        throw new \InvalidArgumentException('Unbekannte Häufigkeit');
    }

    public static function apply(Config $cfg): string
    {
        $expr = trim((string)$cfg->get('schedule.backup', ''));
        $f = self::cronFile();
        if (!$cfg->get('schedule.enabled') || $expr === '') { @unlink($f); $msg = 'Zeitplan aus'; }
        else {
            Fs::writeAtomic($f, "# Appdata Incremental\n$expr " . Env::aibBin() . " cron > /dev/null 2>&1\n");
            $msg = "Zeitplan aktiv: $expr";
        }
        if (is_executable(Env::updateCronBin())) Cmd::run([Env::updateCronBin()]);
        return $msg;
    }
}

/** Background jobs (backup, restore, prune …) started from the GUI as their own session. */
final class Jobs
{
    public static function running(): ?array
    {
        $s = State::read();
        $pid = (int)($s['pid'] ?? 0);
        if (empty($s['running']) || $pid <= 0) return null;
        $cmd = @file_get_contents("/proc/$pid/cmdline");
        if ($cmd === false || !str_contains($cmd, 'aib')) return null;
        return $s;
    }

    /** @param string[] $args arguments for aib (validated by the caller) */
    public static function start(array $args): void
    {
        if (self::running()) throw new \RuntimeException('Es läuft bereits ein Vorgang');
        $lockFile = Env::stateDir() . '/aib.lock';
        if (is_file($lockFile)) {
            $fh = fopen($lockFile, 'c');
            $free = $fh && flock($fh, LOCK_EX | LOCK_NB);
            if ($fh) { if ($free) flock($fh, LOCK_UN); fclose($fh); }
            if (!$free) throw new \RuntimeException('Es läuft bereits ein Vorgang');
        }
        $self = Env::aibBin();
        $argv = array_merge([Env::bin('setsid'), '-f'], is_executable($self) ? [$self] : [PHP_BINARY, $self], $args);
        $p = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($p)) throw new \RuntimeException('Vorgang konnte nicht gestartet werden');
        proc_close($p);
        // wait briefly until the job registered itself
        for ($i = 0; $i < 30 && !self::running(); $i++) usleep(100000);
    }

    /** Stop the running job (whole process group), then restart containers it stopped and remove snapshots. */
    public static function cancel(Config $cfg): string
    {
        $s = self::running();
        if (!$s) return 'Kein Vorgang aktiv.';
        $pid = (int)$s['pid'];
        if (function_exists('posix_kill')) { @posix_kill(-$pid, 15); @posix_kill($pid, 15); }
        else { Cmd::run(['kill', '-TERM', '--', "-$pid"]); Cmd::run(['kill', '-TERM', (string)$pid]); }
        for ($i = 0; $i < 300 && file_exists("/proc/$pid"); $i++) usleep(100000);
        if (file_exists("/proc/$pid")) {
            if (function_exists('posix_kill')) { @posix_kill(-$pid, 9); @posix_kill($pid, 9); } else Cmd::run(['kill', '-KILL', '--', "-$pid"]);
            usleep(500000);
        }
        $msg = 'Vorgang abgebrochen.';
        if (Lock::acquire()) {
            Log::$echo = false;
            $left = (array)(State::read()['stopped_by_aib'] ?? []);
            foreach ($left as $n) if (!Docker::isRunning($n)) Docker::start($n);
            if ($left) $msg .= ' Wieder gestartet: ' . implode(', ', $left) . '.';
            Storage::cleanupStale($cfg);
            State::update(['running' => false, 'phase' => 'idle', 'current' => null, 'stopped_by_aib' => []]);
            Lock::release();
        }
        return $msg;
    }
}

/** Built-in exclude templates (generic; selectable in the GUI). */
final class Templates
{
    public static function all(): array
    {
        $plex = 'Library/Application Support/Plex Media Server';
        return [
            'gamepanel' => ['label' => 'Game-Panel: Welten und eigene Backups ausschließen', 'excludes' => ['backups', 'servers', 'cache'],
                            'help' => 'Für Panels, die Spielserver-Welten und eigene Backups selbst sichern. Gesichert werden nur Datenbank, Einstellungen und Schlüssel.'],
            'logs' => ['label' => 'Logs ausschließen', 'excludes' => ['logs', 'log', '*.log']],
            'cache' => ['label' => 'Cache und Temp ausschließen', 'excludes' => ['cache', 'Cache', 'tmp', 'temp']],
            'plex' => ['label' => 'Plex: Cache, Codecs, Absturzberichte', 'excludes' => ["$plex/Cache", "$plex/Codecs", "$plex/Crash Reports", "$plex/Logs"]],
            'jellyfin' => ['label' => 'Jellyfin/Emby: Cache, Transcodes, Logs', 'excludes' => ['cache', 'transcodes', 'log', 'logs']],
        ];
    }
}

/** System check (structured for GUI and CLI). */
final class Doctor
{
    /** @return list<array{ok:bool,what:string,info:string}> */
    public static function run(Config $cfg): array
    {
        $out = [];
        $add = function (bool $ok, string $what, string $info = '') use (&$out) { $out[] = ['ok' => $ok, 'what' => $what, 'info' => $info]; };
        $add(version_compare(PHP_VERSION, '8.1', '>='), 'PHP ' . PHP_VERSION);
        foreach (['docker', 'unshare', 'setsid', 'ssh-keygen', 'btrfs', 'zfs'] as $b) {
            $need = in_array($b, ['docker', 'unshare', 'setsid'], true);
            $ex = Cmd::exists(Env::bin($b));
            if ($ex || $need) $add($ex, "Programm $b", $ex ? '' : 'fehlt');
        }
        $r = new Restic($cfg);
        [$c, $o] = is_executable($r->bin()) ? Cmd::run([$r->bin(), 'version']) : [1, ''];
        $add($c === 0, 'restic', trim($o) ?: $r->bin() . ' fehlt');
        $v = $cfg->validate();
        $add(!$v, 'Konfiguration', $v ? implode('; ', $v) : Config::file());
        $ready = $r->checkReady();
        foreach ($ready as $p) $add(false, $p);
        if (!$ready) $add(true, 'Ziel, Passwort und Schlüssel gesetzt', Target::repository($cfg));
        if ($cfg->get('snapshot.mode') === 'auto') {
            foreach (Storage::mounts() as $m) {
                if (!in_array($m['type'], ['btrfs', 'zfs'], true) || !str_starts_with($m['mnt'], rtrim(Env::mntRoot(), '/') . '/')) continue;
                if ($m['type'] === 'btrfs') {
                    $parent = $m['mnt'] . '/' . dirname(trim((string)$cfg->get('snapshot.btrfs_dir'), '/'));
                    $add(true, "btrfs-Pool {$m['mnt']}", is_dir($parent) ? 'Snapshots möglich' : "Ordner $parent fehlt – hier keine Snapshots");
                } elseif (!str_contains($m['dev'], '@')) {
                    $add(true, "ZFS-Dataset {$m['dev']}", "Mount {$m['mnt']}");
                }
            }
        }
        $add(true, 'Zeitplan', is_file(Schedule::cronFile()) ? 'aktiv: ' . trim((string)$cfg->get('schedule.backup')) : 'aus');
        try {
            $add(true, 'Docker erreichbar', count(Docker::list()) . ' Container');
        } catch (\Throwable $e) {
            $add(false, 'Docker', $e->getMessage());
        }
        return $out;
    }

    /** Snapshot + bind mount test for one path, without stopping containers. */
    public static function selftest(Config $cfg, string $path): array
    {
        $log = [];
        $res = Storage::resolve($path, $cfg);
        if (!$res['paths']) return ['ok' => false, 'log' => $res['warnings']];
        $plan = Storage::plan($res['paths'], $cfg);
        if ($plan['unsupported']) return ['ok' => false, 'log' => ['Kein btrfs/ZFS: ' . implode(', ', $plan['unsupported'])]];
        if (!Lock::acquire()) return ['ok' => false, 'log' => ['Es läuft bereits ein Vorgang']];
        $ok = true; $units = [];
        try {
            foreach ($plan['units'] as $u) {
                $log[] = 'Erstelle ' . $u->describe();
                if (!Storage::create($u)) { $ok = false; $log[] = 'Snapshot fehlgeschlagen'; break; }
                $units[] = $u;
            }
            if ($ok) {
                $marker = $res['paths'][0] . '/.aib-selftest-' . getmypid();
                @file_put_contents($marker, 'x');
                $argv = Storage::wrapWithBinds($plan['binds'], ['/bin/bash', '-c', 'for p in "$@"; do echo "== $p"; ls -A "$p" | head -10; done; test ! -e ' . escapeshellarg($marker), 'x', ...$res['paths']]);
                [$c, $o, $e] = Cmd::run($argv);
                @unlink($marker);
                foreach (explode("\n", trim($o . $e)) as $l) if ($l !== '') $log[] = $l;
                $ok = $c === 0;
                $log[] = $ok ? 'OK: Der Snapshot-Stand ist eingefroren lesbar.' : "FEHLER: Bind-Mount/Namespace funktioniert nicht wie erwartet (Exit $c).";
            }
        } finally {
            foreach ($units as $u) { $log[] = 'Lösche ' . $u->describe(); Storage::destroy($u); }
            Lock::release();
        }
        return ['ok' => $ok, 'log' => $log];
    }
}
