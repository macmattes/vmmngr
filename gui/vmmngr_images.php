<?php
/*
 * vmmngr_images.php - ISO/IMG inventory and downloads (0.3.0)
 */

require_once 'vmmngr-boot.inc';

$isoDir = vmm_data_dir('.iso');
$imgDir = vmm_data_dir('.img');

$post = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST';
$act = $post ? (string)($_POST['act'] ?? '') : '';
$confirm = $post ? (string)($_POST['confirm'] ?? '') : '';

$presets = [
    'virtio' => ['kind' => 'iso', 'url' => 'https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/stable-virtio/virtio-win.iso'],
];

if ($post && $act !== '') {
    if (str_starts_with($act, 'preset:')) {
        $key = substr($act, 7);
        if (isset($presets[$key])) {
            $job = vmm_job_new($presets[$key]['kind'] . '_download', $key, [$presets[$key]['kind'], $presets[$key]['url']], 21600);
            header('Location: vmmngr_jobs.php?id=' . urlencode((string)$job['id']));
            exit;
        }
    }
    if ($act === 'download') {
        $kind = (string)($_POST['kind'] ?? 'iso');
        $url = trim((string)($_POST['url'] ?? ''));
        if (!preg_match('#^https?://[^\s"\'<>]+$#i', $url)) {
            $_SESSION['vmmngr_error'] = gettext('Only http(s) URLs are allowed.');
        } elseif (!in_array($kind, ['iso', 'img'], true)) {
            $_SESSION['vmmngr_error'] = gettext('Unknown image type.');
        } else {
            $job = vmm_job_new(
                $kind . '_download',
                (string)(basename((string)parse_url($url, PHP_URL_PATH)) ?: $kind),
                [$kind, $url],
                21600
            );
            header('Location: vmmngr_jobs.php?id=' . urlencode((string)$job['id']));
            exit;
        }
    } elseif (str_starts_with($act, 'delete:')) {
        [, $kind, $file] = array_pad(explode(':', $act, 3), 3, '');
        $dir = $kind === 'img' ? $imgDir : $isoDir;
        if ($confirm !== $file) {
            $_SESSION['vmmngr_error'] = gettext('Confirmation mismatch - file not deleted.');
        } else {
            $res = vmm_delete_data_file($dir, $file);
            $_SESSION['vmmngr_error'] = $res['ok']
                ? sprintf(gettext('Deleted %s'), $res['deleted'])
                : (string)$res['error'];
        }
        header('Location: vmmngr_images.php');
        exit;
    }
}

$isos = vmm_file_list($isoDir, ['iso']);
$imgs = vmm_file_list($imgDir, ['img']);
$pgtitle = [gettext('Extensions'), gettext('VM Manager'), gettext('Images')];

include 'fbegin.inc';
vmm_css();
vmm_nav('images');
?>
<script>
function vmmAskDelete(name) {
    var typed = window.prompt('Type the file name to delete:\n' + name, '');
    if (typed !== name) { return false; }
    document.getElementById('vmm-confirm').value = typed;
    return true;
}
</script>

<form action="vmmngr_images.php" method="post" name="iform" id="iform"><table id="area_data"><tbody><tr><td id="area_data_frame">

<?php if (!empty($_SESSION['vmmngr_error'])): ?>
    <?php print_info_box(vmm_h((string)$_SESSION['vmmngr_error'])); unset($_SESSION['vmmngr_error']); ?>
<?php endif; ?>

<input type="hidden" name="confirm" id="vmm-confirm" value=""/>

<table class="area_data_settings" style="width:100%;border-collapse:collapse;">
    <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
    <thead>
        <?php html_separator2(); html_titleline2(gettext('Download image'), 2); ?>
    </thead>
    <tbody>
        <tr>
            <td class="celltag"><?=gettext('Type');?></td>
            <td class="celldata">
                <select name="kind">
                    <option value="iso">ISO</option>
                    <option value="img">IMG (cloud/raw)</option>
                </select>
            </td>
        </tr>
        <tr>
            <td class="celltag">URL</td>
            <td class="celldata"><input type="text" name="url" size="80" placeholder="https://..."/></td>
        </tr>
        <tr>
            <td class="celltag">&nbsp;</td>
            <td class="celldata">
                <button type="submit" class="formbtn" name="act" value="download"><?=gettext('Download');?></button>
                <span style="opacity:.75;margin-left:8px;"><?=gettext('Downloads run as a job in the background.');?></span>
            </td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Presets');?></td>
            <td class="celldata">
                <button type="submit" class="formbtn" name="act" value="preset:virtio"><?=gettext('virtio-win drivers ISO');?></button>
                <span style="opacity:.75;margin-left:8px;"><?=gettext('Official stable build - needed by Windows guests for disk/network drivers.');?></span>
            </td>
        </tr>
    </tbody>
</table>

<br />

<?php
$sections = [
    ['key' => 'iso', 'title' => 'ISO', 'dir' => $isoDir, 'files' => $isos],
    ['key' => 'img', 'title' => 'IMG', 'dir' => $imgDir, 'files' => $imgs],
];
foreach ($sections as $sec):
?>
<table class="area_data_selection" style="width:100%;border-collapse:collapse;">
    <colgroup>
        <col style="width:48%"><col style="width:12%"><col style="width:20%"><col style="width:20%">
    </colgroup>
    <thead>
        <?php html_separator2(); html_titleline2(sprintf('%s (%s)', $sec['title'], $sec['dir']), 4); ?>
        <tr>
            <th class="lhell"><?=gettext('File');?></th>
            <th class="lhell"><?=gettext('Size');?></th>
            <th class="lhell"><?=gettext('Modified');?></th>
            <th class="lhebl"><?=gettext('Action');?></th>
        </tr>
    </thead>
    <tbody>
    <?php if ($sec['files'] === []): ?>
        <tr><td class="lcell" colspan="4"><?=gettext('No images.');?></td></tr>
    <?php else: ?>
        <?php foreach ($sec['files'] as $f): ?>
            <tr>
                <td class="lcell vmm-mono"><?=vmm_h((string)$f['name']);?></td>
                <td class="lcell"><?=vmm_h(vmm_human_bytes((int)$f['size']));?></td>
                <td class="lcell vmm-mono"><?=date('Y-m-d H:i', (int)$f['mtime']);?></td>
                <td class="lcebl">
                    <button type="submit" class="formbtn" name="act"
                            value="delete:<?=$sec['key'];?>:<?=vmm_h((string)$f['name']);?>"
                            onclick="return vmmAskDelete(<?=htmlspecialchars(json_encode((string)$f['name']), ENT_QUOTES);?>)"><?=gettext('Delete');?></button>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
<br />
<?php endforeach; ?>

<p><a class="formbtn" href="vmmngr_create.php"><?=gettext('Create VM');?></a> <a class="formbtn" href="vmmngr.php"><?=gettext('Back');?></a></p>

<?php include 'formend.inc'; ?>
</td></tr></tbody></table></form>
<?php include 'fend.inc'; ?>
