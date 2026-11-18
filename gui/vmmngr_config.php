<?php
/*
 * vmmngr_config.php - environment and provider self-check (preview 0.1.0)
 */

require_once 'vmmngr-boot.inc';
require_once 'vmmngr-config-lib.inc';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST' && ($_POST['act'] ?? '') === 'save_settings') {
    $conf = vmm_config();
    $conf['after_action'] = ($_POST['after_action'] ?? 'stay') === 'log' ? 'log' : 'stay';
    $conf['vm_source'] = in_array($_POST['vm_source'] ?? '', ['auto', 'system', 'bundle'], true) ? $_POST['vm_source'] : 'auto';
    $keep = (int)($_POST['job_keep'] ?? 100);
    $conf['job_keep'] = ($keep >= 0 && $keep <= 10000) ? $keep : 100;
    vmm_save_config($conf);
    $_SESSION['vmmngr_error'] = gettext('Settings saved.');
    header('Location: vmmngr_config.php');
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST' && ($_POST['act'] ?? '') === 'register') {
    $added = vmm_register_boot();
    $_SESSION['vmmngr_error'] = $added === []
        ? gettext('Boot integration was already complete - nothing changed.')
        : sprintf(gettext('Added to config.xml: %s. Modules take effect at the next boot.'), implode(', ', $added));
    header('Location: vmmngr_config.php');
    exit;
}

$pgtitle = [gettext('Extensions'), gettext('VM Manager'), gettext('Configuration')];
$sc = vmm_selfcheck();
$conf = vmm_config();

include 'fbegin.inc';
?>
<?php vmm_css();
vmm_nav('config'); ?>

<form action="vmmngr_config.php" method="post" name="iform" id="iform"><table id="area_data"><tbody><tr><td id="area_data_frame">

<?php if (!empty($_SESSION['vmmngr_error'])): ?>
    <?php print_info_box(vmm_h((string)$_SESSION['vmmngr_error'])); unset($_SESSION['vmmngr_error']); ?>
<?php endif; ?>

<?php $bs = vmm_boot_state(); $conf = vmm_config(); ?>
<table class="area_data_settings" style="width:100%;border-collapse:collapse;">
    <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
    <thead><?php html_separator2(); html_titleline2(gettext('Behaviour'), 2); ?></thead>
    <tbody>
        <tr>
            <td class="celltag"><?=gettext('After an action');?></td>
            <td class="celldata">
                <select name="after_action">
                    <option value="stay" <?=($conf['after_action'] ?? 'stay') === 'stay' ? 'selected' : '';?>><?=gettext('stay on the page, show job status');?></option>
                    <option value="log" <?=($conf['after_action'] ?? 'stay') === 'log' ? 'selected' : '';?>><?=gettext('jump to the job log');?></option>
                </select>
                <button type="submit" class="formbtn" name="act" value="save_settings" style="margin-left:8px;"><?=gettext('Save');?></button>
            </td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Job retention');?></td>
            <td class="celldata">
                <input type="text" name="job_keep" size="6" value="<?=(int)($conf['job_keep'] ?? 100);?>"/>
                <span style="opacity:.75;margin-left:8px;"><?=gettext('finished jobs to keep (FIFO); 0 = delete after each run');?></span>
            </td>
        </tr>
        <tr>
            <td class="celltag">vm_source</td>
            <td class="celldata">
                <select name="vm_source">
                    <?php foreach (['auto', 'system', 'bundle'] as $src): ?>
                        <option value="<?=$src;?>" <?=($conf['vm_source'] ?? 'auto') === $src ? 'selected' : '';?>><?=$src;?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
    </tbody>
</table>

<br />

