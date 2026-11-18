<?php
/**
 * vmm-lib.php - shared library for vmm-ctl (CLI) and the VM Manager GUI.
 *
 * Single place that talks to vm-bhyve. No shell interpolation: proc_open with
 * an argv array, fixed subcommands only.
 */

declare(strict_types=1);

// Repository root = parent of bin/ (symlinks resolved).
if (!defined('VMM_ROOT')) {
    define('VMM_ROOT', dirname(dirname(realpath(__FILE__))));
}
if (!defined('VMM_CONF')) {
    define('VMM_CONF', VMM_ROOT . '/gui/ext/vmmngr/vmmngr.conf');
}

const VMM_VM_SYSTEM   = '/usr/local/sbin/vm';
const VMM_LIB_SYSTEM  = '/usr/local/lib/vm-bhyve';
const VMM_FW_SYSTEM   = '/usr/local/share/uefi-firmware/BHYVE_UEFI.fd';
const VMM_GRUB_SYSTEM = '/usr/local/sbin/grub-bhyve';
const VMM_TTYD_SYSTEM = '/usr/local/bin/ttyd';

function vmm_config(): array
{
    $defaults = [
        'appname'      => 'VM Manager',
        'version'      => '0.1.0-preview',
        'rootfolder'   => VMM_ROOT,
        'vm_source'    => 'auto',           // auto | system | bundle
        'bridges'      => [],
        'default_bridge' => '',
        'enable'       => true,
        'job_timeout'  => 900,
        'after_action' => 'stay',           // stay | log
        'job_keep'     => 100,              // finished jobs to keep (0 = none)
        'vm_dir_cache' => '',
    ];
    if (!is_file(VMM_CONF)) {
        return $defaults;
    }
    $data = json_decode((string)file_get_contents(VMM_CONF), true);
    if (!is_array($data)) {
        return $defaults;
    }
    return array_merge($defaults, $data);
}

function vmm_save_config(array $conf): bool
{
    $json = json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return file_put_contents(VMM_CONF, $json . "\n") !== false;
}

/* ------------------------------------------------------------------ */
/* provider resolution: never overlay an existing system installation  */
/* ------------------------------------------------------------------ */

function vmm_is_our_link(string $path): bool
{
    if (!is_link($path)) {
        return false;
    }
    $target = (string)readlink($path);
    return str_starts_with($target, VMM_ROOT . '/');
}

function vmm_resolve(string $component): array
{
    $conf = vmm_config();
    $pref = (string)($conf['vm_source'] ?? 'auto');

    $candidates = [
        'vm' => [
            'system' => [VMM_VM_SYSTEM, VMM_LIB_SYSTEM],
            'bundle' => [VMM_ROOT . '/vendor/vm-bhyve/sbin/vm', VMM_ROOT . '/vendor/vm-bhyve/lib'],
        ],
        'grub' => [
            'system' => [VMM_GRUB_SYSTEM],
            'bundle' => [VMM_ROOT . '/vendor/grub2-bhyve/grub-bhyve'],
        ],
        'firmware' => [
            'system' => [VMM_FW_SYSTEM],
            'bundle' => [VMM_ROOT . '/vendor/edk2-bhyve/BHYVE_UEFI.fd'],
        ],
        'ttyd' => [
            'system' => [VMM_TTYD_SYSTEM],
            'bundle' => [VMM_ROOT . '/vendor/ttyd/ttyd'],
        ],
    ];

    if (!isset($candidates[$component])) {
        return ['source' => 'unknown', 'path' => '', 'available' => false];
    }

    $check = static function (array $paths): bool {
        foreach ($paths as $p) {
            if (!is_file($p) && !is_dir($p)) {
                return false;
            }
        }
        return true;
    };

    // "system" only counts if it is a real file/dir, not our own symlink
    $systemOk = $check($candidates[$component]['system'])
        && !vmm_is_our_link($candidates[$component]['system'][0]);
    $bundleOk = $check($candidates[$component]['bundle']);

    $useSystem = match ($pref) {
        'system' => $systemOk,
        'bundle' => false,
        default  => $systemOk,
    };
    if ($useSystem) {
        return ['source' => 'system', 'path' => $candidates[$component]['system'][0], 'available' => true];
    }
    if ($bundleOk) {
        return ['source' => 'bundle', 'path' => $candidates[$component]['bundle'][0], 'available' => true];
    }
    return [
        'source' => 'missing',
        'path' => $candidates[$component]['system'][0],
        'available' => false,
    ];
}

/* ------------------------------------------------------------------ */
/* command execution                                                  */
/* ------------------------------------------------------------------ */

function vmm_run(array $argv, int $timeout = 30): array
{
    $vm = vmm_resolve('vm');
    if (!$vm['available']) {
        return ['rc' => 127, 'stdout' => '', 'stderr' => 'vm-bhyve not found', 'cmd' => ''];
    }

    $env = getenv();
    if ($env === false) {
        $env = [];
    }
    $grub = vmm_resolve('grub');
    if ($grub['source'] === 'bundle') {
        $env['PATH'] = VMM_ROOT . '/vendor/grub2-bhyve:' . ($env['PATH'] ?? '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
    }
    $env['LC_ALL'] = 'C';

    $cmd = array_merge([$vm['path']], $argv);
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($cmd, $spec, $pipes, null, $env);
    if (!is_resource($proc)) {
        return ['rc' => 127, 'stdout' => '', 'stderr' => 'proc_open failed', 'cmd' => implode(' ', $cmd)];
    }
    fclose($pipes[0]);
    $stdout = '';
    $stderr = '';
    $start = microtime(true);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    while (true) {
        $status = proc_get_status($proc);
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) - $start > $timeout) {
            proc_terminate($proc, 9);
            $stderr .= "\ntimeout after {$timeout}s";
            break;
        }
        usleep(20000);
    }
    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);
    if ($rc === -1) {
        $rc = 0;
    }
    return [
        'rc' => $rc,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'cmd' => implode(' ', array_map('escapeshellarg', $cmd)),
    ];
}

