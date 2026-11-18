<?php
/*
 * vmmngr_vm.php - VM detail view with actions (0.2.0)
 */

require_once 'vmmngr-boot.inc';
require_once 'vmmngr-config-lib.inc';

$name = (string)($_GET['name'] ?? '');
// POSTs from this page carry the VM name in the act field; accept that too so
// a form without the query string can never bounce to the dashboard
if ($name === '' && isset($_POST['act'])) {
    $parts = explode(':', (string)$_POST['act'], 2);
    $name = $parts[1] ?? '';
}
if ($name === '' || !vmm_valid_name($name)) {
    header('Location: vmmngr.php');
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST' && isset($_POST['act'])) {
    $act = (string)$_POST['act'];
    [$verb, $vmName] = array_pad(explode(':', $act, 2), 2, '');
    if ($vmName !== $name) {
        $vmName = $name;
    }

    if ($verb === 'save_config') {
        if (vmm_vm_state($name) === 'running') {
            $_SESSION['vmmngr_error'] = gettext('Stop the VM before changing CPU, memory or loader.');
            header('Location: vmmngr_vm.php?name=' . urlencode($name));
            exit;
        }
        $cpu = trim((string)($_POST['cpu'] ?? ''));
        $mem = strtoupper(trim((string)($_POST['memory'] ?? '')));
        $loader = (string)($_POST['loader'] ?? '');
        $graphics = (string)($_POST['graphics'] ?? 'no');
        $switch = trim((string)($_POST['switch'] ?? ''));

        if (!preg_match('/^[0-9]{1,2}$/', $cpu) || (int)$cpu < 1 || (int)$cpu > 64) {
            $_SESSION['vmmngr_error'] = gettext('vCPU must be between 1 and 64.');
        } elseif (!preg_match('/^[0-9]{1,5}[MG]$/', $mem)) {
            $_SESSION['vmmngr_error'] = gettext('Memory must look like 2G or 512M.');
        } elseif (!in_array($loader, ['uefi', 'uefi-csm', 'grub', 'bhyve'], true)) {
            $_SESSION['vmmngr_error'] = gettext('Unknown loader.');
        } else {
            $pairs = [
                'cpu' => $cpu,
                'memory' => $mem,
                'loader' => $loader,
                'graphics' => $graphics === 'yes' ? 'yes' : 'no',
            ];
            if ($switch !== '') {
                $pairs['network0_switch'] = $switch;
            }
            $res = vmm_conf_set($name, $pairs);
            if (!$res['ok']) {
                $_SESSION['vmmngr_error'] = (string)$res['error'];
            } else {
                vmm_autostart_set($name, isset($_POST['autostart']));
                $_SESSION['vmmngr_error'] = $res['changed'] === []
                    ? gettext('No changes.')
                    : sprintf(gettext('Updated: %s'), implode(', ', $res['changed']));
            }
        }
        header('Location: vmmngr_vm.php?name=' . urlencode($name));
        exit;
    }

    if ($verb === 'novnc_start' || $verb === 'novnc_stop') {
        if ($verb === 'novnc_stop') {
            vmm_novnc_stop($name);
            $_SESSION['vmmngr_error'] = gettext('noVNC stopped.');
        } else {
            $res = vmm_novnc_start($name);
            $_SESSION['vmmngr_error'] = $res['ok']
                ? sprintf(gettext('noVNC on http://%s:%d'), $res['host'], $res['port'])
                : (string)$res['error'];
        }
        header('Location: vmmngr_vm.php?name=' . urlencode($name));
        exit;
    }

    if ($verb === 'console_start') {
        $res = vmm_console_start($name);
        $_SESSION['vmmngr_error'] = $res['ok']
            ? sprintf(gettext('Web console on http://%s:%d'), $res['host'], $res['port'])
            : (string)$res['error'];
        header('Location: vmmngr_vm.php?name=' . urlencode($name));
        exit;
    }
    if ($verb === 'console_stop') {
        vmm_console_stop($name);
        $_SESSION['vmmngr_error'] = gettext('Web console stopped.');
        header('Location: vmmngr_vm.php?name=' . urlencode($name));
        exit;
    }

    // media: attach ISOs (install) or eject them
    if ($verb === 'install' || $verb === 'eject') {
        if ($verb === 'eject') {
            $res = vmm_iso_detach($name);
            $_SESSION['vmmngr_error'] = isset($res['error'])
                ? (string)$res['error']
                : ($res['removed'] === [] ? gettext('No ISO attached.') : sprintf(gettext('Ejected: %s'), implode(', ', $res['removed'])));
            header('Location: vmmngr_vm.php?name=' . urlencode($name));
            exit;
        }
        $isoDir = vmm_data_dir('.iso');
        $pick = [];
        foreach (['iso', 'driver_iso'] as $field) {
            $f = vmm_safe_basename((string)($_POST[$field] ?? ''));
            if ($f !== '' && str_ends_with(strtolower($f), '.iso')) {
                $pick[] = $isoDir . '/' . $f;
            }
        }
        if ($pick === []) {
            $_SESSION['vmmngr_error'] = gettext('Choose an ISO first.');
            header('Location: vmmngr_vm.php?name=' . urlencode($name));
            exit;
        }
        $res = vmm_iso_attach($name, $pick);
        if (isset($res['error'])) {
            $_SESSION['vmmngr_error'] = (string)$res['error'];
            header('Location: vmmngr_vm.php?name=' . urlencode($name));
            exit;
        }
        $job = vmm_job_new('start', $name);
        header('Location: ' . vmm_after_job_target((string)$job['id'], 'vmmngr_vm.php?name=' . urlencode($name)));
        exit;
    }
    $check = vmm_action_check($verb, $vmName, (string)($_POST['confirm'] ?? ''));
    if (!$check['ok']) {
        $_SESSION['vmmngr_error'] = $check['error'];
        header('Location: vmmngr_vm.php?name=' . urlencode($name));
        exit;
    }
    $job = vmm_job_new($verb, $vmName);
    header('Location: ' . vmm_after_job_target((string)$job['id'], 'vmmngr_vm.php?name=' . urlencode($name)));
    exit;
}

