<?php
/*
 * vmmngr_template.php - template editor (0.3.0)
 */

require_once 'vmmngr-boot.inc';

$name = (string)($_GET['name'] ?? '');
$tpl = vmm_valid_template_name($name) ? vmm_template_get($name) : ['ok' => false, 'error' => 'invalid template name'];

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
    $content = (string)($_POST['content'] ?? '');
    $res = vmm_template_save($name, $content);
    $_SESSION['vmmngr_error'] = $res['ok']
        ? gettext('Template saved.')
        : (string)$res['error'];
    header('Location: vmmngr_template.php?name=' . urlencode($name));
    exit;
}

$pgtitle = [gettext('Extensions'), gettext('VM Manager'), gettext('Template'), $name];
include 'fbegin.inc';
vmm_css();
vmm_nav('templates');
?>
<form action="vmmngr_template.php?name=<?=urlencode($name);?>" method="post" name="iform" id="iform"><table id="area_data"><tbody><tr><td id="area_data_frame">

<?php if (!empty($_SESSION['vmmngr_error'])): ?>
    <?php print_info_box(vmm_h((string)$_SESSION['vmmngr_error'])); unset($_SESSION['vmmngr_error']); ?>
<?php endif; ?>

<?php if (!$tpl['ok']): ?>
    <?php print_info_box(vmm_h((string)$tpl['error'])); ?>
<?php else: ?>
<table class="area_data_settings" style="width:100%;border-collapse:collapse;">
    <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
    <thead>
        <?php html_separator2(); html_titleline2(sprintf('%s: %s', gettext('Template'), $name), 2); ?>
    </thead>
    <tbody>
        <tr>
            <td class="celltag"><?=gettext('Content');?></td>
            <td class="celldata">
                <textarea name="content" rows="26" style="width:98%;font-family:monospace;font-size:12px;"><?=vmm_h((string)$tpl['content']);?></textarea>
            </td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Actions');?></td>
            <td class="celldata">
                <button type="submit" class="formbtn"><?=gettext('Save');?></button>
                <a class="formbtn" href="vmmngr_templates.php"><?=gettext('Back');?></a>
                <span style="opacity:.75;margin-left:8px;"><?=gettext('Value format: key="value" per line, as vm-bhyve expects.');?></span>
            </td>
        </tr>
    </tbody>
</table>
<?php endif; ?>

<?php include 'formend.inc'; ?>
</td></tr></tbody></table></form>
<?php include 'fend.inc'; ?>