/* ------------------------------------------------------------------ */
/* parsers                                                            */
/* ------------------------------------------------------------------ */

function vmm_state_from_string(string $s): array
{
    $s = trim($s);
    if (preg_match('/^(Running|Suspended|Stopped|Starting|Locked)\b(?:\s*\((\d+)\))?/i', $s, $m)) {
        return ['state' => strtolower($m[1]), 'pid' => isset($m[2]) ? (int)$m[2] : 0, 'raw' => $s];
    }
    return ['state' => strtolower($s) === '' ? 'unknown' : strtolower($s), 'pid' => 0, 'raw' => $s];
}

function vmm_parse_list(string $text): array
{
    $lines = preg_split('/\R/', trim($text)) ?: [];
    if (count($lines) < 2) {
        return [];
    }
    $header = array_shift($lines);
    $cols = ['NAME', 'DATASTORE', 'LOADER', 'CPU', 'MEMORY', 'VNC', 'AUTO', 'STATE'];
    $offsets = [];
    foreach ($cols as $c) {
        $pos = strpos($header, $c);
        $offsets[$c] = $pos === false ? null : $pos;
    }
    $out = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $row = [];
        $known = array_values(array_filter($offsets, static fn($v) => $v !== null));
        $i = 0;
        foreach ($cols as $c) {
            $start = $offsets[$c];
            if ($start === null) {
                $row[strtolower($c)] = '';
                continue;
            }
            $next = null;
            foreach ($known as $k) {
                if ($k > $start) {
                    $next = $k;
                    break;
                }
            }
            $row[strtolower($c)] = $next === null
                ? trim(substr($line, $start))
                : trim(substr($line, $start, $next - $start));
            $i++;
        }
        if ($row['name'] === '') {
            continue;
        }
        $state = vmm_state_from_string($row['state'] ?? '');
        $row['state'] = $state['state'];
        $row['pid'] = $state['pid'];
        $row['state_raw'] = $state['raw'];
        $row['auto'] = stripos((string)($row['auto'] ?? ''), 'yes') === 0;
        $out[] = $row;
    }
    return $out;
}

function vmm_parse_info(string $text): array
{
    $out = ['general' => [], 'blocks' => [], 'raw' => $text];
    $lines = preg_split('/\R/', $text) ?: [];
    $section = null;
    $index = -1;
    $lastNum = [];
    foreach ($lines as $line) {
        if (preg_match('/^--+$/', $line) || preg_match('/^Virtual Machine:/', $line)) {
            continue;
        }
        if (preg_match('/^  ([a-z0-9][a-z0-9-]*)$/', $line, $m)) {
            $section = $m[1];
            $out['blocks'][] = ['type' => $section, 'lines' => [], 'data' => []];
            $index = count($out['blocks']) - 1;
            $lastNum[$section] = -1;
            continue;
        }
        if ($index >= 0 && preg_match('/^    ([a-z0-9][a-z0-9-]*):\s?(.*)$/', $line, $m)) {
            $key = $m[1];
            $val = trim($m[2]);
            // a repeated "number" key starts a new block of the same type
            if ($key === 'number' && isset($out['blocks'][$index]['data']['number'])) {
                $type = $out['blocks'][$index]['type'];
                $out['blocks'][] = ['type' => $type, 'lines' => [], 'data' => []];
                $index = count($out['blocks']) - 1;
            }
            if ($key === 'number') {
                $out['blocks'][$index]['data']['number'] = $val;
            } else {
                $out['blocks'][$index]['data'][$key] = $val;
            }
            continue;
        }
        if (preg_match('/^  ([a-z0-9][a-z0-9-]*):\s?(.*)$/', $line, $m)) {
            $out['general'][$m[1]] = trim($m[2]);
            continue;
        }
        if ($index >= 0 && trim($line) !== '') {
            $out['blocks'][$index]['lines'][] = trim($line);
        }
    }
    // build convenience indexes
    $out['sections'] = [];
    foreach ($out['blocks'] as $b) {
        $out['sections'][$b['type']][] = $b;
    }
    return $out;
}

