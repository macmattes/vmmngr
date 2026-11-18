<?php
/*
 * vmmngr_templates.php - template inventory and file operations (0.3.0)
 */

require_once 'vmmngr-boot.inc';

$tplDir = vmm_data_dir('.templates');
$post = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST';
$act = $post ? (string)($_POST['act'] ?? '') : '';
$confirm = $post ? (string)($_POST['confirm'] ?? '') : '';
$newname = $post ? trim((string)($_POST['newname'] ?? '')) : '';
if ($post && $newname === '') {
    $newname = trim((string)($_POST['new_name_input'] ?? ''));
}

if ($post && $act !== '') {
    if (str_starts_with($act, 'duplicate:')) {
        $src = substr($act, 10);
        $dst = vmm_safe_basename($newname);
        if ($dst === '' || !str_ends_with($dst, '.conf')) {
            $_SESSION['vmmngr_error'] = gettext('New name must end with .conf');
        } elseif (is_file($tplDir . '/' . $dst)) {
            $_SESSION['vmmngr_error'] = gettext('Template already exists.');
        } else {
            $t = vmm_template_get($src);
            if (!$t['ok']) {
                $_SESSION['vmmngr_error'] = (string)$t['error'];
            } else {
                $res = vmm_template_save($dst, (string)$t['content']);
                $_SESSION['vmmngr_error'] = $res['ok']
                    ? sprintf(gettext('Duplicated to %s'), $dst)
                    : (string)$res['error'];
            }
        }
    } elseif (str_starts_with($act, 'delete:')) {
        $src = substr($act, 7);
        if ($confirm !== $src) {
            $_SESSION['vmmngr_error'] = gettext('Confirmation mismatch - template not deleted.');
        } else {
            $path = $tplDir . '/' . vmm_safe_basename($src);
            $_SESSION['vmmngr_error'] = is_file($path) && @unlink($path)
                ? sprintf(gettext('Deleted %s'), $src)
                : gettext('Cannot delete template.');
        }
    } elseif ($act === 'new') {
        $dst = vmm_safe_basename($newname);
        if ($dst === '' || !str_ends_with($dst, '.conf')) {
            $_SESSION['vmmngr_error'] = gettext('Name must end with .conf');
        } elseif (is_file($tplDir . '/' . $dst)) {
            $_SESSION['vmmngr_error'] = gettext('Template already exists.');
        } else {
            $skeleton = "guest=\"linux\"\nloader=\"uefi\"\ncpu=2\nmemory=2G\n"
                . "network0_type=\"virtio-net\"\nnetwork0_switch=\"public\"\n"
                . "disk0_type=\"virtio-blk\"\ndisk0_name=\"disk0\"\ndisk0_dev=\"zvol\"\n";
            $res = vmm_template_save($dst, $skeleton);
            if ($res['ok']) {
                header('Location: vmmngr_template.php?name=' . urlencode($dst));
                exit;
            }
            $_SESSION['vmmngr_error'] = (string)$res['error'];
        }
    }
    header('Location: vmmngr_templates.php');
    exit;
}

$templates = vmm_file_list($tplDir, ['conf']);
$pgtitle = [gettext('Extensions'), gettext('VM Manager'), gettext('Templates')];

include 'fbegin.inc';
vmm_css();
vmm_nav('templates');
?>
<script>
function vmmAskName(promptText) {
    var typed = window.prompt(promptText, '');
    if (typed === null || typed === '') { return false; }
    document.getElementById('vmm-newname').value = typed;
    return true;
}
function vmmAskDelete(name) {
    var typed = window.prompt('Type the template name to delete:\n' + name, '');
    if (typed !== name) { return false; }
    document.getElementById('vmm-confirm').value = typed;
    return true;
}
</script>

<form action="vmmngr_templates.php" method="post" name="iform" id="iform"><table id="area_data"><tbody><tr><td id="area_data_frame">

<?php if (!empty($_SESSION['vmmngr_error'])): ?>
    <?php print_info_box(vmm_h((string)$_SESSION['vmmngr_error'])); unset($_SESSION['vmmngr_error']); ?>
<?php endif; ?>

<input type="hidden" name="confirm" id="vmm-confirm" value=""/>
<input type="hidden" name="newname" id="vmm-newname" value=""/>

<table class="area_data_settings" style="width:100%;border-collapse:collapse;">
    <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
    <thead>
        <?php html_separator2(); html_titleline2(gettext('New template'), 2); ?>
    </thead>
    <tbody>
        <tr>
            <td class="celltag"><?=gettext('Name');?></td>
            <td class="celldata">
                <input type="text" name="new_name_input" size="30" placeholder="myvm.conf" oninput="document.getElementById('vmm-newname').value=this.value"/>
                <button type="submit" class="formbtn" name="act" value="new"><?=gettext('Create');?></button>
                <span style="opacity:.75;margin-left:8px;"><?=gettext('Starts from a minimal skeleton, then opens the editor.');?></span>
            </td>
        </tr>
    </tbody>
</table>

<br />

<table class="area_data_selection" style="width:100%;border-collapse:collapse;">
    <colgroup>
        <col style="width:38%"><col style="width:12%"><col style="width:20%"><col style="width:30%">
    </colgroup>
    <thead>
        <?php html_separator2(); html_titleline2(sprintf('%s (%s)', gettext('Templates'), $tplDir), 4); ?>
        <tr>
            <th class="lhell"><?=gettext('Template');?></th>
            <th class="lhell"><?=gettext('Size');?></th>
            <th class="lhell"><?=gettext('Modified');?></th>
            <th class="lhebl"><?=gettext('Actions');?></th>
        </tr>
    </thead>
    <tbody>
    <?php if ($templates === []): ?>
        <tr><td class="lcell" colspan="4"><?=gettext('No templates.');?></td></tr>
    <?php else: ?>
        <?php foreach ($templates as $t): ?>
            <?php $tn = (string)$t['name']; ?>
            <tr>
                <td class="lcell vmm-mono"><a href="vmmngr_template.php?name=<?=urlencode($tn);?>"><?=vmm_h($tn);?></a></td>
                <td class="lcell"><?=vmm_h(vmm_human_bytes((int)$t['size']));?></td>
                <td class="lcell vmm-mono"><?=date('Y-m-d H:i', (int)$t['mtime']);?></td>
                <td class="lcebl">
                    <a class="formbtn" href="vmmngr_template.php?name=<?=urlencode($tn);?>"><?=gettext('Edit');?></a>
                    <button type="submit" class="formbtn" name="act" value="duplicate:<?=vmm_h($tn);?>"
                            onclick="return vmmAskName('New name for the copy (must end with .conf):')"><?=gettext('Duplicate');?></button>
                    <button type="submit" class="formbtn" name="act" value="delete:<?=vmm_h($tn);?>"
                            onclick="return vmmAskDelete(<?=htmlspecialchars(json_encode($tn), ENT_QUOTES);?>)"><?=gettext('Delete');?></button>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<p><a class="formbtn" href="vmmngr_create.php"><?=gettext('Create VM');?></a> <a class="formbtn" href="vmmngr.php"><?=gettext('Back');?></a></p>

<?php include 'formend.inc'; ?>
</td></tr></tbody></table></form>
<?php include 'fend.inc'; ?>
