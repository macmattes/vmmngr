<?php
/*
 * vmmngr.php - VM Manager dashboard (0.2.0: actions via job engine)
 */

require_once 'vmmngr-boot.inc';

$pgtitle = [gettext('Extensions'), gettext('VM Manager')];

// actions are posted to this page (XigmaNAS validates the authtoken here)
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST' && isset($_POST['act'])) {
    $act = (string)$_POST['act'];
    [$verb, $vmName] = array_pad(explode(':', $act, 2), 2, '');
    $check = vmm_action_check($verb, $vmName, (string)($_POST['confirm'] ?? ''));
    if (!$check['ok']) {
        $_SESSION['vmmngr_error'] = $check['error'];
        header('Location: vmmngr.php');
        exit;
    }
    $job = vmm_job_new($verb, $vmName);
    header('Location: ' . vmm_after_job_target((string)$job['id'], 'vmmngr.php'));
    exit;
}

$activeJob = (string)($_GET['job'] ?? '');
if ($activeJob !== '' && vmm_job_running($activeJob)) {
    header('Refresh: 3');
}

$conf = vmm_config();
$vmprov = vmm_resolve('vm');
$vms = $vmprov['available'] ? vmm_list() : [];
$ver = $vmprov['available'] ? vmm_vm_version() : ['version' => '', 'rev' => ''];
$vm_dir = $vmprov['available'] ? vmm_vm_dir() : '';
$budget = vmm_ram_budget();
$switchList = $vmprov['available'] ? vmm_switches() : [];
$needsSetup = ($vm_dir === '' || $switchList === []);

$running = array_values(array_filter($vms, static fn(array $v): bool => ($v['state'] ?? '') === 'running'));
$mem_used = 0;
$cpus = 0;
foreach ($running as $v) {
    $mem_used += vmm_size_to_bytes((string)($v['memory'] ?? '0'));
    $cpus += (int)($v['cpu'] ?? 0);
}
vmm_job_recover();
$jobs = vmm_job_list(5);

include 'fbegin.inc';
vmm_css();
vmm_nav('dashboard');
?>

<form action="vmmngr.php" method="post" name="iform" id="iform"><table id="area_data"><tbody><tr><td id="area_data_frame">

<?php if ($activeJob !== ''): ?>
    <?=vmm_job_status_html($activeJob); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['vmmngr_error'])): ?>
    <?php print_info_box(vmm_h((string)$_SESSION['vmmngr_error'])); unset($_SESSION['vmmngr_error']); ?>
<?php endif; ?>

<?php if ($needsSetup): ?>
    <?php
    $missing = [];
    if ($vm_dir === '') {
        $missing[] = 'vm_dir';
    }
    if ($switchList === []) {
        $missing[] = gettext('switch');
    }
    print_info_box(sprintf(
        gettext('Setup is incomplete (%s). Run the guided setup to register the default datastore and create a switch.'),
        implode(', ', $missing)
    ) . ' <a class="formbtn" href="vmmngr_setup.php">' . gettext('Open setup') . '</a>');
    ?>
<?php endif; ?>

<?php if (!$vmprov['available']): ?>
    <?php print_info_box(sprintf(gettext('vm-bhyve was not found. Neither %s nor the bundled copy exists.'), '/usr/local/sbin/vm')); ?>
<?php endif; ?>

<div class="vmm-grid">
    <div class="vmm-card">
        <div class="k"><?=gettext('Virtual Machines');?></div>
        <div class="v"><?=count($running);?> / <?=count($vms);?></div>
        <div class="s"><?=gettext('running / total');?></div>
    </div>
    <div class="vmm-card">
        <div class="k"><?=gettext('Assigned Memory');?></div>
        <div class="v"><?=vmm_human_bytes($mem_used);?></div>
        <div class="s"><?=$cpus;?> vCPU · <?=gettext('budget');?> <?=vmm_human_bytes($budget['limit']);?> (<?=$budget['pct'];?>%)</div>
    </div>
    <div class="vmm-card">
        <div class="k"><?=gettext('vm-bhyve');?></div>
        <div class="v"><?=vmm_h((string)($ver['version'] ?? '?'));?></div>
        <div class="s"><?=vmm_source_label($vmprov);?> · rev <?=vmm_h((string)($ver['rev'] ?? '?'));?></div>
    </div>
    <div class="vmm-card">
        <div class="k"><?=gettext('Datastore');?></div>
        <div class="v"><?=vmm_h(ltrim(str_replace('zfs:', '', $vm_dir), '/'));?></div>
        <div class="s vmm-mono"><?=vmm_h($vm_dir);?></div>
    </div>
</div>

<div id="vmm-job" class="vmm-job"></div>