function vmm_parse_table(string $text): array
{
    $lines = preg_split('/\R/', trim($text)) ?: [];
    if (count($lines) < 2) {
        return [];
    }
    $header = preg_split('/\s{2,}/', trim(array_shift($lines))) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = preg_split('/\s{2,}/', $line) ?: [];
        $row = [];
        foreach ($header as $i => $h) {
            $row[strtolower(trim($h))] = $parts[$i] ?? '';
        }
        if ($row !== []) {
            $out[] = $row;
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* higher level helpers                                               */
/* ------------------------------------------------------------------ */

function vmm_list(): array
{
    // note: "vm list -r" lists running guests only; we want everything
    $res = vmm_run(['list'], 30);
    return vmm_parse_list($res['stdout']);
}

function vmm_info(string $name): array
{
    if (!vmm_valid_name($name)) {
        return ['error' => 'invalid name'];
    }
    $res = vmm_run(['info', $name], 30);
    if ($res['rc'] !== 0) {
        return ['error' => trim($res['stderr'] ?: $res['stdout']), 'rc' => $res['rc']];
    }
    return vmm_parse_info($res['stdout']);
}

function vmm_valid_name(string $name): bool
{
    return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $name);
}

function vmm_size_to_bytes(string $size): int
{
    if (!preg_match('/^([0-9.]+)\s*([KMGTP]?)i?B?$/i', trim($size), $m)) {
        return 0;
    }
    $n = (float)$m[1];
    $mult = ['K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3, 'T' => 1024 ** 4, 'P' => 1024 ** 5];
    $u = strtoupper($m[2]);
    return (int)($n * ($mult[$u] ?? 1));
}

function vmm_human_bytes(int $bytes): string
{
    $units = ['B', 'K', 'M', 'G', 'T'];
    $i = 0;
    $v = (float)$bytes;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    return $i === 0 ? (string)$bytes : sprintf('%.1f%s', $v, $units[$i]);
}

function vmm_vm_version(): array
{
    $res = vmm_run(['version'], 15);
    $text = trim($res['stdout'] . "\n" . $res['stderr']);
    if (preg_match('/vm-bhyve:?[^\n]*?(v?([0-9][0-9a-z.\-]*))\s*\(rev\.?\s*([0-9]+)\)/i', $text, $m)) {
        return ['version' => $m[2], 'rev' => $m[3], 'raw' => $text];
    }
    return ['version' => '', 'rev' => '', 'raw' => $text];
}

function vmm_datastores(): array
{
    $res = vmm_run(['datastore', 'list'], 15);
    return vmm_parse_table($res['stdout']);
}

function vmm_switches(): array
{
    $res = vmm_run(['switch', 'list'], 15);
    return vmm_parse_table($res['stdout']);
}

function vmm_images(): array
{
    $res = vmm_run(['image', 'list'], 15);
    return vmm_parse_table($res['stdout']);
}

function vmm_vm_dir(): string
{
    $res = vmm_run(['get', 'all'], 15);
    $text = $res['stdout'];
    if (preg_match('/^vm_dir="?([^"\n]+)"?/m', $text, $m)) {
        return $m[1];
    }
    $rc = @shell_exec('/usr/bin/grep -E "^vm_dir=" /etc/rc.conf 2>/dev/null');
    if (is_string($rc) && preg_match('/vm_dir="?([^"\n]+)"?/', $rc, $m)) {
        return $m[1];
    }
    return '';
}

function vmm_selfcheck(): array
{
    $vm = vmm_resolve('vm');
    $grub = vmm_resolve('grub');
    $fw = vmm_resolve('firmware');
    $ttyd = vmm_resolve('ttyd');
    $out = [
        'timestamp' => date('c'),
        'platform' => trim((string)@file_get_contents('/etc/platform')),
        'product' => trim((string)@file_get_contents('/etc/prd.name')),
        'os' => trim((string)@shell_exec('uname -r')),
        'php' => PHP_VERSION,
        'root' => VMM_ROOT,
        'vm' => $vm,
        'grub' => $grub,
        'firmware' => $fw,
        'ttyd' => $ttyd,
        'vm_dir' => vmm_vm_dir(),
        'kld' => [
            'vmm' => trim((string)@shell_exec('kldstat -q -n vmm && echo loaded || echo missing')),
            'nmdm' => trim((string)@shell_exec('kldstat -q -n nmdm && echo loaded || echo missing')),
        ],
        'loader_conf' => [
            'file' => '/boot/loader.conf.local',
            'vmm' => (bool)preg_match('/^vmm_load=/m', (string)@file_get_contents('/boot/loader.conf.local')),
            'nmdm' => (bool)preg_match('/^nmdm_load=/m', (string)@file_get_contents('/boot/loader.conf.local')),
        ],
    ];
    $out['vm']['version'] = $vm['available'] ? vmm_vm_version() : [];
    $out['datastores'] = $vm['available'] ? vmm_datastores() : [];
    $out['switches'] = $vm['available'] ? vmm_switches() : [];
    $vms = $vm['available'] ? vmm_list() : [];
    $out['vm_count'] = count($vms);
    $out['vm_running'] = count(array_filter($vms, static fn($v) => ($v['state'] ?? '') === 'running'));
    if ($vm['available']) {
        $fwFile = $fw['source'] === 'system' ? VMM_FW_SYSTEM : VMM_ROOT . '/vendor/edk2-bhyve/BHYVE_UEFI.fd';
        $out['firmware']['file'] = $fwFile;
        $out['firmware']['mtime'] = is_file($fwFile) ? date('Y-m-d H:i', (int)filemtime($fwFile)) : '';
        $out['firmware']['size'] = is_file($fwFile) ? filesize($fwFile) : 0;
    }
    return $out;
}

function vmm_json(array $data, int $code = 0): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------------ */
/* job engine                                                         */
/* ------------------------------------------------------------------ */

const VMM_JOBDIR  = VMM_ROOT . '/state/jobs';
const VMM_LOCKDIR = VMM_ROOT . '/state/locks';
const VMM_ACTIONS = [
    'start'    => ['argv' => ['start'],    'timeout' => 300, 'needs' => 'stopped'],
    'stop'     => ['argv' => ['stop'],     'timeout' => 180, 'needs' => 'running'],
    'restart'  => ['argv' => ['restart'],  'timeout' => 300, 'needs' => 'running'],
    'reset'    => ['argv' => ['reset', '-f'], 'timeout' => 120, 'needs' => 'running'],
    'poweroff' => ['argv' => ['poweroff', '-f'], 'timeout' => 120, 'needs' => 'running'],
    'destroy'  => ['argv' => ['destroy', '-f'], 'timeout' => 300, 'needs' => 'stopped'],
];

function vmm_ensure_state_dirs(): void
{
    foreach ([VMM_JOBDIR, VMM_LOCKDIR] as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
    }
}

function vmm_vm_state(string $name): string
{
    foreach (vmm_list() as $v) {
        if (($v['name'] ?? '') === $name) {
            return (string)$v['state'];
        }
    }
    return 'absent';
}

function vmm_ram_budget(): array
{
    $conf = vmm_config();
    $limitPct = (int)($conf['ram_budget_pct'] ?? 85);
    $total = 0;
    $out = trim((string)@shell_exec('/sbin/sysctl -n hw.physmem 2>/dev/null'));
    if ($out !== '' && ctype_digit($out)) {
        $total = (int)$out;
    }
    if ($total <= 0) {
        $total = 8 * 1024 ** 3;
    }
    return ['total' => $total, 'limit' => (int)($total * $limitPct / 100), 'pct' => $limitPct];
}

function vmm_ram_used(): int
{
    $used = 0;
    foreach (vmm_list() as $v) {
        if (($v['state'] ?? '') === 'running') {
            $used += vmm_size_to_bytes((string)($v['memory'] ?? '0'));
        }
    }
    return $used;
}

function vmm_action_check(string $verb, string $name, string $confirm = ''): array
{
    if (!isset(VMM_ACTIONS[$verb])) {
        return ['ok' => false, 'error' => 'unknown action'];
    }
    if (!vmm_valid_name($name)) {
        return ['ok' => false, 'error' => 'invalid vm name'];
    }
    $state = vmm_vm_state($name);
    if ($state === 'absent') {
        return ['ok' => false, 'error' => 'vm not found'];
    }
    $needs = VMM_ACTIONS[$verb]['needs'];
    if ($needs === 'running' && $state !== 'running') {
        return ['ok' => false, 'error' => "vm is {$state}, not running"];
    }
    if ($needs === 'stopped' && $state === 'running') {
        return ['ok' => false, 'error' => 'vm is running; stop it first'];
    }
    if ($verb === 'start') {
        $budget = vmm_ram_budget();
        $used = vmm_ram_used();
        $need = 0;
        foreach (vmm_list() as $v) {
            if (($v['name'] ?? '') === $name) {
                $need = vmm_size_to_bytes((string)($v['memory'] ?? '0'));
            }
        }
        if ($need > 0 && $used + $need > $budget['limit']) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'RAM budget: %s in use + %s requested exceeds limit of %s (%d%%)',
                    vmm_human_bytes($used),
                    vmm_human_bytes($need),
                    vmm_human_bytes($budget['limit']),
                    $budget['pct']
                ),
            ];
        }
    }
    if ($verb === 'destroy') {
        if ($confirm !== $name) {
            return ['ok' => false, 'error' => 'confirmation mismatch: type the vm name exactly'];
        }
    }
    return ['ok' => true, 'state' => $state];
}