<?php $bs = vmm_boot_state(); ?>
<table class="area_data_settings" style="width:100%;border-collapse:collapse;">
    <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
    <thead>
        <?php html_separator2(); html_titleline2(gettext('Boot integration (config.xml)'), 2); ?>
    </thead>
    <tbody>
        <tr><td class="celltag">vmm_load</td><td class="celldata">
            <span class="<?=$bs['vmm_load'] !== '' ? 'vmm-ok' : 'vmm-miss';?>"><?=$bs['vmm_load'] !== '' ? vmm_h($bs['vmm_load']) : gettext('missing');?></span>
        </td></tr>
        <tr><td class="celltag">nmdm_load</td><td class="celldata">
            <span class="<?=$bs['nmdm_load'] !== '' ? 'vmm-ok' : 'vmm-miss';?>"><?=$bs['nmdm_load'] !== '' ? vmm_h($bs['nmdm_load']) : gettext('missing');?></span>
        </td></tr>
        <tr><td class="celltag"><?=gettext('Postinit hook');?></td><td class="celldata">
            <span class="<?=$bs['rc_postinit'] ? 'vmm-ok' : 'vmm-miss';?>"><?=$bs['rc_postinit'] ? gettext('registered') : gettext('missing');?></span>
        </td></tr>
        <tr><td class="celltag"><?=gettext('Action');?></td><td class="celldata">
            <button type="submit" class="formbtn" name="act" value="register"><?=gettext('Register in config.xml');?></button>
            <span style="opacity:.75;margin-left:8px;"><?=gettext('XigmaNAS regenerates loader.conf.local and rc.conf from config.xml at boot; direct file edits do not survive.');?></span>
        </td></tr>
    </tbody>
</table>

<br />

<table class="area_data_settings" style="width:100%;border-collapse:collapse;">
    <colgroup>
        <col class="area_data_settings_col_tag">
        <col class="area_data_settings_col_data">
    </colgroup>
    <thead>
        <?php html_separator2(); html_titleline2(gettext('Environment'), 2); ?>
    </thead>
    <tbody>
        <tr><td class="celltag"><?=gettext('Platform');?></td><td class="celldata"><?=vmm_h($sc['platform']);?> · <?=vmm_h($sc['product']);?> · <?=vmm_h($sc['os']);?></td></tr>
        <tr><td class="celltag">PHP</td><td class="celldata"><?=vmm_h($sc['php']);?></td></tr>
        <tr><td class="celltag"><?=gettext('Extension root');?></td><td class="celldata vmm-mono"><?=vmm_h($sc['root']);?></td></tr>
        <tr><td class="celltag">vm_dir</td><td class="celldata vmm-mono"><?=vmm_h($sc['vm_dir']);?></td></tr>
        <tr><td class="celltag"><?=gettext('Kernel modules');?></td><td class="celldata">
            vmm: <span class="<?=$sc['kld']['vmm'] === 'loaded' ? 'vmm-ok' : 'vmm-miss';?>"><?=vmm_h($sc['kld']['vmm']);?></span> ·
            nmdm: <span class="<?=$sc['kld']['nmdm'] === 'loaded' ? 'vmm-ok' : 'vmm-miss';?>"><?=vmm_h($sc['kld']['nmdm']);?></span>
        </td></tr>
        <tr><td class="celltag"><?=gettext('Boot-time modules');?></td><td class="celldata vmm-mono">
            <?=$sc['loader_conf']['file'];?>:
            vmm_load=<?=$sc['loader_conf']['vmm'] ? 'YES' : 'no';?> ·
            nmdm_load=<?=$sc['loader_conf']['nmdm'] ? 'YES' : 'no';?>
        </td></tr>
        <tr><td class="celltag"><?=gettext('Virtual machines');?></td><td class="celldata"><?=$sc['vm_count'];?> <?=gettext('total');?>, <?=$sc['vm_running'];?> <?=gettext('running');?></td></tr>
        <?php $b = vmm_ram_budget(); ?>
        <tr><td class="celltag"><?=gettext('RAM budget');?></td><td class="celldata"><?=vmm_human_bytes(vmm_ram_used());?> <?=gettext('in use');?> · <?=gettext('limit');?> <?=vmm_human_bytes($b['limit']);?> (<?=$b['pct'];?>% <?=gettext('of');?> <?=vmm_human_bytes($b['total']);?>)</td></tr>
    </tbody>
