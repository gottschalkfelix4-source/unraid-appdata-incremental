<?php
declare(strict_types=1);

namespace Aib;

/** One btrfs subvolume or ZFS dataset that gets a short-lived read-only snapshot. */
final class SnapUnit
{
    public bool $created = false;

    public function __construct(
        public string $type,     // btrfs | zfs
        public string $source,   // btrfs: subvolume root path, zfs: dataset name
        public string $mount,    // btrfs: subvolume root path, zfs: dataset mountpoint
        public string $target,   // btrfs: snapshot path, zfs: dataset@name
        public string $snapRoot, // where the snapshot content of $mount is visible
    ) {}

    public function key(): string { return $this->type . ':' . $this->source; }

    /** Path inside the snapshot that corresponds to $path (which lies below $mount). */
    public function map(string $path): string
    {
        return $this->snapRoot . substr($path, strlen(rtrim($this->mount, '/')));
    }

    public function describe(): string
    {
        return $this->type === 'btrfs' ? "btrfs-Snapshot von {$this->source} → {$this->target}" : "ZFS-Snapshot {$this->target}";
    }
}

final class Storage
{
    private static ?array $mounts = null;

    /** @return list<array{dev:string,mnt:string,type:string}> */
    public static function mounts(): array
    {
        if (self::$mounts !== null) return self::$mounts;
        $list = [];
        foreach (@file(Env::procMounts(), FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $f = explode(' ', $l);
            if (count($f) < 3) continue;
            $un = fn($s) => preg_replace_callback('/\\\\([0-7]{3})/', fn($m) => chr(octdec($m[1])), $s);
            $list[] = ['dev' => $un($f[0]), 'mnt' => $un($f[1]), 'type' => $f[2]];
        }
        return self::$mounts = $list;
    }

    public static function resetCache(): void { self::$mounts = null; }

    /** Mount entry that contains $path (longest prefix). */
    public static function mountFor(string $path): ?array
    {
        $best = null;
        foreach (self::mounts() as $m) {
            if (Fs::within($path, $m['mnt']) && ($best === null || strlen($m['mnt']) >= strlen($best['mnt']))) $best = $m;
        }
        return $best;
    }

    /**
     * Resolve a container path to real disk/pool paths. /mnt/user/X may exist on several pools at once
     * (Unraid merges them); every existing copy is returned.
     * @return array{paths:string[],warnings:string[]}
     */
    public static function resolve(string $path, Config $cfg): array
    {
        $path = Fs::norm($path);
        $root = rtrim(Env::mntRoot(), '/');
        foreach (['user', 'user0'] as $share) {
            $prefix = "$root/$share/";
            if (!str_starts_with($path . '/', $prefix)) continue;
            $rest = substr($path, strlen($prefix));
            if ($rest === '' || $rest === false) return ['paths' => [], 'warnings' => ["$path ist ein ganzer Share-Root – wird nicht gesichert"]];
            $found = [];
            $ignore = (array)$cfg->get('resolve_ignore', []);
            foreach (scandir($root) ?: [] as $d) {
                if ($d === '.' || $d === '..' || in_array($d, $ignore, true)) continue;
                $cand = "$root/$d/$rest";
                if (file_exists($cand) || is_link($cand)) $found[] = $cand;
            }
            if (!$found) return ['paths' => [], 'warnings' => ["$path existiert auf keinem Pool/Disk"]];
            return ['paths' => $found, 'warnings' => []];
        }
        if (!file_exists($path)) return ['paths' => [], 'warnings' => ["$path existiert nicht"]];
        return ['paths' => [$path], 'warnings' => []];
    }

    /** Remove paths that lie inside another path of the list. */
    public static function dedupe(array $paths): array
    {
        $paths = array_values(array_unique($paths));
        sort($paths);
        $out = [];
        foreach ($paths as $p) {
            foreach ($out as $o) if (Fs::within($p, $o)) continue 2;
            $out[] = $p;
        }
        return $out;
    }

    private static function btrfsSubvolRoot(string $path, string $mnt): string
    {
        $p = is_dir($path) ? $path : dirname($path);
        $dev = @stat($p)['dev'] ?? null;
        while (true) {
            $st = @stat($p);
            if ($st && $st['ino'] === 256 && $st['dev'] === $dev) return $p;
            if ($p === $mnt || $p === '/' || !Fs::within($p, $mnt)) return $mnt;
            $p = dirname($p);
        }
    }

    /**
     * Plan snapshot units + bind mounts for a set of real paths.
     * @return array{units:array<string,SnapUnit>,binds:list<array{0:string,1:string,2:string}>,unsupported:string[]}
     *         binds: [snapshotPath, originalPath, unitKey]
     */
    public static function plan(array $paths, Config $cfg): array
    {
        $units = []; $binds = []; $unsupported = [];
        $btrfsDir = trim((string)$cfg->get('snapshot.btrfs_dir', 'appdata/.aib-snapshots'), '/');
        $zname = (string)$cfg->get('snapshot.zfs_name', 'aib');
        foreach ($paths as $p) {
            $m = self::mountFor($p);
            if (!$m) { $unsupported[] = $p; continue; }
            if ($m['type'] === 'btrfs') {
                $r = self::btrfsSubvolRoot($p, $m['mnt']);
                $mnt = rtrim($m['mnt'], '/');
                $slug = trim(str_replace('/', '_', substr($r, strlen($mnt))), '_');
                $u = new SnapUnit('btrfs', $r, $r, $mnt . '/' . $btrfsDir . '/' . ($slug === '' ? 'root' : $slug), '');
                $u->snapRoot = $u->target;
                $units[$u->key()] ??= $u;
                $binds[] = [$units[$u->key()]->map($p), $p, $u->key()];
            } elseif ($m['type'] === 'zfs') {
                foreach (self::zfsUnitsFor($p, $m, $zname) as [$u, $dst]) {
                    $units[$u->key()] ??= $u;
                    $binds[] = [$units[$u->key()]->map($dst), $dst, $u->key()];
                }
            } else {
                $unsupported[] = $p;
            }
        }
        usort($binds, fn($a, $b) => strlen($a[1]) <=> strlen($b[1]));
        return ['units' => $units, 'binds' => $binds, 'unsupported' => $unsupported];
    }

    /** ZFS dataset of $p plus child datasets mounted below $p. */
    private static function zfsUnitsFor(string $p, array $m, string $zname): array
    {
        $mk = fn(array $mm) => new SnapUnit('zfs', $mm['dev'], $mm['mnt'], $mm['dev'] . '@' . $zname, rtrim($mm['mnt'], '/') . '/.zfs/snapshot/' . $zname);
        $res = [[$mk($m), $p]];
        foreach (self::mounts() as $c) {
            if ($c['type'] === 'zfs' && $c['mnt'] !== $p && Fs::within($c['mnt'], $p) && !str_contains($c['dev'], '@')) {
                $res[] = [$mk($c), $c['mnt']];
            }
        }
        return $res;
    }

    public static function create(SnapUnit $u): bool
    {
        if ($u->type === 'btrfs') {
            $btrfs = Env::bin('btrfs');
            if (file_exists($u->target)) self::destroyPath($u->target);
            $parent = dirname($u->target);
            if (!is_dir(dirname($parent))) { Log::error("Snapshot-Ordner $parent nicht möglich: " . dirname($parent) . ' fehlt'); return false; }
            if (!is_dir($parent) && !@mkdir($parent, 0700)) { Log::error("Kann $parent nicht anlegen"); return false; }
            [$ok] = Cmd::ok([$btrfs, 'subvolume', 'snapshot', '-r', $u->source, $u->target], 'btrfs-Snapshot');
            $u->created = $ok;
            return $ok;
        }
        $zfs = Env::bin('zfs');
        [$c] = Cmd::run([$zfs, 'list', '-H', '-t', 'snapshot', '-o', 'name', $u->target]);
        if ($c === 0 && !self::zfsDestroy($u->target, $u->snapRoot)) {
            Log::error("Alter ZFS-Snapshot {$u->target} ist noch belegt – neuer Snapshot nicht möglich");
            return false;
        }
        [$ok] = Cmd::ok([$zfs, 'snapshot', $u->target], 'ZFS-Snapshot');
        $u->created = $ok;
        if ($ok) { @scandir($u->snapRoot); } // trigger automount outside of the mount namespace
        return $ok;
    }

    public static function destroy(SnapUnit $u): void
    {
        if (!$u->created) return;
        if ($u->type === 'btrfs') self::destroyPath($u->target);
        elseif (!self::zfsDestroy($u->target, $u->snapRoot)) {
            Log::warn("ZFS-Snapshot {$u->target} ist noch belegt und bleibt vorerst bestehen – er wird beim nächsten Snapshot bzw. Lauf automatisch gelöscht");
        }
        $u->created = false;
    }

    /**
     * Destroy a ZFS snapshot. Right after a backup the kernel may still hold the snapshot's automount
     * (".zfs/snapshot/<name>", e.g. while the private mount namespace is torn down) and ZFS answers
     * "dataset is busy". Unmount it and retry for a few seconds before giving up.
     */
    public static function zfsDestroy(string $target, string $snapRoot = ''): bool
    {
        $waits = [0, 0.5, 1, 1, 2, 2, 3, 3];
        $err = '';
        foreach ($waits as $w) {
            if ($w > 0) usleep((int)($w * 1000000 * (float)(getenv('AIB_RETRY_SCALE') ?: 1)));
            if ($snapRoot !== '' && self::isMounted($snapRoot)) Cmd::run([Env::bin('umount'), $snapRoot]);
            [$c, $o, $e] = Cmd::run([Env::bin('zfs'), 'destroy', $target]);
            if ($c === 0) return true;
            $err = trim($e !== '' ? $e : $o);
            if (stripos($err, 'could not find') !== false || stripos($err, 'does not exist') !== false) return true;
            if (stripos($err, 'busy') === false) break; // other errors: no point in retrying
            Log::debug("$target noch belegt, neuer Versuch");
        }
        Log::debug("zfs destroy $target: $err");
        return false;
    }

    private static function isMounted(string $path): bool
    {
        $path = rtrim($path, '/');
        foreach (@file(Env::procMounts(), FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $f = explode(' ', $l);
            if (isset($f[1]) && preg_replace_callback('/\\\\([0-7]{3})/', fn($m) => chr(octdec($m[1])), $f[1]) === $path) return true;
        }
        return false;
    }

    private static function destroyPath(string $path): void
    {
        Cmd::ok([Env::bin('btrfs'), 'subvolume', 'delete', $path], "Löschen des Snapshots $path");
    }

    /** Remove snapshots left over by an aborted run (only our own names). */
    public static function cleanupStale(Config $cfg): void
    {
        if ($cfg->get('snapshot.mode') !== 'auto') return;
        $btrfsDir = trim((string)$cfg->get('snapshot.btrfs_dir'), '/');
        $seen = [];
        foreach (self::mounts() as $m) {
            if ($m['type'] !== 'btrfs' || isset($seen[$m['dev']])) continue;
            $seen[$m['dev']] = true;
            $dir = $m['mnt'] . '/' . $btrfsDir;
            if (!is_dir($dir)) continue;
            foreach (scandir($dir) ?: [] as $e) {
                if ($e === '.' || $e === '..') continue;
                Log::warn("Alter Snapshot $dir/$e aus abgebrochenem Lauf wird gelöscht");
                self::destroyPath("$dir/$e");
            }
        }
        if (Cmd::exists(Env::bin('zfs'))) {
            $suffix = '@' . $cfg->get('snapshot.zfs_name', 'aib');
            [$c, $o] = Cmd::run([Env::bin('zfs'), 'list', '-H', '-t', 'snapshot', '-o', 'name']);
            if ($c === 0) foreach (explode("\n", trim($o)) as $s) {
                if ($s !== '' && str_ends_with($s, $suffix)) {
                    Log::warn("Alter ZFS-Snapshot $s aus abgebrochenem Lauf wird gelöscht");
                    if (!self::zfsDestroy($s)) Log::warn("$s ist noch belegt – nächster Versuch beim nächsten Lauf");
                }
            }
        }
    }

    /**
     * Wrap a command so that it runs in a private mount namespace in which every original path is
     * bind-mounted from its snapshot. restic then records the original paths (stable for parent
     * detection and restore) while reading frozen data.
     */
    public static function wrapWithBinds(array $binds, array $argv): array
    {
        if (!$binds) return $argv;
        $script = "set -e\n";
        foreach ($binds as [$src, $dst]) {
            $script .= 'mount --bind ' . escapeshellarg($src) . ' ' . escapeshellarg($dst) . "\n";
        }
        $script .= 'exec "$@"' . "\n";
        return array_merge([Env::bin('unshare'), '--mount', '--propagation', 'private', '/bin/bash', '-c', $script, 'aib-ns'], $argv);
    }
}