function vmm_job_path(string $id): string
{
    return VMM_JOBDIR . '/' . $id . '.json';
}

function vmm_job_write(array $job): void
{
    $job['updated'] = time();
    $tmp = vmm_job_path($job['id']) . '.tmp';
    file_put_contents($tmp, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, vmm_job_path($job['id']));
}

function vmm_job_get(string $id): ?array
{
    if (!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/', $id)) {
        return null;
    }
    $f = vmm_job_path($id);
    if (!is_file($f)) {
        return null;
    }
    $job = json_decode((string)file_get_contents($f), true);
    return is_array($job) ? $job : null;
}

function vmm_job_list(int $limit = 50, string $vm = ''): array
{
    $files = glob(VMM_JOBDIR . '/*.json') ?: [];
    rsort($files);
    $out = [];
    foreach ($files as $f) {
        $job = json_decode((string)file_get_contents($f), true);
        if (!is_array($job)) {
            continue;
        }
        if ($vm !== '' && ($job['vm'] ?? '') !== $vm) {
            continue;
        }
        $out[] = $job;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/**
 * Delete old finished jobs (FIFO). Queued/running jobs are never touched.
 * Returns ['removed' => int, 'kept' => int].
 */
function vmm_job_prune(int $keep = 100, bool $onlyFinished = true): array
{
    vmm_ensure_state_dirs();
    $files = glob(VMM_JOBDIR . '/*.json') ?: [];
    rsort($files); // newest first
    $kept = 0;
    $removed = 0;
    foreach ($files as $i => $f) {
        $job = json_decode((string)file_get_contents($f), true);
        if (!is_array($job)) {
            @unlink($f);
            continue;
        }
        $state = (string)($job['state'] ?? '');
        $running = in_array($state, ['queued', 'running'], true);
        if ($running) {
            $kept++;
            continue;
        }
        if ($kept < $keep) {
            $kept++;
            continue;
        }
        $id = (string)($job['id'] ?? basename($f, '.json'));
        @unlink($f);
        @unlink(VMM_JOBDIR . '/' . $id . '.log');
        @unlink(VMM_JOBDIR . '/' . $id . '.cancel');
        $removed++;
    }
    return ['removed' => $removed, 'kept' => $kept];
}

function vmm_job_log(string $id, int $maxBytes = 65536): string
{
    $f = VMM_JOBDIR . '/' . $id . '.log';
    if (!is_file($f)) {
        return '';
    }
    $size = filesize($f);
    if ($size <= $maxBytes) {
        return (string)file_get_contents($f);
    }
    $fh = fopen($f, 'rb');
    fseek($fh, $maxBytes * -1, SEEK_END);
    $data = (string)stream_get_contents($fh);
    fclose($fh);
    return "... (truncated)\n" . $data;
}

/**
 * Where to go after an action created a job: the job log, or back to the page
 * the action came from (default, keeps the dashboard in view).
 */
function vmm_after_job_target(string $jobId, string $back): string
{
    $conf = vmm_config();
    if (($conf['after_action'] ?? 'stay') === 'log') {
        return 'vmmngr_jobs.php?id=' . urlencode($jobId);
    }
    $sep = str_contains($back, '?') ? '&' : '?';
    return $back . $sep . 'job=' . urlencode($jobId);
}

function vmm_job_status_html(string $id): string
{
    $job = vmm_job_get($id);
    if ($job === null) {
        return '';
    }
    $state = (string)$job['state'];
    $cls = $state === 'failed' ? 'vmm-err' : 'vmm-ok';
    return sprintf(
        '<div style="margin:8px 0;padding:8px 10px;border:1px solid rgba(127,127,127,.35);border-radius:4px;">'
        . 'Job <span class="vmm-mono">%s</span> · %s %s · <span class="%s">%s</span> · <a href="vmmngr_jobs.php?id=%s">%s</a></div>',
        htmlspecialchars($id, ENT_QUOTES),
        htmlspecialchars((string)$job['verb'], ENT_QUOTES),
        htmlspecialchars((string)$job['vm'], ENT_QUOTES),
        $cls,
        htmlspecialchars($state . ($job['rc'] !== null ? ' (rc ' . $job['rc'] . ')' : ''), ENT_QUOTES),
        urlencode($id),
        gettext('Log')
    );
}

function vmm_job_running(string $id): bool
{
    $job = vmm_job_get($id);
    return $job !== null && in_array($job['state'], ['queued', 'running'], true);
}

function vmm_job_new(string $verb, string $name, array $argv = [], int $timeout = 0, array $meta = []): array
{
    vmm_ensure_state_dirs();
    $id = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $job = [
        'id'      => $id,
        'verb'    => $verb,
        'vm'      => $name,
        'state'   => 'queued',
        'created' => time(),
        'started' => null,
        'ended'   => null,
        'pid'     => null,
        'rc'      => null,
        'argv'    => $argv,
        'timeout' => $timeout,
        'error'   => '',
        'by'      => (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'),
    ];
    foreach ($meta as $k => $v) {
        if (is_scalar($v)) {
            $job[$k] = $v;
        }
    }
    vmm_job_write($job);
    @touch(VMM_JOBDIR . '/' . $id . '.log');

    // the web SAPI is php-cgi; jobs must be started with the CLI binary
    $php = is_file('/usr/local/bin/php') ? '/usr/local/bin/php' : PHP_BINARY;
    $cmd = sprintf(
        '/usr/bin/nohup %s %s %s > /dev/null 2>&1 &',
        escapeshellarg($php),
        escapeshellarg(VMM_ROOT . '/bin/vmm-jobd'),
        escapeshellarg($id)
    );
    if (function_exists('exec')) {
        exec($cmd);
    } else {
        // exec() can be disabled in hardened PHP builds; shell_exec is enough here
        @shell_exec($cmd);
    }
    return $job;
}

function vmm_job_recover(): int
{
    $fixed = 0;
    foreach (vmm_job_list(200) as $job) {
        if (($job['state'] ?? '') !== 'running') {
            continue;
        }
        $pid = (int)($job['pid'] ?? 0);
        $alive = vmm_pid_alive($pid);
        if (!$alive) {
            $job['state'] = 'failed';
            $job['error'] = 'interrupted (process gone)';
            $job['ended'] = time();
            vmm_job_write($job);
            $fixed++;
        }
    }
    return $fixed;
}

function vmm_dataset_for_vm(string $name): string
{
    $vmDir = vmm_vm_dir();
    if ($vmDir === '') {
        return '';
    }
    $base = preg_replace('/^zfs:/', '', $vmDir);
    $base = rtrim($base, '/');
    return $base === '' ? '' : $base . '/' . $name;
}

/**
 * Release ZFS holds that would block destroying a VM's datasets.
 * Returns the list of released "snapshot tag" pairs.
 */
function vmm_release_holds(string $dataset): array
{
    $released = [];
    if ($dataset === '' || !preg_match('/^[A-Za-z0-9._:\/-]+$/', $dataset)) {
        return $released;
    }

    // "zfs holds" only works on snapshots, so walk the snapshot tree first.
    exec('/sbin/zfs list -H -o name -t snapshot -r ' . escapeshellarg($dataset) . ' 2>/dev/null', $snaps, $rc);
    if ($rc !== 0) {
        return $released;
    }
    $snaps = array_slice(array_filter(array_map('trim', $snaps)), 0, 2000);

    foreach ($snaps as $snap) {
        exec('/sbin/zfs holds ' . escapeshellarg($snap) . ' 2>/dev/null', $lines, $hrc);
        if ($hrc !== 0 || $lines === []) {
            continue;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'NAME') === 0) {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 2) {
                continue;
            }
            [$holdSnap, $tag] = $parts;
            exec('/sbin/zfs release ' . escapeshellarg($tag) . ' ' . escapeshellarg($holdSnap) . ' 2>&1', $out, $r);
            if ($r === 0) {
                $released[] = $holdSnap . ' ' . $tag;
            }
        }
        $lines = [];
    }
    return $released;
}

function vmm_data_dir(string $sub): string
{
    $vmDir = vmm_vm_dir();
    $base = preg_replace('/^zfs:/', '', $vmDir);
    $base = rtrim($base, '/');
    if ($base === '') {
        return '';
    }
    if ($base[0] !== '/') {
        $base = '/mnt/' . $base;
    }
    return $base . '/' . $sub;
}

function vmm_file_list(string $dir, array $exts): array
{
    if ($dir === '' || !is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $path = $dir . '/' . $f;
        if (!is_file($path)) {
            continue;
        }
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if ($exts !== [] && !in_array($ext, $exts, true)) {
            continue;
        }
        $out[] = ['name' => $f, 'size' => filesize($path), 'mtime' => filemtime($path), 'ext' => $ext];
    }
    usort($out, static fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

function vmm_safe_basename(string $name): string
{
    $name = basename($name);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._()+-]{0,120}$/', $name)) {
        return '';
    }
    return $name;
}

function vmm_delete_data_file(string $dir, string $name): array
{
    $safe = vmm_safe_basename($name);
    if ($safe === '' || $dir === '') {
        return ['ok' => false, 'error' => 'invalid file name'];
    }
    $path = $dir . '/' . $safe;
    if (!is_file($path)) {
        return ['ok' => false, 'error' => 'file not found'];
    }
    return @unlink($path)
        ? ['ok' => true, 'deleted' => $safe]
        : ['ok' => false, 'error' => 'cannot delete'];
}

/**
 * Turn a bhyve VNC bind address ("0.0.0.0:5901") into something a human can
 * actually connect to. 0.0.0.0 is a listen address, not a destination.
 */
function vmm_novnc_ready(): array
{
    $bin = '/usr/local/bin/websockify';
    $web = '/usr/local/libexec/novnc';
    return [
        'binary' => $bin,
        'web' => $web,
        'ok' => is_executable($bin) && is_file($web . '/vnc.html'),
    ];
}

function vmm_novnc_state_file(string $vm): string
{
    return VMM_ROOT . '/state/novnc/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $vm) . '.json';
}

function vmm_novnc_status(string $vm): ?array
{
    $f = vmm_novnc_state_file($vm);
    if (!is_file($f)) {
        return null;
    }
    $st = json_decode((string)file_get_contents($f), true);
    if (!is_array($st) || !vmm_pid_alive((int)($st['pid'] ?? 0))) {
        @unlink($f);
        return null;
    }
    return $st;
}

function vmm_novnc_stop(string $vm): array
{
    $st = vmm_novnc_status($vm);
    if ($st === null) {
        return ['ok' => true, 'stopped' => false];
    }
    exec('/bin/kill ' . (int)$st['pid'] . ' >/dev/null 2>&1');
    usleep(300000);
    exec('/bin/kill -9 ' . (int)$st['pid'] . ' >/dev/null 2>&1');
    @unlink(vmm_novnc_state_file($vm));
    return ['ok' => true, 'stopped' => true];
}

/**
 * Serve the VM's VNC screen through noVNC (websockify + static web root).
 */
function vmm_novnc_start(string $vm, string $bindHost = ''): array
{
    $ready = vmm_novnc_ready();
    if (!$ready['ok']) {
        return ['ok' => false, 'error' => 'noVNC/websockify not installed (pkg install novnc py312-websockify)'];
    }
    if (vmm_vm_state($vm) !== 'running') {
        return ['ok' => false, 'error' => 'vm is not running'];
    }
    $info = vmm_info($vm);
    $vncPort = 0;
    foreach (($info['sections']['console-ports'] ?? []) as $b) {
        if (!empty($b['data']['vnc']) && preg_match('/:(\d+)$/', (string)$b['data']['vnc'], $m)) {
            $vncPort = (int)$m[1];
            break;
        }
    }
    if ($vncPort === 0) {
        return ['ok' => false, 'error' => 'this vm has no VNC screen (graphics="no"?)'];
    }
    if ($bindHost === '') {
        $bindHost = vmm_vnc_host_for_vm($vm);
    }
    $existing = vmm_novnc_status($vm);
    if ($existing !== null) {
        return ['ok' => true] + $existing;
    }

    $port = 0;
    for ($p = 6080; $p <= 6099; $p++) {
        $fp = @fsockopen('127.0.0.1', $p, $errno, $errstr, 0.2);
        if ($fp === false) {
            $port = $p;
            break;
        }
        fclose($fp);
    }
    if ($port === 0) {
        return ['ok' => false, 'error' => 'no free port in 6080-6099'];
    }

    $dir = VMM_ROOT . '/state/novnc';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $log = $dir . '/' . $vm . '.log';
    $cmd = sprintf(
        '/usr/bin/nohup %s --web %s %s %s > %s 2>&1 &',
        escapeshellarg($ready['binary']),
        escapeshellarg($ready['web']),
        escapeshellarg($bindHost . ':' . $port),
        escapeshellarg('127.0.0.1:' . $vncPort),
        escapeshellarg($log)
    );
    if (function_exists('exec')) {
        exec($cmd);
    } else {
        @shell_exec($cmd);
    }
    usleep(700000);

    $pid = 0;
    exec('/usr/bin/pgrep -f ' . escapeshellarg('websockify --web ' . $ready['web'] . ' ' . $bindHost . ':' . $port) . ' 2>/dev/null', $out, $rc);
    if ($rc === 0 && !empty($out[0])) {
        $pid = (int)$out[0];
    }
    if ($pid === 0) {
        return ['ok' => false, 'error' => 'websockify did not start, see ' . $log];
    }

    $st = [
        'vm' => $vm,
        'pid' => $pid,
        'port' => $port,
        'host' => $bindHost,
        'vnc_port' => $vncPort,
        'started' => time(),
        'url' => sprintf('http://%s:%d/vnc.html?autoconnect=1&resize=scale&path=websockify', $bindHost, $port),
    ];
    file_put_contents(vmm_novnc_state_file($vm), json_encode($st, JSON_PRETTY_PRINT));
    return ['ok' => true] + $st;
}

function vmm_console_state_file(string $vm): string
{
    return VMM_ROOT . '/state/console/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $vm) . '.json';
}

function vmm_console_status(string $vm): ?array
{
    $f = vmm_console_state_file($vm);
    if (!is_file($f)) {
        return null;
    }
    $st = json_decode((string)file_get_contents($f), true);
    if (!is_array($st)) {
        return null;
    }
    if (!vmm_pid_alive((int)($st['pid'] ?? 0))) {
        @unlink($f);
        return null;
    }
    return $st;
}

function vmm_console_stop(string $vm): array
{
    $st = vmm_console_status($vm);
    if ($st === null) {
        return ['ok' => true, 'stopped' => false];
    }
    exec('/bin/kill ' . (int)$st['pid'] . ' >/dev/null 2>&1');
    usleep(300000);
    exec('/bin/kill -9 ' . (int)$st['pid'] . ' >/dev/null 2>&1');
    @unlink(vmm_console_state_file($vm));
    return ['ok' => true, 'stopped' => true];
}

/**
 * Start a ttyd instance that serves the VM's serial console (nmdm device).
 */
function vmm_console_start(string $vm, string $bindHost = ''): array
{
    $ttyd = vmm_resolve('ttyd');
    if (!$ttyd['available']) {
        return ['ok' => false, 'error' => 'ttyd not found (install www/ttyd or bundle it)'];
    }
    if (vmm_vm_state($vm) !== 'running') {
        return ['ok' => false, 'error' => 'vm is not running'];
    }
    $info = vmm_info($vm);
    $dev = '';
    foreach (($info['sections']['console-ports'] ?? []) as $b) {
        if (!empty($b['data']['com1'])) {
            $dev = (string)$b['data']['com1'];
            break;
        }
    }
    if ($dev === '' || !file_exists($dev)) {
        return ['ok' => false, 'error' => 'no serial console device (com1) for this vm'];
    }
    if ($bindHost === '') {
        $bindHost = vmm_vnc_host_for_vm($vm);
    }

    // always start fresh (old instances may use the credential-based layout)
    if (vmm_console_status($vm) !== null) {
        vmm_console_stop($vm);
    }

    $port = 0;
    for ($p = 7681; $p <= 7699; $p++) {
        $fp = @fsockopen('127.0.0.1', $p, $errno, $errstr, 0.2);
        if ($fp === false) {
            $port = $p;
            break;
        }
        fclose($fp);
    }
    if ($port === 0) {
        return ['ok' => false, 'error' => 'no free port in 7681-7699'];
    }

    // ttyd has no session integration, so the base path acts as a bearer token.
    // This avoids the browser's basic-auth dialog blocking the embedded frame.
    $token = bin2hex(random_bytes(8));
    $dir = VMM_ROOT . '/state/console';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $log = $dir . '/' . $vm . '.log';
    $cmd = sprintf(
        '/usr/bin/nohup %s -p %d -i %s -b %s -W /usr/bin/cu -l %s > %s 2>&1 &',
        escapeshellarg($ttyd['path']),
        $port,
        escapeshellarg($bindHost),
        escapeshellarg('/' . $token . '/'),
        escapeshellarg($dev),
        escapeshellarg($log)
    );
    if (function_exists('exec')) {
        exec($cmd);
    } else {
        @shell_exec($cmd);
    }
    usleep(600000);

    $pid = 0;
    exec('/usr/bin/pgrep -f ' . escapeshellarg('ttyd -p ' . $port) . ' 2>/dev/null', $out, $rc);
    if ($rc === 0 && !empty($out[0])) {
        $pid = (int)$out[0];
    }
    if ($pid === 0) {
        return ['ok' => false, 'error' => 'ttyd did not start, see ' . $log];
    }

    $st = [
        'vm' => $vm,
        'pid' => $pid,
        'port' => $port,
        'host' => $bindHost,
        'token' => $token,
        'device' => $dev,
        'started' => time(),
        'url' => sprintf('http://%s:%d/%s/', $bindHost, $port, $token),
    ];
    file_put_contents(vmm_console_state_file($vm), json_encode($st, JSON_PRETTY_PRINT));
    return ['ok' => true] + $st;
}

function vmm_vnc_connect(string $bind, string $host = ''): string
{
    $bind = trim($bind);
    if ($bind === '' || $bind === '-') {
        return '';
    }
    [$bindHost, $port] = array_pad(explode(':', $bind, 2), 2, '');
    $port = (int)$port;
    if ($port <= 0) {
        return '';
    }
    if ($bindHost !== '0.0.0.0' && $bindHost !== '' && $bindHost !== '*') {
        return $bindHost . ':' . $port;
    }
    if ($host === '') {
        $host = trim((string)@shell_exec('hostname -f 2>/dev/null')) ?: (gethostname() ?: 'localhost');
    }
    return $host . ':' . $port;
}

/**
 * IP of the bridge that carries this VM's traffic (the address a client on
 * that network actually reaches). Falls back to the host name.
 */
function vmm_iface_ipv4(string $iface): string
{
    if ($iface === '' || $iface === 'n/a') {
        return '';
    }
    $out = trim((string)@shell_exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' inet 2>/dev/null'));
    return preg_match('/inet (\d+\.\d+\.\d+\.\d+)/', $out, $m) ? $m[1] : '';
}

