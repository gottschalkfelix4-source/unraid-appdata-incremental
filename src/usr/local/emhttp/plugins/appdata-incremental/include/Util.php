<?php
declare(strict_types=1);

namespace Aib;

const PLUGIN = 'appdata-incremental';
// written by the .plg installer; "dev" when running from a source checkout
define(__NAMESPACE__ . '\\VERSION', trim((string)@file_get_contents(__DIR__ . '/../VERSION')) ?: 'dev');

/** Paths; every one can be overridden by an environment variable (used by the test suite). */
final class Env
{
    public static function cfgDir(): string   { return self::e('AIB_CFG_DIR', '/boot/config/plugins/' . PLUGIN); }
    public static function stateDir(): string { return self::e('AIB_STATE_DIR', '/var/lib/' . PLUGIN); }
    public static function logDir(): string   { return self::e('AIB_LOG_DIR', '/var/log/' . PLUGIN); }
    public static function tmpDir(): string   { return self::e('AIB_TMP_DIR', '/tmp/' . PLUGIN); }
    public static function metaRoot(): string { return self::tmpDir() . '/meta'; }
    public static function mntRoot(): string  { return self::e('AIB_MNT_ROOT', '/mnt'); }
    public static function procMounts(): string { return self::e('AIB_PROC_MOUNTS', '/proc/mounts'); }
    public static function templatesDir(): string { return self::e('AIB_TEMPLATES_DIR', '/boot/config/plugins/dockerMan/templates-user'); }
    public static function notifyBin(): string { return self::e('AIB_NOTIFY', '/usr/local/emhttp/webGui/scripts/notify'); }
    public static function updateCronBin(): string { return self::e('AIB_UPDATE_CRON', '/usr/local/sbin/update_cron'); }
    public static function aibBin(): string { return self::e('AIB_SELF', '/usr/local/sbin/aib'); }
    /** External binaries: AIB_BIN_DOCKER, AIB_BIN_BTRFS, AIB_BIN_ZFS, AIB_BIN_UNSHARE */
    public static function bin(string $name): string { return self::e('AIB_BIN_' . strtoupper($name), $name); }

    private static function e(string $k, string $d): string
    {
        $v = getenv($k);
        return ($v === false || $v === '') ? $d : $v;
    }
}

final class Log
{
    private static $fh = null;
    private static ?string $file = null;
    public static bool $echo = true;
    public static int $warnings = 0;
    public static int $errors = 0;
    /** @var string[] */
    public static array $problems = [];

    public static function open(string $kind): string
    {
        $dir = Env::logDir();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        self::$file = $dir . '/' . $kind . '-' . date('Ymd-His') . '.log';
        self::$fh = fopen(self::$file, 'ab');
        @unlink($dir . '/latest.log');
        @symlink(basename(self::$file), $dir . '/latest.log');
        return self::$file;
    }

    public static function file(): ?string { return self::$file; }

    public static function close(): void
    {
        if (self::$fh) fclose(self::$fh);
        self::$fh = null;
    }

    public static function info(string $m): void { self::w('INFO', $m); }
    public static function debug(string $m): void { if (getenv('AIB_DEBUG')) self::w('DEBUG', $m); }
    public static function warn(string $m): void { self::$warnings++; self::$problems[] = 'WARN: ' . $m; self::w('WARN', $m); }
    public static function error(string $m): void { self::$errors++; self::$problems[] = 'ERROR: ' . $m; self::w('ERROR', $m); }

    private static function w(string $lvl, string $m): void
    {
        $line = date('Y-m-d H:i:s') . ' ' . str_pad($lvl, 5) . ' ' . $m . "\n";
        if (self::$fh) fwrite(self::$fh, $line);
        if (self::$echo) fwrite($lvl === 'INFO' || $lvl === 'DEBUG' ? STDOUT : STDERR, $line);
    }
}

