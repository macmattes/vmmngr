<?php
/*
 * vmmngr_setup.php - guided first-run setup (0.10.0)
 *
 * Step 1: prerequisites
 * Step 2: default datastore (vm_dir) -> config.xml + vm init
 * Step 3: first switch on an existing host bridge
 */

require_once 'vmmngr-boot.inc';
require_once 'vmmngr-config-lib.inc';

$vmDir = vmm_rc_param('vm_dir');
if ($vmDir === '') {
    $out = trim((string)@shell_exec('/usr/bin/grep -E "^vm_dir=" /etc/rc.conf 2>/dev/null'));
    if (preg_match('/vm_dir="?([^"\n]+)"?/', $out, $m)) {
        $vmDir = $m[1];
    }
}
$switchList = vmm_switches();
$hostBridges = array_values(array_filter(array_map('trim', explode("\n", trim((string)@shell_exec('/sbin/ifconfig -g bridge 2>/dev/null'))))));

$post = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST';
$act = $post ? (string)($_POST['act'] ?? '') : '';

if ($post && $act === 'save_datastore') {
    $spec = trim((string)($_POST['datastore'] ?? ''));
    $newDs = trim((string)($_POST['new_dataset'] ?? ''));
    $create = isset($_POST['create_dataset']);

    if ($newDs !== '' && $create) {
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*$#', $newDs)) {
            $_SESSION['vmmngr_error'] = gettext('Dataset name must look like pool/name.');
            header('Location: vmmngr_setup.php?step=2');
            exit;
        }
        exec('/sbin/zfs list -H -o name ' . escapeshellarg($newDs) . ' 2>/dev/null', $o, $rc);
        if ($rc !== 0) {
            exec('/sbin/zfs create ' . escapeshellarg($newDs) . ' 2>&1', $out, $crc);
            if ($crc !== 0) {
                $_SESSION['vmmngr_error'] = 'zfs create failed: ' . implode(' ', $out);
                header('Location: vmmngr_setup.php?step=2');
                exit;
            }
        }
        $spec = 'zfs:' . $newDs;
    }

    if (!preg_match('#^zfs:([A-Za-z0-9._:/-]+)$#', $spec, $m)) {
        $_SESSION['vmmngr_error'] = gettext('Choose a ZFS dataset.');
        header('Location: vmmngr_setup.php?step=2');
        exit;
    }
    $ds = $m[1];
    exec('/sbin/zfs list -H -o mounted ' . escapeshellarg($ds) . ' 2>/dev/null', $o2, $rc2);
    if ($rc2 !== 0 || trim((string)($o2[0] ?? '')) !== 'yes') {
        $_SESSION['vmmngr_error'] = gettext('Dataset is not mounted - cannot use it as vm_dir.');
        header('Location: vmmngr_setup.php?step=2');
        exit;
    }

    vmm_set_rc_param('vm_dir', 'zfs:' . $ds);
    vmm_set_rc_param('vm_enable', 'YES');
    $res = vmm_run(['init'], 60);
    $_SESSION['vmmngr_error'] = $res['rc'] === 0
        ? sprintf(gettext('Default datastore set to zfs:%s and initialised.'), $ds)
        : trim($res['stderr'] ?: $res['stdout'] ?: 'vm init failed');
    header('Location: vmmngr_setup.php?step=3');
    exit;
}

