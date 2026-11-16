<?php
declare(strict_types=1);

namespace Aib;

/** All containers via the Docker CLI/API – including ones without an Unraid template (e.g. created by Compose or other management tools). */
final class Docker
{
    /** @return array<string,array{name:string,id:string,running:bool,status:string,image:string,restart:string,mounts:array}> */
    public static function list(): array
    {
        $docker = Env::bin('docker');
        [$c, $ids, $e] = Cmd::run([$docker, 'ps', '-aq', '--no-trunc']);
        if ($c !== 0) throw new \RuntimeException('docker ps fehlgeschlagen: ' . trim($e));
        $ids = array_values(array_filter(array_map('trim', explode("\n", $ids))));
        if (!$ids) return [];
        [$c, $json, $e] = Cmd::run(array_merge([$docker, 'inspect'], $ids));
        if ($c !== 0) throw new \RuntimeException('docker inspect fehlgeschlagen: ' . trim($e));
        $all = json_decode($json, true);
        if (!is_array($all)) throw new \RuntimeException('docker inspect lieferte kein JSON');
        $out = [];
        foreach ($all as $ct) {
            $name = ltrim((string)($ct['Name'] ?? ''), '/');
            if ($name === '') continue;
            $mounts = [];
            foreach ((array)($ct['Mounts'] ?? []) as $m) {
                if (($m['Type'] ?? '') !== 'bind') continue;
                $mounts[] = ['source' => Fs::norm((string)$m['Source']), 'destination' => (string)($m['Destination'] ?? ''), 'rw' => (bool)($m['RW'] ?? true)];
            }
            $out[$name] = [
                'name' => $name,
                'id' => (string)($ct['Id'] ?? ''),
                'running' => (bool)($ct['State']['Running'] ?? false),
                'status' => (string)($ct['State']['Status'] ?? ''),
                'image' => (string)($ct['Config']['Image'] ?? ''),
                'restart' => (string)($ct['HostConfig']['RestartPolicy']['Name'] ?? ''),
                'icon_label' => (string)($ct['Config']['Labels']['net.unraid.docker.icon'] ?? ''),
                'webui' => (string)($ct['Config']['Labels']['net.unraid.docker.webui'] ?? ''),
                'mounts' => $mounts,
            ];
        }
        uksort($out, 'strnatcasecmp');
        return $out;
    }

    public static function isRunning(string $name): bool
    {
        [$c, $o] = Cmd::run([Env::bin('docker'), 'inspect', '--format', '{{.State.Running}}', $name]);
        return $c === 0 && trim($o) === 'true';
    }

    public static function stop(string $name, int $timeout): bool
    {
        [$ok] = Cmd::ok([Env::bin('docker'), 'stop', '-t', (string)$timeout, $name], "Stoppen von $name");
        return $ok;
    }

    public static function start(string $name): bool
    {
        [$ok] = Cmd::ok([Env::bin('docker'), 'start', $name], "Starten von $name");
        return $ok;
    }

    public static function inspectRaw(string $name): ?string
    {
        [$c, $o] = Cmd::run([Env::bin('docker'), 'inspect', $name]);
        return $c === 0 ? $o : null;
    }
}
