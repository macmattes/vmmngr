<?php
/*
 * vmmngr_do.php - form handler for VM actions (0.2.2)
 *
 * Plain POST + redirect instead of fetch/JSON: works with the XigmaNAS session
 * handling and redirects to login when the session has expired.
 */

require_once 'vmmngr-boot.inc';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Location: vmmngr.php');
    exit;
}

$verb = (string)($_POST['verb'] ?? '');
$vm = (string)($_POST['vm'] ?? '');
$act = (string)($_POST['act'] ?? '');
if ($act !== '' && str_contains($act, ':')) {
    [$verb, $vm] = explode(':', $act, 2);
}
$confirm = (string)($_POST['confirm'] ?? '');
$back = (string)($_POST['back'] ?? 'vmmngr.php');

if (!str_starts_with($back, 'vmmngr')) {
    $back = 'vmmngr.php';
}

$check = vmm_action_check($verb, $vm, $confirm);
if (!$check['ok']) {
    $_SESSION['vmmngr_error'] = $check['error'];
    header('Location: ' . $back);
    exit;
}

$job = vmm_job_new($verb, $vm);
header('Location: vmmngr_jobs.php?id=' . urlencode((string)$job['id']));
exit;