function vmm_vnc_host_for_vm(string $name): string
{
    $switchName = '';
    $info = vmm_info($name);
    foreach (($info['sections']['network-interface'] ?? []) as $nic) {
        if (!empty($nic['data']['virtual-switch'])) {
            $switchName = (string)$nic['data']['virtual-switch'];
            break;
        }
    }
    $iface = '';
    foreach (vmm_switches() as $sw) {
        if ($switchName === '' || (string)($sw['name'] ?? '') === $switchName) {
            $iface = (string)($sw['iface'] ?? '');
            if ($iface !== '' && $iface !== 'n/a') {
                break;
            }
        }
    }
    if ($iface !== '') {
        $ip = vmm_iface_ipv4($iface);
        if ($ip !== '') {
            return $ip;
        }
    }
    return trim((string)@shell_exec('hostname -f 2>/dev/null')) ?: (gethostname() ?: 'localhost');
}

function vmm_vm_conf_path(string $name): string
{
    $dir = vmm_data_dir('');
    $dir = rtrim($dir, '/');
    if ($dir === '' || !vmm_valid_name($name)) {
        return '';
    }
    return $dir . '/' . $name . '/' . $name . '.conf';
}

function vmm_attached_isos(string $name): array
{
    $conf = vmm_vm_conf_path($name);
    if ($conf === '' || !is_file($conf)) {
        return [];
    }
    $out = [];
    $cur = null;
    foreach (preg_split('/\R/', (string)file_get_contents($conf)) ?: [] as $line) {
        if (preg_match('/^disk(\d+)_type="(ahci-cd|ahci-hd)"/', trim($line), $m)) {
            $cur = (int)$m[1];
            $out[$cur] = ['slot' => $cur, 'path' => ''];
        } elseif ($cur !== null && preg_match('/^disk' . $cur . '_name="([^"]+)"/', trim($line), $m2)) {
            $out[$cur]['path'] = $m2[1];
        }
    }
    return array_values(array_filter($out, static fn($d) => ($d['path'] ?? '') !== ''));
}

