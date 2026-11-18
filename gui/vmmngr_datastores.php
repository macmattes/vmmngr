<?php
/*
 * vmmngr_datastores.php - vm-bhyve datastores (0.8.0)
 */

require_once 'vmmngr-boot.inc';

$post = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST';
$act = $post ? (string)($_POST['act'] ?? '') : '';

if ($post && $act === 'add') {
    $dsName = trim((string)($_POST['ds_name'] ?? ''));
    $dsPath = trim((string)($_POST['ds_path'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/', $dsName)) {
        $_SESSION['vmmngr_error'] = gettext('Invalid datastore name (letters, digits, dash, underscore).');
    } elseif (!preg_match('#^(zfs:[A-Za-z0-9._:/-]+|/mnt/[A-Za-z0-9._/-]+)$#', $dsPath)) {
        $_SESSION['vmmngr_error'] = gettext('Path must be zfs:pool/dataset or below /mnt.');
    } else {
        $res = vmm_run(['datastore', 'add', $dsName, $dsPath], 20);
        $_SESSION['vmmngr_error'] = $res['rc'] === 0
            ? sprintf(gettext('Datastore %s added.'), $dsName)
            : trim($res['stderr'] ?: $res['stdout'] ?: 'unknown error');
    }
    header('Location: vmmngr_datastores.php');
    exit;
}

if ($post && str_starts_with($act, 'remove:')) {
    $dsName = substr($act, 7);
    if (($_POST['confirm'] ?? '') !== $dsName) {
        $_SESSION['vmmngr_error'] = gettext('Confirmation mismatch - datastore not removed.');
    } else {
        $res = vmm_run(['datastore', 'remove', $dsName], 20);
        $_SESSION['vmmngr_error'] = $res['rc'] === 0
            ? sprintf(gettext('Datastore %s removed from the configuration (data is untouched).'), $dsName)
            : trim($res['stderr'] ?: $res['stdout'] ?: 'unknown error');
    }
    header('Location: vmmngr_datastores.php');
    exit;
}

$stores = vmm_datastores();
$vms = vmm_list();
$byStore = [];
foreach ($vms as $v) {
    $byStore[(string)$v['datastore']][] = (string)$v['name'];
}
$vmDefault = vmm_vm_dir();

$pgtitle = [gettext('Extensions'), gettext('VM Manager'), gettext('Datastores')];
include 'fbegin.inc';
vmm_css();
vmm_nav('datastores');
?>
<script>
function vmmAskStore(name) {
    var typed = window.prompt('Type the datastore name to remove:\n' + name, '');
    if (typed !== name) { return false; }
    document.getElementById('vmm-confirm').value = typed;
    return true;
}
</script>

<form action="vmmngr_datastores.php" method="post" name="iform" id="iform"><table id="area_data"><tbody><tr><td id="area_data_frame">

<?php if (!empty($_SESSION['vmmngr_error'])): ?>
    <?php print_info_box(vmm_h((string)$_SESSION['vmmngr_error'])); unset($_SESSION['vmmngr_error']); ?>
<?php endif; ?>

<input type="hidden" name="confirm" id="vmm-confirm" value=""/>

<table class="area_data_settings" style="width:100%;border-collapse:collapse;">
    <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
    <thead><?php html_separator2(); html_titleline2(gettext('Add datastore'), 2); ?></thead>
    <tbody>
        <tr>
            <td class="celltag"><?=gettext('Name');?></td>
            <td class="celldata"><input type="text" name="ds_name" size="20" placeholder="NVME"/></td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Path');?></td>
            <td class="celldata">
                <input type="text" name="ds_path" size="46" placeholder="zfs:hdd_vm_pool/bhyve2"/>
                <span style="opacity:.75;margin-left:8px;"><?=gettext('zfs:pool/dataset or a directory below /mnt');?></span>
            </td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Default datastore');?></td>
            <td class="celldata vmm-mono"><?=vmm_h($vmDefault);?></td>
        </tr>
        <tr>
            <td class="celltag">&nbsp;</td>
            <td class="celldata">
                <button type="submit" class="formbtn" name="act" value="add"><?=gettext('Add');?></button>
                <span style="opacity:.75;margin-left:8px;"><?=gettext('The name appears in the create wizard immediately.');?></span>
            </td>
        </tr>
    </tbody>
</table>

<br />

<table class="area_data_selection" style="width:100%;border-collapse:collapse;">
    <colgroup>
        <col style="width:16%"><col style="width:10%"><col style="width:30%"><col style="width:18%"><col style="width:26%">
    </colgroup>
    <thead>
        <?php html_separator2(); html_titleline2(gettext('Datastores'), 5); ?>
        <tr>
            <th class="lhell"><?=gettext('Name');?></th>
            <th class="lhell"><?=gettext('Type');?></th>
            <th class="lhell"><?=gettext('Path');?></th>
            <th class="lhell"><?=gettext('ZFS dataset');?></th>
            <th class="lhebl"><?=gettext('Used by');?></th>
        </tr>
    </thead>
    <tbody>
    <?php if ($stores === []): ?>
        <tr><td class="lcell" colspan="5"><?=gettext('No datastores reported.');?></td></tr>
    <?php else: ?>
        <?php foreach ($stores as $s): ?>
            <?php
            $name = (string)($s['name'] ?? '');
            $used = $byStore[$name] ?? [];
            $isDefault = $name === 'default';
            ?>
            <tr>
                <td class="lcell"><strong><?=vmm_h($name);?></strong><?php if ($isDefault): ?> <span style="opacity:.6">(default)</span><?php endif; ?></td>
                <td class="lcell"><?=vmm_h((string)($s['type'] ?? ''));?></td>
                <td class="lcell vmm-mono"><?=vmm_h((string)($s['path'] ?? ''));?></td>
                <td class="lcell vmm-mono"><?=vmm_h((string)($s['zfs_dataset'] ?? ''));?></td>
                <td class="lcebl">
                    <?php if ($used !== []): ?>
                        <span class="vmm-mono"><?=vmm_h(implode(', ', $used));?></span>
                    <?php elseif ($isDefault): ?>
                        <span style="opacity:.6"><?=gettext('cannot be removed');?></span>
                    <?php else: ?>
                        <button type="submit" class="formbtn" name="act" value="remove:<?=vmm_h($name);?>"
                                onclick="return vmmAskStore(<?=htmlspecialchars(json_encode($name), ENT_QUOTES);?>)"><?=gettext('Remove');?></button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<div id="remarks">
    <?php html_remark2('dsnote', gettext('Note'), gettext('Removing a datastore only drops it from the vm-bhyve configuration - disks and datasets stay untouched. Renaming is not supported by vm-bhyve; add the new name and remove the old one while no VM uses it.')); ?>
</div>

<p><a class="formbtn" href="vmmngr_create.php"><?=gettext('Create VM');?></a> <a class="formbtn" href="vmmngr.php"><?=gettext('Dashboard');?></a></p>

<?php include 'formend.inc'; ?>
</td></tr></tbody></table></form>
<?php include 'fend.inc'; ?>