final class Cmd
{
    /**
     * Run a command without a shell.
     * @param string[] $argv
     * @param array<string,string>|null $env extra environment
     * @param callable|null $onOut called per stdout line
     * @param callable|null $onErr called per stderr line
     * @return array{0:int,1:string,2:string} [exitCode, stdout, stderr]
     */
    public static function run(array $argv, ?array $env = null, ?callable $onOut = null, ?callable $onErr = null, ?string $cwd = null): array
    {
        Log::debug('exec: ' . self::show($argv));
        $fullEnv = null;
        if ($env !== null) {
            $fullEnv = getenv();
            foreach ($env as $k => $v) $fullEnv[$k] = $v;
        }
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open($argv, $spec, $pipes, $cwd, $fullEnv);
        if (!is_resource($p)) return [127, '', 'cannot execute ' . $argv[0]];
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = ''; $err = ''; $bo = ''; $be = '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open) {
            $r = array_values($open); $w = null; $x = null;
            if (@stream_select($r, $w, $x, 1) === false) break;
            foreach ($r as $s) {
                $chunk = fread($s, 65536);
                $isOut = ($s === $pipes[1]);
                if ($chunk === '' || $chunk === false) {
                    if (feof($s)) { unset($open[$isOut ? 1 : 2]); }
                    continue;
                }
                if ($isOut) { $out .= $chunk; $bo .= $chunk; self::lines($bo, $onOut); }
                else { $err .= $chunk; $be .= $chunk; self::lines($be, $onErr); }
            }
        }
        if ($bo !== '' && $onOut) $onOut($bo);
        if ($be !== '' && $onErr) $onErr($be);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($p);
        return [$code, $out, $err];
    }

    private static function lines(string &$buf, ?callable $cb): void
    {
        if (strlen($buf) > 1048576 && $cb === null) { $buf = ''; return; }
        while (($pos = strpos($buf, "\n")) !== false) {
            $line = substr($buf, 0, $pos);
            $buf = substr($buf, $pos + 1);
            if ($cb) $cb($line);
        }
        if ($cb === null) $buf = '';
    }

    /** Run, log failures, return [ok, stdout]. */
    public static function ok(array $argv, string $what): array
    {
        [$c, $o, $e] = self::run($argv);
        if ($c !== 0) {
            Log::error("$what fehlgeschlagen (Exit $c): " . trim($e !== '' ? $e : $o));
            return [false, $o];
        }
        return [true, $o];
    }

    public static function exists(string $bin): bool
    {
        if (str_contains($bin, '/')) return is_executable($bin);
        foreach (explode(':', getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin') as $d) {
            if ($d !== '' && is_executable("$d/$bin")) return true;
        }
        return false;
    }

    public static function show(array $argv): string
    {
        return implode(' ', array_map(fn($a) => preg_match('/^[A-Za-z0-9_\/.:=,@%+-]+$/', (string)$a) ? $a : escapeshellarg((string)$a), $argv));
    }
}

final class Fs
{
    public static function writeAtomic(string $file, string $data, int $mode = 0644): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $tmp = $file . '.tmp' . getmypid();
        if (file_put_contents($tmp, $data) === false) throw new \RuntimeException("Kann $file nicht schreiben");
        @chmod($tmp, $mode);
        if (!rename($tmp, $file)) { @unlink($tmp); throw new \RuntimeException("Kann $file nicht ersetzen"); }
    }

    public static function rmTree(string $p): void
    {
        if (is_link($p) || is_file($p)) { @unlink($p); return; }
        if (!is_dir($p)) return;
        foreach (scandir($p) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            self::rmTree("$p/$e");
        }
        @rmdir($p);
    }

    /** true if $child equals $parent or lies below it */
    public static function within(string $child, string $parent): bool
    {
        $parent = rtrim($parent, '/');
        if ($parent === '') return true;
        return $child === $parent || str_starts_with($child, $parent . '/');
    }

    public static function norm(string $p): string
    {
        $parts = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') { array_pop($parts); continue; }
            $parts[] = $seg;
        }
        return '/' . implode('/', $parts);
    }

    public static function human(float $bytes): string
    {
        $u = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($u) - 1) { $bytes /= 1024; $i++; }
        return sprintf($i ? '%.2f %s' : '%d %s', $bytes, $u[$i]);
    }
}

final class Notify
{
    public static function send(string $level, string $subject, string $desc): void
    {
        $bin = Env::notifyBin();
        $icon = ['error' => 'alert', 'warning' => 'warning'][$level] ?? 'normal';
        if (!is_executable($bin)) { Log::info("Benachrichtigung ($icon): $subject – $desc"); return; }
        Cmd::run([$bin, '-e', 'Appdata Incremental', '-s', $subject, '-d', $desc, '-i', $icon]);
    }
}

final class State
{
    public static function file(): string { return Env::stateDir() . '/status.json'; }

    public static function read(): array
    {
        $f = self::file();
        $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($d) ? $d : [];
    }

    public static function update(array $patch): void
    {
        $d = array_merge(self::read(), $patch);
        Fs::writeAtomic(self::file(), json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}

final class Lock
{
    private static $fh = null;

    public static function acquire(): bool
    {
        $dir = Env::stateDir();
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        self::$fh = fopen($dir . '/aib.lock', 'c');
        if (!self::$fh || !flock(self::$fh, LOCK_EX | LOCK_NB)) return false;
        ftruncate(self::$fh, 0);
        fwrite(self::$fh, (string)getmypid());
        return true;
    }

    public static function release(): void
    {
        if (self::$fh) { flock(self::$fh, LOCK_UN); fclose(self::$fh); self::$fh = null; }
    }
}