if (vmm_vm_state($name) === 'absent') {
    $_SESSION['vmmngr_error'] = sprintf(gettext('Virtual machine %s does not exist (yet).'), $name);
    header('Location: vmmngr.php');
    exit;
}

$activeJob = (string)($_GET['job'] ?? '');
if ($activeJob !== '' && vmm_job_running($activeJob)) {
    header('Refresh: 3');
}

$info = vmm_info($name);
$pgtitle = [gettext('Extensions'), gettext('VM Manager'), $name];
$jobs = vmm_job_list(10, $name);

include 'fbegin.inc';
vmm_css();
vmm_nav('', $name);
?>

<form action="vmmngr_vm.php?name=<?=urlencode($name);?>" method="post" name="iform" id="iform"><table id="area_data"><tbody><tr><td id="area_data_frame">

<?php if ($activeJob !== ''): ?>
    <?=vmm_job_status_html($activeJob); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['vmmngr_error'])): ?>
    <?php print_info_box(vmm_h((string)$_SESSION['vmmngr_error'])); unset($_SESSION['vmmngr_error']); ?>
<?php endif; ?>

<?php if (isset($info['error'])): ?>
    <?php print_info_box(sprintf(gettext('Could not read VM details: %s'), vmm_h((string)$info['error']))); ?>