</table>

<br />

<table class="area_data_settings" style="width:100%;border-collapse:collapse;">
    <colgroup>
        <col class="area_data_settings_col_tag">
        <col class="area_data_settings_col_data">
    </colgroup>
    <thead>
        <?php html_separator2(); html_titleline2(gettext('Components and provider'), 2); ?>
    </thead>
    <tbody>
        <?php
        $rows = [
            'vm' => gettext('vm-bhyve'),
            'grub' => gettext('grub-bhyve'),
            'firmware' => gettext('UEFI firmware'),
            'ttyd' => gettext('Web terminal (ttyd)'),
        ];
        foreach ($rows as $key => $label):
            $p = $sc[$key];
            $ok = !empty($p['available']);
        ?>
            <tr>
                <td class="celltag"><?=$label;?></td>
                <td class="celldata">
                    <span class="<?=$ok ? 'vmm-ok' : 'vmm-miss';?>"><?=$ok ? gettext('available') : gettext('missing');?></span>
                    · <?=gettext('source');?>: <strong><?=vmm_h((string)$p['source']);?></strong>
                    <div class="vmm-mono"><?=vmm_h((string)$p['path']);?></div>
                    <?php if ($key === 'vm' && !empty($sc['vm']['version']['version'])): ?>
                        <div class="vmm-mono"><?=vmm_h((string)$sc['vm']['version']['version']);?> (rev <?=vmm_h((string)($sc['vm']['version']['rev'] ?? '?'));?>)</div>
                    <?php endif; ?>
                    <?php if ($key === 'firmware' && !empty($sc['firmware']['mtime'])): ?>
                        <div class="vmm-mono"><?=vmm_h((string)$sc['firmware']['mtime']);?> · <?=vmm_h(vmm_human_bytes((int)$sc['firmware']['size']));?></div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <tr><td class="celltag"><?=gettext('Update policy');?></td><td class="celldata"><span class="vmm-mono">vm_source = <?=vmm_h((string)$conf['vm_source']);?></span> — <?=gettext('the system installation always wins; the bundle only fills gaps.');?></td></tr>
    </tbody>
</table>

<br />

<table class="area_data_settings" style="width:100%;border-collapse:collapse;">
    <colgroup>
        <col class="area_data_settings_col_tag">
        <col class="area_data_settings_col_data">
    </colgroup>
    <thead>
        <?php html_separator2(); html_titleline2(gettext('Datastores and switches'), 2); ?>
    </thead>
    <tbody>
        <?php foreach (($sc['datastores'] ?? []) as $ds): ?>
            <tr>
                <td class="celltag"><?=gettext('Datastore');?></td>
                <td class="celldata vmm-mono"><?=vmm_h(implode(' · ', array_map('strval', $ds)));?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (($sc['datastores'] ?? []) === []): ?>
            <tr><td class="celltag"><?=gettext('Datastore');?></td><td class="celldata"><?=gettext('none reported');?></td></tr>
        <?php endif; ?>
        <?php foreach (($sc['switches'] ?? []) as $sw): ?>
            <tr>
                <td class="celltag"><?=gettext('Switch');?></td>
                <td class="celldata vmm-mono"><?=vmm_h(implode(' · ', array_map('strval', $sw)));?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<details style="margin-top:12px;">
    <summary style="cursor:pointer;padding:6px 0;"><?=gettext('Raw self-check (JSON)');?></summary>
    <pre class="vmm-raw vmm-mono"><?=vmm_h(json_encode($sc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));?></pre>
</details>

</td></tr></tbody></table><?php include 'formend.inc'; ?></form>
<?php include 'fend.inc'; ?>
