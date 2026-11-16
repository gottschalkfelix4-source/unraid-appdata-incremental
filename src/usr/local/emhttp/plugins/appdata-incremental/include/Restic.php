<?php
declare(strict_types=1);

namespace Aib;

final class Restic
{
    public function __construct(private Config $cfg) {}

    public function bin(): string { return (string)$this->cfg->get('restic_bin'); }

    public function env(): array
    {
        $e = [
            'RESTIC_REPOSITORY' => (string)$this->cfg->get('repository'),
            'RESTIC_PASSWORD_FILE' => (string)$this->cfg->get('password_file'),
            'RESTIC_CACHE_DIR' => (string)$this->cfg->get('restic_cache_dir'),
            'RESTIC_PROGRESS_FPS' => '0.0166', // ~1 status line per minute in --json mode
        ];
        return $e;
    }

    /** Global options placed before the sub command. */
    public function globalArgs(): array
    {
        $a = ['--retry-lock', '30m'];
        $sftp = $this->sftpCommand();
        if ($sftp !== null) { $a[] = '-o'; $a[] = 'sftp.command=' . $sftp; }
        $comp = (string)$this->cfg->get('compression', 'auto');
        if ($comp !== '') { $a[] = '--compression'; $a[] = $comp; }
        foreach ((array)$this->cfg->get('restic_extra_args', []) as $x) $a[] = (string)$x;
        return $a;
    }

    /** Own ssh call for sftp repositories (key, port, known_hosts of the plugin). */
    public function sftpCommand(): ?string
    {
        $repo = (string)$this->cfg->get('repository');
        if (!str_starts_with($repo, 'sftp:')) return null;
        $port = (int)$this->cfg->get('ssh.port', 22);
        if (preg_match('#^sftp://([^/@]+@)?([^/:]+)(?::(\d+))?/#', $repo, $m)) {
            $userHost = $m[1] . $m[2];
            if (!empty($m[3])) $port = (int)$m[3];
        } elseif (preg_match('#^sftp:([^/:]+@)?([^/:]+):#', $repo, $m)) {
            $userHost = $m[1] . $m[2];
        } else {
            return null;
        }
        $key = $this->privateKeyCopy((string)$this->cfg->get('ssh.key_file'));
        $kh = (string)$this->cfg->get('ssh.known_hosts');
        $parts = ['ssh', '-p', (string)$port, '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new',
                  '-o', 'ServerAliveInterval=60', '-o', 'ServerAliveCountMax=240'];
        if ($key !== '') { $parts[] = '-i'; $parts[] = $key; $parts[] = '-o'; $parts[] = 'IdentitiesOnly=yes'; }
        if ($kh !== '') { $parts[] = '-o'; $parts[] = 'UserKnownHostsFile=' . $kh; }
        $parts[] = $userHost; $parts[] = '-s'; $parts[] = 'sftp';
        foreach ($parts as $p) if (preg_match('/[\s"\']/', $p)) throw new \RuntimeException("Leerzeichen/Anführungszeichen in ssh-Parameter nicht erlaubt: $p");
        return implode(' ', $parts);
    }

    /**
     * ssh refuses private keys with open permissions; the flash drive (FAT32) cannot store them.
     * Use a 0600 copy in RAM instead.
     */
    private function privateKeyCopy(string $key): string
    {
        if ($key === '' || !is_file($key)) return $key;
        $dir = Env::tmpDir() . '/ssh';
        if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
        @chmod($dir, 0700);
        $copy = $dir . '/' . basename($key);
        if (!is_file($copy) || filemtime($copy) < filemtime($key) || filesize($copy) !== filesize($key)) {
            $old = umask(0077);
            @copy($key, $copy);
            umask($old);
        }
        @chmod($copy, 0600);
        return is_file($copy) ? $copy : $key;
    }

    public function argv(array $sub): array
    {
        return array_merge([$this->bin()], $this->globalArgs(), $sub);
    }

    /** Run restic, stream stderr into the log. */
    public function run(array $sub, ?callable $onOut = null, bool $logStderr = true, ?array $wrapBinds = null): array
    {
        $argv = $this->argv($sub);
        if ($wrapBinds) $argv = Storage::wrapWithBinds($wrapBinds, $argv);
        $onErr = $logStderr ? function (string $l) { if (trim($l) !== '') Log::info('restic: ' . $l); } : null;
        return Cmd::run($argv, $this->env(), $onOut, $onErr);
    }

    public function json(array $sub)
    {
        [$c, $o, $e] = $this->run(array_merge($sub, ['--json']), null, false);
        if ($c !== 0) throw new \RuntimeException('restic ' . $sub[0] . ' fehlgeschlagen (Exit ' . $c . '): ' . trim($e));
        $d = json_decode($o, true);
        if ($d === null && trim($o) !== 'null') throw new \RuntimeException('restic ' . $sub[0] . ': ungültiges JSON');
        return $d;
    }

    /** Snapshots of this plugin, optionally for one container; newest last. */
    public function snapshots(?string $container = null): array
    {
        $tag = 'aib' . ($container !== null ? ',ct:' . $container : '');
        $list = $this->json(['snapshots', '--tag', $tag, '--host', (string)$this->cfg->get('host')]) ?: [];
        usort($list, fn($a, $b) => strcmp($a['time'], $b['time']));
        return $list;
    }

    public static function containerOf(array $snap): ?string
    {
        foreach ((array)($snap['tags'] ?? []) as $t) if (str_starts_with($t, 'ct:')) return substr($t, 3);
        return null;
    }

    public function checkReady(): array
    {
        $p = [];
        if (!is_executable($this->bin())) $p[] = 'restic fehlt: ' . $this->bin();
        if ((string)$this->cfg->get('repository') === '') $p[] = 'repository ist nicht gesetzt';
        $pw = (string)$this->cfg->get('password_file');
        if (!is_file($pw) || trim((string)@file_get_contents($pw)) === '') $p[] = "Passwortdatei fehlt oder ist leer: $pw";
        if ($this->sftpCommand() !== null && !is_file((string)$this->cfg->get('ssh.key_file'))) $p[] = 'SSH-Key fehlt: ' . $this->cfg->get('ssh.key_file');
        return $p;
    }

    public function retentionArgs(?array $override = null): array
    {
        $r = $override ?? (array)$this->cfg->get('retention', []);
        $a = [];
        foreach (['keep_last', 'keep_hourly', 'keep_daily', 'keep_weekly', 'keep_monthly', 'keep_yearly'] as $k) {
            if (!empty($r[$k]) && (int)$r[$k] > 0) { $a[] = '--' . str_replace('_', '-', $k); $a[] = (string)(int)$r[$k]; }
        }
        if (!empty($r['keep_within'])) { $a[] = '--keep-within'; $a[] = (string)$r['keep_within']; }
        return $a;
    }
}