<?php else: ?>
    <?php
    $g = $info['general'] ?? [];
    $state = vmm_state_from_string((string)($g['state'] ?? ''));
    $isRunning = $state['state'] === 'running';
    $jsName = htmlspecialchars(json_encode($name), ENT_QUOTES);
    ?>

    <table class="area_data_settings" style="width:100%;border-collapse:collapse;">
        <colgroup>
            <col class="area_data_settings_col_tag">
            <col class="area_data_settings_col_data">
        </colgroup>
        <thead>
            <?php
            html_separator2();
            html_titleline2(sprintf('%s: %s', gettext('Virtual Machine'), $name), 2);
            ?>
        </thead>
        <tbody>
            <tr><td class="celltag"><?=gettext('State');?></td><td class="celldata"><?=vmm_badge($state['state']);?> <?php if ($state['pid']): ?><span class="vmm-mono">PID <?=$state['pid'];?></span><?php endif; ?></td></tr>
            <tr><td class="celltag"><?=gettext('Loader');?></td><td class="celldata"><?=vmm_h((string)($g['loader'] ?? ''));?></td></tr>
            <tr><td class="celltag"><?=gettext('vCPU / Memory');?></td><td class="celldata"><?=vmm_h((string)($g['cpu'] ?? ''));?> vCPU · <?=vmm_h((string)($g['memory'] ?? ''));?><?php if (!empty($g['memory-resident'])): ?> <span class="vmm-mono">(<?=vmm_h((string)$g['memory-resident']);?>)</span><?php endif; ?></td></tr>
            <tr><td class="celltag"><?=gettext('Datastore');?></td><td class="celldata"><?=vmm_h((string)($g['datastore'] ?? ''));?></td></tr>
            <tr><td class="celltag">UUID</td><td class="celldata vmm-mono"><?=vmm_h((string)($g['uuid'] ?? ''));?></td></tr>
            <tr>
                <td class="celltag"><?=gettext('Actions');?></td>
                <td class="celldata">
                    <span class="vmm-actions">
                        <?php if ($isRunning): ?>
                            <button type="submit" class="formbtn" name="act" value="stop:<?=vmm_h($name);?>" onclick="return confirm('Stop <?=vmm_h($name);?>?')"><?=gettext('Stop');?></button>
                            <button type="submit" class="formbtn" name="act" value="restart:<?=vmm_h($name);?>" onclick="return confirm('Restart <?=vmm_h($name);?>?')"><?=gettext('Restart');?></button>
                            <button type="submit" class="formbtn" name="act" value="poweroff:<?=vmm_h($name);?>" onclick="return confirm('Force power off <?=vmm_h($name);?>? Unsaved guest data may be lost.')"><?=gettext('Power off');?></button>
                            <button type="submit" class="formbtn" name="act" value="reset:<?=vmm_h($name);?>" onclick="return confirm('Hard reset <?=vmm_h($name);?>?')"><?=gettext('Reset');?></button>
                        <?php else: ?>
                            <button type="submit" class="formbtn" name="act" value="start:<?=vmm_h($name);?>"><?=gettext('Start');?></button>
                            <input type="text" name="confirm" value="" placeholder="<?=gettext('type name to destroy');?>" style="width:150px"/>
                            <button type="submit" class="formbtn" name="act" value="destroy:<?=vmm_h($name);?>" onclick="return confirm('Destroy <?=vmm_h($name);?>? All disks and snapshots are deleted.')"><?=gettext('Destroy');?></button>
                        <?php endif; ?>
                        <a class="formbtn" href="vmmngr.php"><?=gettext('Back');?></a>
                    </span>
                </td>
            </tr>
        </tbody>
    </table>

    <?php
    $confNow = vmm_conf_get($name);
    $autoOn = in_array($name, vmm_autostart_names(), true);
    $switchNames = array_map(static fn($x) => (string)($x['name'] ?? ''), vmm_switches());
    ?>
    <table class="area_data_settings" style="width:100%;border-collapse:collapse;">
        <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
        <thead><?php html_separator2(); html_titleline2(gettext('Configuration'), 2); ?></thead>
        <tbody>
            <tr>
                <td class="celltag"><?=gettext('vCPU');?></td>
                <td class="celldata"><input type="text" name="cpu" size="6" value="<?=vmm_h((string)($confNow['cpu'] ?? '1'));?>" <?=$isRunning ? 'disabled' : '';?>/></td>
            </tr>
            <tr>
                <td class="celltag"><?=gettext('Memory');?></td>
                <td class="celldata"><input type="text" name="memory" size="8" value="<?=vmm_h((string)($confNow['memory'] ?? '1G'));?>" <?=$isRunning ? 'disabled' : '';?>/></td>
            </tr>
            <tr>
                <td class="celltag"><?=gettext('Loader');?></td>
                <td class="celldata">
                    <select name="loader" <?=$isRunning ? 'disabled' : '';?>>
                        <?php foreach (['uefi', 'uefi-csm', 'grub', 'bhyve'] as $ld): ?>
                            <option value="<?=$ld;?>" <?=($confNow['loader'] ?? '') === $ld ? 'selected' : '';?>><?=$ld;?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <td class="celltag"><?=gettext('Graphics (VNC)');?></td>
                <td class="celldata">
                    <select name="graphics" <?=$isRunning ? 'disabled' : '';?>>
                        <option value="no" <?=($confNow['graphics'] ?? 'no') === 'no' ? 'selected' : '';?>>no</option>
                        <option value="yes" <?=($confNow['graphics'] ?? '') === 'yes' ? 'selected' : '';?>>yes</option>
                    </select>
                </td>
            </tr>
            <tr>
                <td class="celltag"><?=gettext('Network switch');?></td>
                <td class="celldata">
                    <select name="switch" <?=$isRunning ? 'disabled' : '';?>>
                        <?php foreach ($switchNames as $sw): ?>
                            <option value="<?=vmm_h($sw);?>" <?=($confNow['network0_switch'] ?? '') === $sw ? 'selected' : '';?>><?=vmm_h($sw);?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <td class="celltag"><?=gettext('Autostart');?></td>
                <td class="celldata">
                    <label><input type="checkbox" name="autostart" value="1" <?=$autoOn ? 'checked' : '';?>/> <?=gettext('start at boot (vm_list)');?></label>
                </td>
            </tr>
            <tr>
                <td class="celltag">&nbsp;</td>
                <td class="celldata">
                    <button type="submit" class="formbtn" name="act" value="save_config:<?=vmm_h($name);?>" <?=$isRunning ? 'disabled' : '';?>><?=gettext('Save');?></button>
                    <?php if ($isRunning): ?>
                        <span style="opacity:.75;margin-left:8px;"><?=gettext('Stop the VM to change these values.');?></span>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>

    <?php
    $vncBind = '';
    foreach (($info['sections']['console-ports'] ?? []) as $b) {
        if (!empty($b['data']['vnc'])) {
            $vncBind = (string)$b['data']['vnc'];
        }
    }
    $vncTarget = $vncBind !== '' ? vmm_vnc_connect($vncBind, vmm_vnc_host_for_vm($name)) : '';
    ?>
    <?php if ($vncTarget !== ''): ?>
    <table class="area_data_settings" style="width:100%;border-collapse:collapse;">
        <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
        <thead><?php html_separator2(); html_titleline2(gettext('Console access'), 2); ?></thead>
        <tbody>
            <tr>
                <td class="celltag">VNC</td>
                <td class="celldata">
                    <a class="formbtn" href="vnc://<?=vmm_h($vncTarget);?>">vnc://<?=vmm_h($vncTarget);?></a>
                    <span class="vmm-mono" style="margin-left:8px;"><?=gettext('bind');?>: <?=vmm_h($vncBind);?></span>
                    <div style="opacity:.75;margin-top:4px;"><?=gettext('0.0.0.0 is the listening address - connect to the host name above. No password is set.');?></div>
                </td>
            </tr>
            <tr>
                <td class="celltag"><?=gettext('Serial');?></td>
                <td class="celldata vmm-mono">cu -l /dev/nmdm-<?=vmm_h($name);?>.1B</td>
            </tr>
            <?php $con = vmm_console_status($name); ?>
            <tr>
                <td class="celltag"><?=gettext('Web terminal');?></td>
                <td class="celldata">
                    <?php if ($con !== null): ?>
                        <?php $conUrl = $con['url'] ?? ('http://' . $con['host'] . ':' . (int)$con['port'] . '/'); ?>
                        <a class="formbtn" href="<?=vmm_h($conUrl);?>" target="_blank"><?=gettext('open in a tab');?></a>
                        <button type="submit" class="formbtn" name="act" value="console_stop:<?=vmm_h($name);?>" style="margin-left:8px;"><?=gettext('Stop');?></button>
                        <div style="opacity:.75;margin-top:4px;"><?=gettext('The URL contains a random token - treat it like a password.');?></div>
                    <?php else: ?>
                        <button type="submit" class="formbtn" name="act" value="console_start:<?=vmm_h($name);?>"><?=gettext('Start web console');?></button>
                        <span style="opacity:.75;margin-left:8px;"><?=gettext('ttyd serves the serial console (text only) with basic auth.');?></span>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>

    <?php if ($con !== null): ?>
        <?php $conUrl = $con['url'] ?? ('http://' . $con['host'] . ':' . (int)$con['port'] . '/'); ?>
        <table class="area_data_settings" style="width:100%;border-collapse:collapse;">
            <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
            <thead><?php html_separator2(); html_titleline2(gettext('Web console'), 2); ?></thead>
            <tbody>
                <tr>
                    <td colspan="2" class="celldata">
                        <div style="margin-bottom:6px;opacity:.75;font-size:12px;">
                            <?=gettext('No login prompt - the URL itself carries the token.');?>
                        </div>
                        <iframe src="<?=vmm_h($conUrl);?>" style="width:100%;height:440px;border:1px solid rgba(127,127,127,.4);border-radius:4px;background:#000;"></iframe>
                    </td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <?php $nv = vmm_novnc_ready(); $nvSt = vmm_novnc_status($name); ?>
    <table class="area_data_settings" style="width:100%;border-collapse:collapse;">
        <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
        <thead><?php html_separator2(); html_titleline2(gettext('Graphical console (noVNC)'), 2); ?></thead>
        <tbody>
            <tr>
                <td class="celltag"><?=gettext('Status');?></td>
                <td class="celldata">
                    <?php if (!$nv['ok']): ?>
                        <span class="vmm-miss"><?=gettext('not installed');?></span>
                        <span class="vmm-mono" style="margin-left:8px;">pkg install novnc py312-websockify</span>
                    <?php elseif ($nvSt !== null): ?>
                        <a class="formbtn" href="<?=vmm_h('http://' . $nvSt['host'] . ':' . (int)$nvSt['port'] . '/vnc.html?autoconnect=1&resize=scale&path=websockify');?>" target="_blank"><?=gettext('open in a tab');?></a>
                        <button type="submit" class="formbtn" name="act" value="novnc_stop:<?=vmm_h($name);?>" style="margin-left:8px;"><?=gettext('Stop');?></button>
                        <div style="opacity:.75;margin-top:4px;"><?=gettext('Serves the VNC screen of this VM through websockify. No password - same exposure as the VNC port itself.');?></div>
                    <?php else: ?>
                        <button type="submit" class="formbtn" name="act" value="novnc_start:<?=vmm_h($name);?>"><?=gettext('Start noVNC');?></button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($nvSt !== null): ?>
            <tr>
                <td colspan="2" class="celldata">
                    <iframe src="<?=vmm_h('http://' . $nvSt['host'] . ':' . (int)$nvSt['port'] . '/vnc.html?autoconnect=1&resize=scale&path=websockify');?>"
                            style="width:100%;height:520px;border:1px solid rgba(127,127,127,.4);border-radius:4px;background:#000;"></iframe>
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php
    $attached = vmm_attached_isos($name);
    $isos = vmm_file_list(vmm_data_dir('.iso'), ['iso']);
    ?>
    <table class="area_data_settings" style="width:100%;border-collapse:collapse;">
        <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
        <thead>
            <?php html_separator2(); html_titleline2(gettext('Install / media'), 2); ?>
        </thead>
        <tbody>
            <tr>
                <td class="celltag"><?=gettext('Attached');?></td>
                <td class="celldata vmm-mono">
                    <?php if ($attached === []): ?>
                        <?=gettext('none');?>
                    <?php else: ?>
                        <?php foreach ($attached as $a): ?>
                            <div>disk<?=(int)$a['slot'];?> → <?=vmm_h((string)$a['path']);?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if (!$isRunning): ?>
            <tr>
                <td class="celltag"><?=gettext('Boot ISO');?></td>
                <td class="celldata">
                    <select name="iso">
                        <option value=""><?=gettext('none');?></option>
                        <?php foreach ($isos as $i): ?>
                            <option value="<?=vmm_h((string)$i['name']);?>"><?=vmm_h((string)$i['name']);?></option>
                        <?php endforeach; ?>
                    </select>
                    <span style="opacity:.75;margin-left:8px;"><?=gettext('Windows: boot from the installation ISO, drivers from virtio-win below.');?></span>
                </td>
            </tr>
            <tr>
                <td class="celltag"><?=gettext('Driver ISO');?></td>
                <td class="celldata">
                    <select name="driver_iso">
                        <option value=""><?=gettext('none');?></option>
                        <?php foreach ($isos as $i): ?>
                            <?php if (stripos((string)$i['name'], 'virtio') === false) { continue; } ?>
                            <option value="<?=vmm_h((string)$i['name']);?>"><?=vmm_h((string)$i['name']);?></option>
                        <?php endforeach; ?>
                    </select>
                    <span style="opacity:.75;margin-left:8px;"><?=gettext('Only virtio-* images are listed here.');?></span>
                </td>
            </tr>
            <tr>
                <td class="celltag"><?=gettext('Actions');?></td>
                <td class="celldata">
                    <button type="submit" class="formbtn" name="act" value="install:<?=vmm_h($name);?>"><?=gettext('Attach and start');?></button>
                    <button type="submit" class="formbtn" name="act" value="eject:<?=vmm_h($name);?>" onclick="return confirm('Eject all attached ISOs?')"><?=gettext('Eject all');?></button>
                    <span style="opacity:.75;margin-left:8px;"><?=gettext('Attaching rewrites the VM config (backup as .vmmngr.bak).');?></span>
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div id="vmm-job" class="vmm-job"></div>

    <?php foreach (($info['sections'] ?? []) as $type => $blocks): ?>
        <table class="area_data_settings" style="width:100%;border-collapse:collapse;">
            <colgroup>
                <col class="area_data_settings_col_tag">
                <col class="area_data_settings_col_data">
            </colgroup>
            <thead>
                <?php html_separator2(); html_titleline2(vmm_h(ucwords(str_replace('-', ' ', $type))), 2); ?>
            </thead>
            <tbody>
            <?php foreach ($blocks as $b): ?>
                <?php foreach ($b['data'] as $k => $v): ?>
                    <tr>
                        <td class="celltag"><?=vmm_h((string)$k);?></td>
                        <td class="celldata vmm-mono"><?=vmm_h((string)$v);?></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($b['lines'] as $line): ?>
                    <tr>
                        <td class="celltag">—</td>
                        <td class="celldata vmm-mono"><?=vmm_h((string)$line);?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($b['data'] === [] && $b['lines'] === []): ?>
                    <tr><td class="celltag">—</td><td class="celldata"><?=gettext('none');?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <br />
    <?php endforeach; ?>

    <table class="area_data_selection" style="width:100%;border-collapse:collapse;">
        <colgroup>
            <col style="width:30%">
            <col style="width:12%">
            <col style="width:12%">
            <col style="width:46%">
        </colgroup>
        <thead>
            <?php html_separator2(); html_titleline2(gettext('Jobs for this VM'), 4); ?>
            <tr>
                <th class="lhell"><?=gettext('Job');?></th>
                <th class="lhell"><?=gettext('Action');?></th>
                <th class="lhell"><?=gettext('State');?></th>
                <th class="lhebl"><?=gettext('Started');?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ($jobs === []): ?>
            <tr><td class="lcell" colspan="4"><?=gettext('No jobs yet.');?></td></tr>
        <?php else: ?>
            <?php foreach ($jobs as $j): ?>
                <tr>
                    <td class="lcell vmm-mono"><a href="vmmngr_jobs.php?id=<?=urlencode((string)$j['id']);?>"><?=vmm_h((string)$j['id']);?></a></td>
                    <td class="lcell"><?=vmm_h((string)$j['verb']);?></td>
                    <td class="lcell"><span class="<?=($j['state'] ?? '') === 'failed' ? 'vmm-err' : 'vmm-ok';?>"><?=vmm_h((string)$j['state']);?></span></td>
                    <td class="lcebl vmm-mono"><?=!empty($j['started']) ? date('Y-m-d H:i:s', (int)$j['started']) : '—';?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <details style="margin-top:12px;">
        <summary style="cursor:pointer;padding:6px 0;"><?=gettext('Raw vm-bhyve output');?></summary>
        <pre class="vmm-raw vmm-mono"><?=vmm_h((string)($info['raw'] ?? ''));?></pre>
    </details>
<?php endif; ?>

</td></tr></tbody></table><?php include 'formend.inc'; ?></form>
<?php include 'fend.inc'; ?>
