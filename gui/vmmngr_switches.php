<?php
/*
 * vmmngr_switches.php - vm-bhyve switches on existing host bridges (0.9.0)
 */

require_once 'vmmngr-boot.inc';

$post = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST';
$act = $post ? (string)($_POST['act'] ?? '') : '';

if ($post && $act === 'add') {
    $swName = trim((string)($_POST['sw_name'] ?? ''));
    $bridge = trim((string)($_POST['bridge'] ?? ''));
    $hostBridges = array_filter(explode("\n", trim((string)@shell_exec('/sbin/ifconfig -g bridge 2>/dev/null'))));
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/', $swName)) {
        $_SESSION['vmmngr_error'] = gettext('Invalid switch name.');
    } elseif (!in_array($bridge, $hostBridges, true)) {
        $_SESSION['vmmngr_error'] = gettext('Choose an existing host bridge - the addon does not create bridges.');
    } else {
        $res = vmm_run(['switch', 'create', '-t', 'manual', '-b', $bridge, $swName], 30);
        $_SESSION['vmmngr_error'] = $res['rc'] === 0
            ? sprintf(gettext('Switch %s created on %s.'), $swName, $bridge)
            : trim($res['stderr'] ?: $res['stdout'] ?: 'unknown error');
    }
    header('Location: vmmngr_switches.php');
    exit;
}

if ($post && str_starts_with($act, 'destroy:')) {
    $swName = substr($act, 8);
    if (($_POST['confirm'] ?? '') !== $swName) {
        $_SESSION['vmmngr_error'] = gettext('Confirmation mismatch - switch kept.');
    } else {
        $res = vmm_run(['switch', 'destroy', $swName], 30);
        $_SESSION['vmmngr_error'] = $res['rc'] === 0
            ? sprintf(gettext('Switch %s removed.'), $swName)
            : trim($res['stderr'] ?: $res['stdout'] ?: 'unknown error');
    }
    header('Location: vmmngr_switches.php');
    exit;
}

$switches = vmm_switches();
$vms = vmm_list();
$bySwitch = [];
foreach ($vms as $v) {
    $info = vmm_info((string)$v['name']);
    foreach (($info['sections']['network-interface'] ?? []) as $nic) {
        $sw = (string)($nic['data']['virtual-switch'] ?? '');
        if ($sw !== '') {
            $bySwitch[$sw][] = (string)$v['name'];
        }
    }
}
$hostBridges = array_values(array_filter(array_map('trim', explode("\n", trim((string)@shell_exec('/sbin/ifconfig -g bridge 2>/dev/null'))))));

$pgtitle = [gettext('Extensions'), gettext('VM Manager'), gettext('Switches')];
include 'fbegin.inc';
vmm_css();
vmm_nav('switches');
?>
<script>
function vmmAskSwitch(name) {
    var typed = window.prompt('Type the switch name to remove:\n' + name, '');
    if (typed !== name) { return false; }
    document.getElementById('vmm-confirm').value = typed;
    return true;
}
</script>

<form action="vmmngr_switches.php" method="post" name="iform" id="iform"><table id="area_data"><tbody><tr><td id="area_data_frame">

<?php if (!empty($_SESSION['vmmngr_error'])): ?>
    <?php print_info_box(vmm_h((string)$_SESSION['vmmngr_error'])); unset($_SESSION['vmmngr_error']); ?>
<?php endif; ?>

<input type="hidden" name="confirm" id="vmm-confirm" value=""/>

<table class="area_data_settings" style="width:100%;border-collapse:collapse;">
    <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
    <thead><?php html_separator2(); html_titleline2(gettext('Add switch'), 2); ?></thead>
    <tbody>
        <tr>
            <td class="celltag"><?=gettext('Name');?></td>
            <td class="celldata"><input type="text" name="sw_name" size="20" placeholder="lan2"/></td>
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
                <span style="opacity:.75;margin-left:8px;"><?=gettext('Bridges are created in Networking > Bridges; this page only maps vm-bhyve switches to them.');?></span>
            </td>
        </tr>
        <tr>
            <td class="celltag">&nbsp;</td>
            <td class="celldata">
                <button type="submit" class="formbtn" name="act" value="add"><?=gettext('Add');?></button>
                <span style="opacity:.75;margin-left:8px;"><?=gettext('Type "manual" - the bridge owns the ports.');?></span>
            </td>
        </tr>
    </tbody>
</table>

<br />

<table class="area_data_selection" style="width:100%;border-collapse:collapse;">
    <colgroup>
        <col style="width:16%"><col style="width:10%"><col style="width:18%"><col style="width:14%"><col style="width:8%"><col style="width:34%">
    </colgroup>
    <thead>
        <?php html_separator2(); html_titleline2(gettext('Switches'), 6); ?>
        <tr>
            <th class="lhell"><?=gettext('Name');?></th>
            <th class="lhell"><?=gettext('Type');?></th>
            <th class="lhell"><?=gettext('Interface');?></th>
            <th class="lhell"><?=gettext('Address');?></th>
            <th class="lhell">VLAN</th>
            <th class="lhebl"><?=gettext('Used by');?></th>
        </tr>
    </thead>
    <tbody>
    <?php if ($switches === []): ?>
        <tr><td class="lcell" colspan="6"><?=gettext('No switches.');?></td></tr>
    <?php else: ?>
        <?php foreach ($switches as $s): ?>
            <?php
            $name = (string)($s['name'] ?? '');
            $iface = (string)($s['iface'] ?? '');
            $used = array_values(array_unique($bySwitch[$name] ?? []));
            ?>
            <tr>
                <td class="lcell"><strong><?=vmm_h($name);?></strong></td>
                <td class="lcell"><?=vmm_h((string)($s['type'] ?? ''));?></td>
                <td class="lcell vmm-mono"><?=vmm_h($iface);?><?php $ip = vmm_iface_ipv4($iface); ?><?php if ($ip !== ''): ?> <span style="opacity:.7">(<?=vmm_h($ip);?>)</span><?php endif; ?></td>
                <td class="lcell vmm-mono"><?=vmm_h((string)($s['address'] ?? ''));?></td>
                <td class="lcell vmm-mono"><?=vmm_h((string)($s['vlan'] ?? ''));?></td>
                <td class="lcebl">
                    <?php if ($used !== []): ?>
                        <span class="vmm-mono"><?=vmm_h(implode(', ', $used));?></span>
                    <?php else: ?>
                        <button type="submit" class="formbtn" name="act" value="destroy:<?=vmm_h($name);?>"
                                onclick="return vmmAskSwitch(<?=htmlspecialchars(json_encode($name), ENT_QUOTES);?>)"><?=gettext('Remove');?></button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<div id="remarks">
    <?php html_remark2('swnote', gettext('Note'), gettext('"manual" switches take their ports from the host bridge. netgraph-based switches are intentionally not offered - they can break host networking.')); ?>
</div>

<p><a class="formbtn" href="vmmngr_create.php"><?=gettext('Create VM');?></a> <a class="formbtn" href="vmmngr.php"><?=gettext('Dashboard');?></a></p>

<?php include 'formend.inc'; ?>
</td></tr></tbody></table></form>
<?php include 'fend.inc'; ?>
