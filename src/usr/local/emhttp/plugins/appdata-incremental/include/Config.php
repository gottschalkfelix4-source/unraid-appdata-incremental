<?php
declare(strict_types=1);

namespace Aib;

final class Config
{
    public array $data;

    public function __construct(array $data) { $this->data = $data; }

    public static function file(): string { return Env::cfgDir() . '/config.json'; }

    public static function defaults(): array
    {
        $cfg = Env::cfgDir();
        return [
            // restic repository, e.g. "sftp:user@host:restic-appdata" (SFTP) or a local path
            'repository' => '',
            'password_file' => "$cfg/secrets/repo.pass",
            'ssh' => [
                'key_file' => "$cfg/secrets/id_ed25519",
                'port' => 23,
                'known_hosts' => "$cfg/secrets/known_hosts",
            ],
            'restic_bin' => '/usr/local/bin/aib-restic',
            'restic_cache_dir' => Env::tmpDir() . '/restic-cache',
            'restic_extra_args' => [],
            'compression' => 'auto',
            'host' => 'unraid',
            // folders whose sub folders count as appdata (bind mounts below them are backed up);
            // shell patterns allowed, "/mnt/*/appdata" covers every pool and disk
            'appdata_roots' => ['/mnt/user/appdata', '/mnt/*/appdata'],
            // folders below /mnt that are no disks/pools when resolving /mnt/user paths
            'resolve_ignore' => ['user', 'user0', 'disks', 'remotes', 'addons', 'rootshare'],
            'snapshot' => [
                'mode' => 'auto',            // auto = btrfs/ZFS snapshot, none = back up live while stopped
                'btrfs_dir' => 'appdata/.aib-snapshots', // relative to the pool mount, must be on the same pool
                'zfs_name' => 'aib',
                'on_failure' => 'backup_stopped', // backup_stopped | skip
            ],
            'stop_timeout' => 60,
            'schedule' => [
                'enabled' => false,          // aib schedule enable|disable
                'backup' => '0 2 * * *',     // cron expression
                'prune_weekday' => 0,        // 0 = Sunday, -1 = never automatically
                'check_day' => 1,            // day of month, 0 = never automatically
                'check_subset' => '2%',
            ],
            'retention' => ['keep_last' => 0, 'keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 6, 'keep_yearly' => 0, 'keep_within' => ''],
            'notify' => ['on_error' => true, 'on_warning' => true, 'on_success' => false],
            'global_excludes' => [],
            'new_containers' => ['enabled' => true, 'stop' => true],
            // containers with a bind mount matching one of these patterns are deselected by default,
            // e.g. "*/appdata/gamepanel/servers/*" (see docs/examples)
            'auto_disable_mount_patterns' => [],
            // default settings for newly found containers whose bind mount matches match_path,
            // e.g. {"match_path": "*/appdata/gamepanel", "excludes": ["backups", "servers", "cache"]}
            'presets' => [],
            'log_keep' => 14,
            'containers' => new \stdClass(),
        ];
    }

    public static function containerDefaults(): array
    {
        return ['enabled' => true, 'stop' => true, 'group' => '', 'order' => 100, 'excludes' => [],
                'extra_paths' => [], 'skip_paths' => [], 'retention' => null, 'note' => ''];
    }

    public static function load(): self
    {
        $d = self::defaults();
        $f = self::file();
        if (is_file($f)) {
            $raw = json_decode((string)file_get_contents($f), true);
            if (!is_array($raw)) throw new \RuntimeException("Konfiguration $f ist kein gültiges JSON: " . json_last_error_msg());
            $d = self::merge($d, $raw);
        }
        if ($d['containers'] instanceof \stdClass) $d['containers'] = [];
        return new self($d);
    }

    private static function isAssoc($a): bool
    {
        return is_array($a) && $a !== [] && array_keys($a) !== range(0, count($a) - 1);
    }

    private static function merge(array $def, array $over): array
    {
        foreach ($over as $k => $v) {
            if (isset($def[$k]) && self::isAssoc($def[$k]) && self::isAssoc($v)) $def[$k] = self::merge($def[$k], $v);
            else $def[$k] = $v;
        }
        return $def;
    }

    public function save(): void
    {
        $d = $this->data;
        if (!$d['containers']) $d['containers'] = new \stdClass();
        Fs::writeAtomic(self::file(), json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", 0600);
    }

    public function get(string $path, $default = null)
    {
        $cur = $this->data;
        foreach (explode('.', $path) as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) return $default;
            $cur = $cur[$k];
        }
        return $cur;
    }

    /** true if a bind mount source lies below one of the appdata roots (patterns allowed) */
    public function isAppdataPath(string $source): bool
    {
        $segs = explode('/', Fs::norm($source));
        foreach ((array)$this->get('appdata_roots', []) as $root) {
            $root = Fs::norm((string)$root);
            $n = substr_count($root, '/');
            if (count($segs) <= $n + 1) continue; // must be strictly below the root
            if (fnmatch($root, implode('/', array_slice($segs, 0, $n + 1)))) return true;
        }
        return false;
    }

    public function hasContainer(string $name): bool { return isset($this->data['containers'][$name]); }

    /**
     * Effective settings for a container.
     * @param array $info docker info (from Docker::list())
     * @return array settings + 'source' (config|preset|auto-disabled|default)
     */
    public function containerSettings(string $name, array $info): array
    {
        $def = self::containerDefaults();
        if ($this->hasContainer($name)) {
            $s = array_merge($def, (array)$this->data['containers'][$name]);
            $s['source'] = 'config';
            return $s;
        }
        $s = $def;
        $s['enabled'] = (bool)$this->get('new_containers.enabled', true);
        $s['stop'] = (bool)$this->get('new_containers.stop', true);
        $s['source'] = 'default';
        $sources = array_map(fn($m) => $m['source'], $info['mounts'] ?? []);
        foreach ((array)$this->get('presets', []) as $p) {
            foreach ($sources as $src) {
                if (fnmatch($p['match_path'], rtrim($src, '/'))) {
                    foreach (['excludes', 'stop', 'enabled', 'group', 'order', 'note'] as $k) if (array_key_exists($k, $p)) $s[$k] = $p[$k];
                    $s['source'] = 'preset';
                    break 2;
                }
            }
        }
        foreach ((array)$this->get('auto_disable_mount_patterns', []) as $pat) {
            foreach ($sources as $src) {
                if (fnmatch($pat, rtrim($src, '/')) || fnmatch($pat, rtrim($src, '/') . '/')) {
                    $s['enabled'] = false;
                    $s['note'] = $s['note'] ?: "automatisch abgewählt (Mount passt zu $pat)";
                    $s['source'] = 'auto-disabled';
                    break 2;
                }
            }
        }
        return $s;
    }

    /** Add newly found containers to the config with their effective defaults. Returns names added. */
    public function sync(array $dockerList): array
    {
        $added = [];
        foreach ($dockerList as $name => $info) {
            if ($this->hasContainer($name)) continue;
            $s = $this->containerSettings($name, $info);
            unset($s['source']);
            $this->data['containers'][$name] = $s;
            $added[] = $name;
        }
        ksort($this->data['containers'], SORT_NATURAL | SORT_FLAG_CASE);
        return $added;
    }

    /** @return string[] problems */
    public function validate(): array
    {
        $p = [];
        if (!in_array($this->get('snapshot.mode'), ['auto', 'none'], true)) $p[] = 'snapshot.mode muss auto oder none sein';
        if (!in_array($this->get('snapshot.on_failure'), ['backup_stopped', 'skip'], true)) $p[] = 'snapshot.on_failure muss backup_stopped oder skip sein';
        $cron = (string)$this->get('schedule.backup', '');
        if ($cron !== '' && count(preg_split('/\s+/', trim($cron))) !== 5) $p[] = 'schedule.backup braucht 5 Cron-Felder';
        foreach ($this->data['containers'] as $n => $c) {
            if (!is_array($c)) { $p[] = "containers.$n muss ein Objekt sein"; continue; }
            foreach (['excludes', 'extra_paths', 'skip_paths'] as $k) {
                if (isset($c[$k]) && !is_array($c[$k])) $p[] = "containers.$n.$k muss eine Liste sein";
            }
            foreach ((array)($c['extra_paths'] ?? []) as $x) if (!str_starts_with((string)$x, '/')) $p[] = "containers.$n.extra_paths: '$x' muss absolut sein";
        }
        return $p;
    }
}