/**
 * Read a VM's config file into a key => value map (quoted values, comments kept
 * out of the map).
 */
function vmm_conf_get(string $name): array
{
    $conf = vmm_vm_conf_path($name);
    if ($conf === '' || !is_file($conf)) {
        return [];
    }
    $out = [];
    foreach (preg_split('/\R/', (string)file_get_contents($conf)) ?: [] as $line) {
        if (preg_match('/^([A-Za-z0-9_]+)="([^"]*)"\s*$/', trim($line), $m)) {
            $out[$m[1]] = $m[2];
        } elseif (preg_match('/^([A-Za-z0-9_]+)=([^"\s].*)\s*$/', trim($line), $m)) {
            $out[$m[1]] = $m[2];
        }
    }
    return $out;
}

/**
 * Update or insert config keys, keeping every other line untouched.
 * Returns ['ok'=>bool, 'changed'=>[...]].
 */
function vmm_conf_set(string $name, array $pairs): array
{
    $conf = vmm_vm_conf_path($name);
    if ($conf === '' || !is_file($conf)) {
        return ['ok' => false, 'error' => 'vm config not found'];
    }
    $lines = preg_split('/\R/', (string)file_get_contents($conf)) ?: [];
    $changed = [];
    foreach ($pairs as $key => $value) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$key)) {
            continue;
        }
        $value = (string)$value;
        $isNumeric = preg_match('/^[0-9]+[A-Za-z]?$/', $value) === 1 && preg_match('/^(cpu|memory)$/', (string)$key) === 1;
        $line = $isNumeric ? $key . '=' . $value : $key . '="' . $value . '"';
        $found = false;
        foreach ($lines as $i => $existing) {
            if (preg_match('/^' . preg_quote((string)$key, '/') . '=/', trim($existing))) {
                if (trim($existing) !== $line) {
                    $lines[$i] = $line;
                    $changed[] = (string)$key;
                }
                $found = true;
                break;
            }
        }
        if (!$found) {
            $lines[] = $line;
            $changed[] = (string)$key;
        }
    }
    if ($changed === []) {
        return ['ok' => true, 'changed' => []];
    }
    @copy($conf, $conf . '.vmmngr.bak');
    if (@file_put_contents($conf, implode("\n", $lines) . "\n") === false) {
        return ['ok' => false, 'error' => 'cannot write vm config'];
    }
    return ['ok' => true, 'changed' => $changed];
}