<table class="area_data_selection" style="width:100%;table-layout:fixed;border-collapse:collapse;">
    <colgroup>
        <col style="width:16%">
        <col style="width:11%">
        <col style="width:8%">
        <col style="width:6%">
        <col style="width:8%">
        <col style="width:12%">
        <col style="width:6%">
        <col style="width:33%">
    </colgroup>
    <thead>
        <?php
        html_separator2();
        html_titleline2(gettext('Virtual Machines'), 8);
        ?>
        <tr>
            <th class="lhell"><?=gettext('Name');?></th>
            <th class="lhell"><?=gettext('State');?></th>
            <th class="lhell"><?=gettext('Loader');?></th>
            <th class="lhell"><?=gettext('vCPU');?></th>
            <th class="lhell"><?=gettext('Memory');?></th>
            <th class="lhell"><?=gettext('Datastore');?></th>
            <th class="lhell"><?=gettext('Auto');?></th>
            <th class="lhebl"><?=gettext('Actions');?></th>
        </tr>
    </thead>
    <tbody>
    <?php if ($vms === []): ?>
        <tr><td class="lcell" colspan="8"><?=gettext('No virtual machines found.');?></td></tr>
    <?php else: ?>
        <?php foreach ($vms as $v): ?>
            <?php
            $n = (string)$v['name'];
            $isRunning = ($v['state'] ?? '') === 'running';
            $jsName = htmlspecialchars(json_encode($n), ENT_QUOTES);
            ?>
            <tr>
                <td class="lcell"><strong><?=vmm_h($n);?></strong></td>
                <td class="lcell"><?=vmm_badge((string)$v['state']);?></td>
                <td class="lcell"><?=vmm_h((string)$v['loader']);?></td>
                <td class="lcell"><?=vmm_h((string)$v['cpu']);?></td>
                <td class="lcell"><?=vmm_h((string)$v['memory']);?></td>
                <td class="lcell"><?=vmm_h((string)$v['datastore']);?></td>
                <td class="lcell"><?=!empty($v['auto']) ? gettext('yes') : gettext('no');?></td>
                <td class="lcebl">
                    <span class="vmm-actions">
                        <?php if ($isRunning): ?>
                            <button type="submit" class="formbtn" name="act" value="stop:<?=vmm_h($n);?>" onclick="return confirm('Stop <?=vmm_h($n);?>?')"><?=gettext('Stop');?></button>
                            <button type="submit" class="formbtn" name="act" value="restart:<?=vmm_h($n);?>" onclick="return confirm('Restart <?=vmm_h($n);?>?')"><?=gettext('Restart');?></button>
                        <?php else: ?>
                            <button type="submit" class="formbtn" name="act" value="start:<?=vmm_h($n);?>"><?=gettext('Start');?></button>
                        <?php endif; ?>
                        <a class="formbtn" href="vmmngr_vm.php?name=<?=urlencode($n);?>"><?=gettext('Details');?></a>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<br />

<table class="area_data_selection" style="width:100%;border-collapse:collapse;">
    <colgroup>
        <col style="width:26%">
        <col style="width:10%">
        <col style="width:14%">
        <col style="width:10%">
        <col style="width:40%">
    </colgroup>
    <thead>
        <?php html_separator2(); html_titleline2(gettext('Recent jobs'), 5); ?>
        <tr>
            <th class="lhell"><?=gettext('Job');?></th>
            <th class="lhell"><?=gettext('Action');?></th>
            <th class="lhell"><?=gettext('VM');?></th>
            <th class="lhell"><?=gettext('State');?></th>
            <th class="lhebl"><?=gettext('Started');?></th>
        </tr>
    </thead>
    <tbody>
    <?php if ($jobs === []): ?>
        <tr><td class="lcell" colspan="5"><?=gettext('No jobs yet.');?></td></tr>
    <?php else: ?>
        <?php foreach ($jobs as $j): ?>
            <tr>
                <td class="lcell vmm-mono"><a href="vmmngr_jobs.php?id=<?=urlencode((string)$j['id']);?>"><?=vmm_h((string)$j['id']);?></a></td>
                <td class="lcell"><?=vmm_h((string)$j['verb']);?></td>
                <td class="lcell"><?=vmm_h((string)$j['vm']);?></td>
                <td class="lcell">
                    <span class="<?=($j['state'] ?? '') === 'failed' ? 'vmm-err' : 'vmm-ok';?>"><?=vmm_h((string)$j['state']);?></span>
                </td>
                <td class="lcebl vmm-mono"><?=!empty($j['started']) ? date('Y-m-d H:i:s', (int)$j['started']) : '—';?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<div id="remarks">
    <?php
    html_remark2(
        'status',
        gettext('Status'),
        gettext('0.2.0: start/stop/restart/reset/poweroff/destroy run as jobs. Every action is locked per VM and logged; long operations continue if you close the browser.')
    );
    ?>
</div>

</td></tr></tbody></table><?php include 'formend.inc'; ?></form>
<?php include 'fend.inc'; ?>
