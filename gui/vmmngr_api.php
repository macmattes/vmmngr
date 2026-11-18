<?php
/*
 * vmmngr_api.php - JSON endpoint for the VM Manager GUI.
 *
 * GET  ?action=list|info|selfcheck|job|jobs
 * POST ?action=action  (verb, vm, confirm)  - mutations are POST only
 * POST ?action=cancel  (id)
 *
 * Requires an authenticated XigmaNAS session with admin rights.
 * Any failure is reported as JSON and written to log/api-error.log, because
 * the web SAPI runs with display_errors=Off and log_errors=Off.
 */

require_once 'vmmngr-boot.inc';

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

function vmm_api_log(string $msg): void
{
    @file_put_contents(VMM_ROOT . '/log/api-error.log', date('c') . ' ' . $msg . "\n", FILE_APPEND);
}

set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
    if (error_reporting() & $no) {
        vmm_api_log(sprintf('warning: %s in %s:%d', $str, $file, $line));
    }
    return false;
});

register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null) {
        return;
    }
    if (!in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    vmm_api_log(sprintf('fatal: %s in %s:%d', $e['message'], $e['file'], $e['line']));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => 'internal error: ' . $e['message']]);
});

if (!session::is_admin()) {
    vmm_json(['ok' => false, 'error' => 'forbidden'], 403);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string)($_REQUEST['action'] ?? '');
$name = (string)($_REQUEST['vm'] ?? $_REQUEST['name'] ?? '');
$confirm = (string)($_REQUEST['confirm'] ?? '');
$id = (string)($_REQUEST['id'] ?? '');

try {
    switch ($action) {
        case 'list':
            $vms = vmm_list();
            vmm_json(['ok' => true, 'count' => count($vms), 'vms' => $vms]);
            // no break

        case 'info':
            if (!vmm_valid_name($name)) {
                vmm_json(['ok' => false, 'error' => 'invalid name'], 400);
            }
            $info = vmm_info($name);
            if (isset($info['error'])) {
                vmm_json(['ok' => false, 'error' => $info['error']], 500);
            }
            vmm_json(['ok' => true, 'vm' => $name, 'info' => $info]);
            // no break

        case 'selfcheck':
            vmm_json(['ok' => true, 'selfcheck' => vmm_selfcheck()]);
            // no break

        case 'action':
            if ($method !== 'POST') {
                vmm_json(['ok' => false, 'error' => 'POST required'], 405);
            }
            $verb = (string)($_POST['verb'] ?? '');
            $check = vmm_action_check($verb, $name, $confirm);
            if (!$check['ok']) {
                vmm_json(['ok' => false, 'error' => $check['error']], 409);
            }
            $job = vmm_job_new($verb, $name);
            vmm_json(['ok' => true, 'job' => $job], 202);
            // no break

        case 'cancel':
            if ($method !== 'POST') {
                vmm_json(['ok' => false, 'error' => 'POST required'], 405);
            }
            $res = vmm_job_cancel($id);
            vmm_json($res, $res['ok'] ? 200 : 409);
            // no break

        case 'job':
            $job = vmm_job_get($id);
            if ($job === null) {
                vmm_json(['ok' => false, 'error' => 'job not found'], 404);
            }
            $job['log'] = vmm_job_log($id);
            vmm_json(['ok' => true, 'job' => $job]);
            // no break

        case 'jobs':
            vmm_json(['ok' => true, 'jobs' => vmm_job_list(30, $name)]);
            // no break

        case 'diag':
            vmm_json([
                'ok' => true,
                'diag' => [
                    'php_binary' => PHP_BINARY,
                    'cli_binary' => is_file('/usr/local/bin/php') ? '/usr/local/bin/php' : '',
                    'sapi' => PHP_SAPI,
                    'exec' => function_exists('exec'),
                    'shell_exec' => function_exists('shell_exec'),
                    'proc_open' => function_exists('proc_open'),
                    'disable_functions' => (string)ini_get('disable_functions'),
                    'open_basedir' => (string)ini_get('open_basedir'),
                    'root' => VMM_ROOT,
                    'writable_state' => is_writable(VMM_ROOT . '/state'),
                    'writable_log' => is_writable(VMM_ROOT . '/log'),
                    'uid' => function_exists('posix_getuid') ? posix_getuid() : -1,
                    'vm' => vmm_resolve('vm'),
                ],
            ]);
            // no break

        default:
            vmm_json(['ok' => false, 'error' => 'unknown action'], 400);
    }
} catch (Throwable $e) {
    vmm_api_log(sprintf('exception: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => 'exception: ' . $e->getMessage()]);
}