/**
 * Attach ISOs as read-only CD devices and drop any previously attached ones.
 * Returns ['added' => [...], 'removed' => [...]]
 */
function vmm_iso_attach(string $name, array $isos): array
{
    $conf = vmm_vm_conf_path($name);
    if ($conf === '' || !is_file($conf)) {
        return ['error' => 'vm config not found'];
    }
    $isos = array_values(array_filter($isos, static fn($p) => $p !== ''));
    foreach ($isos as $iso) {
        if (!is_file($iso) || !str_ends_with(strtolower($iso), '.iso')) {
            return ['error' => 'iso not found: ' . basename($iso)];
        }
    }

    $lines = preg_split('/\R/', (string)file_get_contents($conf)) ?: [];
    $kept = [];
    $removed = [];
    $skip = null;
    foreach ($lines as $line) {
        if (preg_match('/^disk(\d+)_type="ahci-cd"\s*$/', trim($line), $m)) {
            $skip = (int)$m[1];
            $removed[] = 'disk' . $skip;
            continue;
        }
        if ($skip !== null && preg_match('/^disk' . $skip . '_/', trim($line))) {
            continue;
        }
        $skip = null;
        $kept[] = $line;
    }

    $max = -1;
    foreach ($kept as $line) {
        if (preg_match('/^disk(\d+)_/', trim($line), $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    $added = [];
    foreach ($isos as $iso) {
        $max++;
        $kept[] = 'disk' . $max . '_type="ahci-cd"';
        $kept[] = 'disk' . $max . '_dev="custom"';
        $kept[] = 'disk' . $max . '_name="' . $iso . '"';
        $added[] = 'disk' . $max . ' = ' . basename($iso);
    }

    if (@copy($conf, $conf . '.vmmngr.bak') === false) {
        return ['error' => 'cannot back up vm config'];
    }
    if (@file_put_contents($conf, implode("\n", $kept) . "\n") === false) {
        return ['error' => 'cannot write vm config'];
    }
    return ['added' => $added, 'removed' => $removed];
}

function vmm_iso_detach(string $name): array
{
    $conf = vmm_vm_conf_path($name);
    if ($conf === '' || !is_file($conf)) {
        return ['error' => 'vm config not found'];
    }
    $lines = preg_split('/\R/', (string)file_get_contents($conf)) ?: [];
    $kept = [];
    $removed = [];
    $skip = null;
    foreach ($lines as $line) {
        if (preg_match('/^disk(\d+)_type="ahci-cd"\s*$/', trim($line), $m)) {
            $skip = (int)$m[1];
            $removed[] = 'disk' . $skip;
            continue;
        }
        if ($skip !== null && preg_match('/^disk' . $skip . '_/', trim($line))) {
            continue;
        }
        $skip = null;
        $kept[] = $line;
    }
    if ($removed === []) {
        return ['removed' => []];
    }
    @copy($conf, $conf . '.vmmngr.bak');
    @file_put_contents($conf, implode("\n", $kept) . "\n");
    return ['removed' => $removed];
}

function vmm_valid_template_name(string $name): bool
{
    return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._()+\-]{0,120}\.conf$/', $name);
}

function vmm_template_get(string $name): array
{
    $dir = vmm_data_dir('.templates');
    $safe = vmm_safe_basename($name);
    if ($safe === '' || !str_ends_with($safe, '.conf')) {
        return ['ok' => false, 'error' => 'invalid template name'];
    }
    $path = $dir . '/' . $safe;
    if (!is_file($path)) {
        return ['ok' => false, 'error' => 'template not found'];
    }
    return ['ok' => true, 'name' => $safe, 'content' => (string)file_get_contents($path)];
}

function vmm_template_save(string $name, string $content): array
{
    $dir = vmm_data_dir('.templates');
    $safe = vmm_safe_basename($name);
    if ($safe === '' || !str_ends_with($safe, '.conf')) {
        return ['ok' => false, 'error' => 'invalid template name'];
    }
    if (strlen($content) > 65536) {
        return ['ok' => false, 'error' => 'template too large'];
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ok = @file_put_contents($dir . '/' . $safe, $content);
    return $ok === false ? ['ok' => false, 'error' => 'cannot write template'] : ['ok' => true, 'name' => $safe];
}

function vmm_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    exec('/bin/kill -0 ' . $pid . ' > /dev/null 2>&1', $o, $rc);
    return $rc === 0;
}

function vmm_job_cancel(string $id): array
{
    $job = vmm_job_get($id);
    if ($job === null) {
        return ['ok' => false, 'error' => 'job not found'];
    }
    if (!in_array($job['state'], ['queued', 'running'], true)) {
        return ['ok' => false, 'error' => 'job already finished'];
    }
    @touch(VMM_JOBDIR . '/' . $id . '.cancel');
    return ['ok' => true, 'id' => $id];
}
