<?php
/*
 * vmmngr_jobs.php - job history and log viewer (0.2.0)
 */

require_once 'vmmngr-boot.inc';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST' && ($_POST['act'] ?? '') === 'clear_jobs') {
    $res = vmm_job_prune(0);
    $_SESSION['vmmngr_error'] = sprintf(gettext('Removed %d finished job(s).'), (int)$res['removed']);
    header('Location: vmmngr_jobs.php');
    exit;
}

$pgtitle = [gettext('Extensions'), gettext('VM Manager'), gettext('Jobs')];
$id = (string)($_GET['id'] ?? '');
$job = $id !== '' ? vmm_job_get($id) : null;
$jobs = vmm_job_list(50);

if ($job !== null && in_array($job['state'], ['queued', 'running'], true)) {
    header('Refresh: 2');
}

include 'fbegin.inc';
vmm_css();
vmm_nav('jobs');
?>

<form action="vmmngr_jobs.php" method="post" name="iform" id="iform"><table id="area_data"><tbody><tr><td id="area_data_frame">

<?php if (!empty($_SESSION['vmmngr_error'])): ?>
    <?php print_info_box(vmm_h((string)$_SESSION['vmmngr_error'])); unset($_SESSION['vmmngr_error']); ?>
<?php endif; ?>

<?php if ($job !== null): ?>
    <table class="area_data_settings" style="width:100%;border-collapse:collapse;">
        <colgroup>
            <col class="area_data_settings_col_tag">
            <col class="area_data_settings_col_data">
        </colgroup>
        <thead>
            <?php html_separator2(); html_titleline2(sprintf('%s %s', gettext('Job'), $job['id']), 2); ?>
        </thead>
        <tbody>
            <tr><td class="celltag"><?=gettext('Action');?></td><td class="celldata"><?=vmm_h((string)$job['verb']);?></td></tr>
            <tr><td class="celltag"><?=gettext('VM');?></td><td class="celldata"><a href="vmmngr_vm.php?name=<?=urlencode((string)$job['vm']);?>"><?=vmm_h((string)$job['vm']);?></a></td></tr>
            <tr><td class="celltag"><?=gettext('State');?></td><td class="celldata"><span class="<?=($job['state'] ?? '') === 'failed' ? 'vmm-err' : 'vmm-ok';?>"><?=vmm_h((string)$job['state']);?></span><?php if ($job['error'] ?? ''): ?> — <?=vmm_h((string)$job['error']);?><?php endif; ?></td></tr>
            <tr><td class="celltag"><?=gettext('Return code');?></td><td class="celldata vmm-mono"><?=vmm_h((string)($job['rc'] ?? '—'));?></td></tr>
            <tr><td class="celltag"><?=gettext('Requested by');?></td><td class="celldata vmm-mono"><?=vmm_h((string)($job['by'] ?? ''));?></td></tr>
            <tr><td class="celltag"><?=gettext('Timing');?></td><td class="celldata vmm-mono">
                <?=!empty($job['created']) ? date('Y-m-d H:i:s', (int)$job['created']) : '—';?>
                <?php if (!empty($job['started'])): ?> → <?=date('H:i:s', (int)$job['started']);?><?php endif; ?>
                <?php if (!empty($job['ended'])): ?> → <?=date('H:i:s', (int)$job['ended']);?> (<?=(int)$job['ended'] - (int)($job['started'] ?? $job['ended']);?>s)<?php endif; ?>
            </td></tr>
            <tr>
                <td class="celltag"><?=gettext('Log');?></td>
                <td class="celldata">
                    <pre class="vmm-raw vmm-mono"><?=vmm_h(vmm_job_log((string)$job['id']));?></pre>
                </td>
            </tr>
        </tbody>
    </table>
    <br />
<?php endif; ?>



<table class="area_data_selection" style="width:100%;border-collapse:collapse;">
    <colgroup>
        <col style="width:26%">
        <col style="width:10%">
        <col style="width:14%">
        <col style="width:10%">
        <col style="width:10%">
        <col style="width:30%">
    </colgroup>
    <thead>
        <?php html_separator2(); html_titleline2(sprintf('%s (%d)', gettext('All jobs'), count($jobs)), 6); ?>
        <tr>
            <th class="lhell"><?=gettext('Job');?></th>
            <th class="lhell"><?=gettext('Action');?></th>
            <th class="lhell"><?=gettext('VM');?></th>
            <th class="lhell"><?=gettext('State');?></th>
            <th class="lhell">RC</th>
            <th class="lhebl"><?=gettext('Started');?></th>
        </tr>
    </thead>
    <tbody>
    <?php if ($jobs === []): ?>
        <tr><td class="lcell" colspan="6"><?=gettext('No jobs yet.');?></td></tr>
    <?php else: ?>
        <?php foreach ($jobs as $j): ?>
            <tr>
                <td class="lcell vmm-mono"><a href="vmmngr_jobs.php?id=<?=urlencode((string)$j['id']);?>"><?=vmm_h((string)$j['id']);?></a></td>
                <td class="lcell"><?=vmm_h((string)$j['verb']);?></td>
                <td class="lcell"><?=vmm_h((string)$j['vm']);?></td>
                <td class="lcell"><span class="<?=($j['state'] ?? '') === 'failed' ? 'vmm-err' : 'vmm-ok';?>"><?=vmm_h((string)$j['state']);?></span></td>
                <td class="lcell vmm-mono"><?=vmm_h((string)($j['rc'] ?? '—'));?></td>
                <td class="lcebl vmm-mono"><?=!empty($j['started']) ? date('Y-m-d H:i:s', (int)$j['started']) : '—';?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<p>
    <button type="submit" class="formbtn" name="act" value="clear_jobs"
            onclick="return confirm('Remove all finished jobs? Running jobs are kept.')"><?=gettext('Clear finished jobs');?></button>
    <span style="opacity:.75;margin-left:8px;"><?=gettext('Retention keeps the newest %d jobs automatically (Configuration).', (int)(vmm_config()['job_keep'] ?? 100));?></span>
</p>

</td></tr></tbody></table><?php include 'formend.inc'; ?></form>
<?php include 'fend.inc'; ?>