if ($post && $act === 'add_switch') {
    $name = trim((string)($_POST['sw_name'] ?? ''));
    $bridge = trim((string)($_POST['bridge'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/', $name)) {
        $_SESSION['vmmngr_error'] = gettext('Invalid switch name.');
    } elseif (!in_array($bridge, $hostBridges, true)) {
        $_SESSION['vmmngr_error'] = gettext('Choose an existing host bridge.');
    } else {
        $res = vmm_run(['switch', 'create', '-t', 'manual', '-b', $bridge, $name], 30);
        $_SESSION['vmmngr_error'] = $res['rc'] === 0
            ? sprintf(gettext('Switch %s created on %s.'), $name, $bridge)
            : trim($res['stderr'] ?: $res['stdout'] ?: 'switch create failed');
        if ($res['rc'] === 0) {
            header('Location: vmmngr_setup.php?step=4');
            exit;
        }
    }
    header('Location: vmmngr_setup.php?step=3');
    exit;
}

$step = (int)($_GET['step'] ?? 0);
if ($step < 1 || $step > 4) {
    $step = $vmDir === '' ? 2 : ($switchList === [] ? 3 : 4);
}

// dataset inventory for the picker
$datasets = [];
exec('/sbin/zfs list -H -o name,mountpoint -t filesystem 2>/dev/null', $zlines);
foreach ($zlines as $line) {
    $parts = preg_split('/\t+/', trim($line));
    if (count($parts) < 2) {
        continue;
    }
    [$dsName, $mp] = $parts;
    if ($mp === '/' || $mp === 'none' || $mp === '-' || !str_starts_with($mp, '/mnt')) {
        continue;
    }
    if (substr_count($dsName, '/') > 1) {
        continue;
    }
    $datasets[$dsName] = $mp;
}

$pgtitle = [gettext('Extensions'), gettext('VM Manager'), gettext('Setup')];
include 'fbegin.inc';
vmm_css();
vmm_nav('setup');
?>
<form action="vmmngr_setup.php?step=<?=$step;?>" method="post" name="iform" id="iform"><table id="area_data"><tbody><tr><td id="area_data_frame">

<?php if (!empty($_SESSION['vmmngr_error'])): ?>
    <?php print_info_box(vmm_h((string)$_SESSION['vmmngr_error'])); unset($_SESSION['vmmngr_error']); ?>
<?php endif; ?>

<div class="vmm-tabs" style="border:0;">
    <span class="vmm-tab <?=$step === 1 ? 'vmm-tab-cur' : '';?>">1 · <?=gettext('Check');?></span>
    <span class="vmm-tab <?=$step === 2 ? 'vmm-tab-cur' : '';?>">2 · <?=gettext('Datastore');?></span>
    <span class="vmm-tab <?=$step === 3 ? 'vmm-tab-cur' : '';?>">3 · <?=gettext('Switch');?></span>
    <span class="vmm-tab <?=$step === 4 ? 'vmm-tab-cur' : '';?>">4 · <?=gettext('Done');?></span>
</div>

<?php if ($step === 1): ?>
<table class="area_data_settings" style="width:100%;border-collapse:collapse;">
    <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
    <thead><?php html_separator2(); html_titleline2(gettext('Prerequisites'), 2); ?></thead>
    <tbody>
        <?php
        $vmProv = vmm_resolve('vm');
        $checks = [
            [gettext('vm-bhyve'), $vmProv['available'], $vmProv['path']],
            [gettext('Kernel module vmm'), trim((string)@shell_exec('kldstat -q -n vmm && echo loaded')) === 'loaded', 'kldload vmm'],
            [gettext('Kernel module nmdm'), trim((string)@shell_exec('kldstat -q -n nmdm && echo loaded')) === 'loaded', 'kldload nmdm'],
            [gettext('Host bridges'), $hostBridges !== [], implode(', ', $hostBridges)],
        ];
        foreach ($checks as [$label, $ok, $hint]): ?>
            <tr>
                <td class="celltag"><?=vmm_h($label);?></td>
                <td class="celldata">
                    <span class="<?=$ok ? 'vmm-ok' : 'vmm-miss';?>"><?=$ok ? gettext('ok') : gettext('missing');?></span>
                    <span class="vmm-mono" style="margin-left:8px;"><?=vmm_h((string)$hint);?></span>
                </td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td class="celltag">&nbsp;</td>
            <td class="celldata"><a class="formbtn" href="vmmngr_setup.php?step=2"><?=gettext('Continue');?></a></td>
        </tr>
    </tbody>
</table>

<?php elseif ($step === 2): ?>
<table class="area_data_settings" style="width:100%;border-collapse:collapse;">
    <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
    <thead><?php html_separator2(); html_titleline2(gettext('Default datastore (vm_dir)'), 2); ?></thead>
    <tbody>
        <tr>
            <td class="celltag"><?=gettext('Current');?></td>
            <td class="celldata vmm-mono"><?=$vmDir !== '' ? vmm_h($vmDir) : gettext('not configured');?></td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Existing dataset');?></td>
            <td class="celldata">
                <select name="datastore">
                    <?php foreach ($datasets as $ds => $mp): ?>
                        <option value="zfs:<?=vmm_h($ds);?>"><?=vmm_h($ds);?> (<?=vmm_h($mp);?>)</option>
                    <?php endforeach; ?>
                </select>
                <div style="opacity:.75;margin-top:4px;"><?=gettext('Only mounted datasets below /mnt are listed.');?></div>
            </td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Or create');?></td>
            <td class="celldata">
                <input type="text" name="new_dataset" size="30" placeholder="hdd_vm_pool/bhyve"/>
                <label style="margin-left:8px;"><input type="checkbox" name="create_dataset" value="1"/> <?=gettext('create with zfs create');?></label>
            </td>
        </tr>
        <tr>
            <td class="celltag">&nbsp;</td>
            <td class="celldata">
                <button type="submit" class="formbtn" name="act" value="save_datastore"><?=gettext('Set and initialise');?></button>
                <span style="opacity:.75;margin-left:8px;"><?=gettext('Writes vm_dir/vm_enable to config.xml and runs vm init.');?></span>
            </td>
        </tr>
    </tbody>
</table>

<?php elseif ($step === 3): ?>
<table class="area_data_settings" style="width:100%;border-collapse:collapse;">
    <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
    <thead><?php html_separator2(); html_titleline2(gettext('First network switch'), 2); ?></thead>
    <tbody>
        <tr>
            <td class="celltag"><?=gettext('Existing switches');?></td>
            <td class="celldata vmm-mono">
                <?php if ($switchList === []): ?><?=gettext('none');?><?php else: ?>
                    <?=vmm_h(implode(', ', array_map(static fn($s) => (string)($s['name'] ?? ''), $switchList)));?>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Name');?></td>
            <td class="celldata"><input type="text" name="sw_name" size="20" value="public"/></td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Host bridge');?></td>
            <td class="celldata">
                <select name="bridge">
                    <?php foreach ($hostBridges as $b): ?>
                        <?php $ip = vmm_iface_ipv4($b); ?>
                        <option value="<?=vmm_h($b);?>"><?=vmm_h($b . ($ip !== '' ? ' (' . $ip . ')' : ''));?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <td class="celltag">&nbsp;</td>
            <td class="celldata">
                <button type="submit" class="formbtn" name="act" value="add_switch"><?=gettext('Create switch');?></button>
                <?php if ($switchList !== []): ?>
                    <a class="formbtn" href="vmmngr_setup.php?step=4" style="margin-left:8px;"><?=gettext('Skip');?></a>
                <?php endif; ?>
            </td>
        </tr>
    </tbody>
</table>

<?php else: ?>
<table class="area_data_settings" style="width:100%;border-collapse:collapse;">
    <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
    <thead><?php html_separator2(); html_titleline2(gettext('Setup complete'), 2); ?></thead>
    <tbody>
        <tr><td class="celltag">vm_dir</td><td class="celldata vmm-mono"><?=$vmDir !== '' ? vmm_h($vmDir) : gettext('not set');?></td></tr>
        <tr><td class="celltag"><?=gettext('Switches');?></td><td class="celldata vmm-mono"><?=vmm_h(implode(', ', array_map(static fn($s) => (string)($s['name'] ?? ''), $switchList)));?></td></tr>
        <tr>
            <td class="celltag">&nbsp;</td>
            <td class="celldata">
                <a class="formbtn" href="vmmngr_create.php"><?=gettext('Create the first VM');?></a>
                <a class="formbtn" href="vmmngr.php" style="margin-left:8px;"><?=gettext('Dashboard');?></a>
            </td>
        </tr>
    </tbody>
</table>
<?php endif; ?>

<?php include 'formend.inc'; ?>
</td></tr></tbody></table></form>
<?php include 'fend.inc'; ?>
