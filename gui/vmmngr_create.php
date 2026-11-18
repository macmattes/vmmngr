<?php
/*
 * vmmngr_create.php - VM creation wizard (0.3.0)
 */

require_once 'vmmngr-boot.inc';

$post = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST';
$err = '';

$templates = vmm_file_list(vmm_data_dir('.templates'), ['conf']);
$datastores = vmm_datastores();
$switches = vmm_switches();
$images = vmm_file_list(vmm_data_dir('.img'), ['img']);
$isos = vmm_file_list(vmm_data_dir('.iso'), ['iso']);

if ($post && ($_POST['act'] ?? '') === 'download_iso') {
    $url = trim((string)($_POST['iso_url'] ?? ''));
    if (!preg_match('#^https?://[^\s"\'<>]+$#i', $url)) {
        $err = gettext('Only http(s) URLs are allowed.');
    } else {
        $job = vmm_job_new('iso_download', basename((string)parse_url($url, PHP_URL_PATH)) ?: 'iso', ['iso', $url], 21600);
        header('Location: ' . vmm_after_job_target((string)$job['id'], 'vmmngr_create.php'));
        exit;
    }
}

if ($post) {
    $name = trim((string)($_POST['name'] ?? ''));
    $tpl = (string)($_POST['template'] ?? '');
    $ds = (string)($_POST['datastore'] ?? 'default');
    $cpu = (string)($_POST['cpu'] ?? '2');
    $mem = (string)($_POST['memory'] ?? '2G');
    $size = (string)($_POST['size'] ?? '20G');
    $cloud = isset($_POST['cloudinit']);
    $img = (string)($_POST['image'] ?? '');
    $switch = trim((string)($_POST['switch'] ?? ''));
    $iso = vmm_safe_basename((string)($_POST['iso'] ?? ''));
    $driverIso = vmm_safe_basename((string)($_POST['driver_iso'] ?? ''));
    $autostart = isset($_POST['autostart']);

    if (!vmm_valid_name($name)) {
        $err = gettext('Invalid name: letters, digits, dot, dash, underscore (max 32).');
    } elseif (vmm_vm_state($name) !== 'absent') {
        $err = gettext('A VM with that name already exists.');
    } elseif (!preg_match('/^[A-Za-z0-9._-]+\.conf$/', $tpl)) {
        $err = gettext('Choose a template.');
    } elseif (!preg_match('/^[0-9]{1,2}$/', $cpu) || (int)$cpu < 1 || (int)$cpu > 32) {
        $err = gettext('vCPU must be between 1 and 32.');
    } elseif (!preg_match('/^[0-9]{1,4}[MG]$/', strtoupper($mem))) {
        $err = gettext('Memory must look like 2G or 512M.');
    } elseif (!preg_match('/^[0-9]{1,4}[MG]$/', strtoupper($size))) {
        $err = gettext('Disk size must look like 20G or 512M.');
    } elseif ($cloud && ($img === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._()+-]*\.img$/', $img))) {
        $err = gettext('Cloud-Init needs an image (.img) from the images page.');
    } else {
        $budget = vmm_ram_budget();
        $need = vmm_size_to_bytes(strtoupper($mem));
        if ($need > 0 && vmm_ram_used() + $need > $budget['limit']) {
            $err = sprintf(
                gettext('RAM budget exceeded: %s in use + %s requested, limit %s.'),
                vmm_human_bytes(vmm_ram_used()),
                vmm_human_bytes($need),
                vmm_human_bytes($budget['limit'])
            );
        } else {
            // vm-bhyve expects the template name without the .conf suffix
            $tplName = preg_replace('/\.conf$/', '', $tpl);
            $argv = ['create', '-t', $tplName, '-d', $ds, '-s', strtoupper($size), '-m', strtoupper($mem), '-c', $cpu];
            if ($cloud) {
                $argv[] = '-C';
                $argv[] = '-i';
                $argv[] = $img;
            }
            $argv[] = $name;
            $meta = ['switch' => $switch];
            if ($iso !== '' && str_ends_with(strtolower($iso), '.iso')) {
                $meta['iso'] = vmm_data_dir('.iso') . '/' . $iso;
            }
            if ($driverIso !== '' && str_ends_with(strtolower($driverIso), '.iso')) {
                $meta['driver_iso'] = vmm_data_dir('.iso') . '/' . $driverIso;
            }
            if ($autostart) {
                $meta['autostart'] = '1';
            }
            $job = vmm_job_new('create', $name, $argv, 900, $meta);
            header('Location: vmmngr_jobs.php?id=' . urlencode((string)$job['id']));
            exit;
        }
    }
}

$pgtitle = [gettext('Extensions'), gettext('VM Manager'), gettext('Create VM')];
include 'fbegin.inc';
vmm_css();
vmm_nav('create');
?>
<form action="vmmngr_create.php" method="post" name="iform" id="iform"><table id="area_data"><tbody><tr><td id="area_data_frame">

<?php if ($err !== ''): ?>
    <?php print_info_box(vmm_h($err)); ?>
<?php endif; ?>

<table class="area_data_settings" style="width:100%;border-collapse:collapse;">
    <colgroup><col class="area_data_settings_col_tag"><col class="area_data_settings_col_data"></colgroup>
    <thead>
        <?php html_separator2(); html_titleline2(gettext('New virtual machine'), 2); ?>
    </thead>
    <tbody>
        <tr>
            <td class="celltag"><?=gettext('Name');?></td>
            <td class="celldata"><input type="text" name="name" size="30" value="<?=vmm_h((string)($_POST['name'] ?? ''));?>"/></td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Template');?></td>
            <td class="celldata">
                <select name="template">
                    <?php foreach ($templates as $t): ?>
                        <option value="<?=vmm_h((string)$t['name']);?>"><?=vmm_h((string)$t['name']);?></option>
                    <?php endforeach; ?>
                </select>
                <a href="vmmngr_templates.php" style="margin-left:8px;"><?=gettext('manage templates');?></a>
            </td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Datastore');?></td>
            <td class="celldata">
                <select name="datastore">
                    <?php foreach ($datastores as $d): ?>
                        <option value="<?=vmm_h((string)($d['name'] ?? 'default'));?>"><?=vmm_h((string)($d['name'] ?? 'default'));?> (<?=vmm_h((string)($d['path'] ?? ''));?>)</option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('vCPU');?></td>
            <td class="celldata"><input type="text" name="cpu" size="6" value="<?=vmm_h((string)($_POST['cpu'] ?? '2'));?>"/></td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Memory');?></td>
            <td class="celldata"><input type="text" name="memory" size="8" value="<?=vmm_h((string)($_POST['memory'] ?? '2G'));?>"/> <span style="opacity:.7"><?=gettext('e.g. 2G');?></span></td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Disk size');?></td>
            <td class="celldata"><input type="text" name="size" size="8" value="<?=vmm_h((string)($_POST['size'] ?? '20G'));?>"/> <span style="opacity:.7"><?=gettext('e.g. 20G');?></span></td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Network switch');?></td>
            <td class="celldata">
                <select name="switch">
                    <?php foreach ($switches as $s):
                        $swName = (string)($s['name'] ?? '');
                        $swType = (string)($s['type'] ?? '');
                        $swIface = (string)($s['iface'] ?? '');
                        $swIp = vmm_iface_ipv4($swIface);
                        $label = $swName . ' — ' . $swType;
                        if ($swIface !== '' && $swIface !== 'n/a') {
                            $label .= ' → ' . $swIface;
                        }
                        if ($swIp !== '') {
                            $label .= ' (' . $swIp . ')';
                        }
                    ?>
                        <option value="<?=vmm_h($swName);?>"><?=vmm_h($label);?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Cloud-Init');?></td>
            <td class="celldata">
                <label><input type="checkbox" name="cloudinit" value="1"/> <?=gettext('provision with cloud-init seed');?></label>
                <select name="image" style="margin-left:8px;">
                    <option value=""><?=gettext('choose image');?></option>
                    <?php foreach ($images as $i): ?>
                        <option value="<?=vmm_h((string)$i['name']);?>"><?=vmm_h((string)$i['name']);?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Boot ISO');?></td>
            <td class="celldata">
                <select name="iso">
                    <option value=""><?=gettext('none (install later)');?></option>
                    <?php foreach ($isos as $i): ?>
                        <option value="<?=vmm_h((string)$i['name']);?>"><?=vmm_h((string)$i['name']);?></option>
                    <?php endforeach; ?>
                </select>
                <label style="margin-left:10px;"><input type="checkbox" name="autostart" value="1"/> <?=gettext('start right after create');?></label>
            </td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Driver ISO');?></td>
            <td class="celldata">
                <select name="driver_iso">
                    <option value=""><?=gettext('none');?></option>
                    <?php
                    $virtioCount = 0;
                    foreach ($isos as $i) {
                        if (stripos((string)$i['name'], 'virtio') !== false) {
                            $virtioCount++;
                        }
                    }
                    ?>
                    <?php if ($virtioCount === 0): ?>
                        <option value="" disabled><?=gettext('no virtio ISO in .iso - download it on the Images page');?></option>
                    <?php endif; ?>
                    <?php foreach ($isos as $i): ?>
                        <?php if (stripos((string)$i['name'], 'virtio') === false) { continue; } ?>
                        <option value="<?=vmm_h((string)$i['name']);?>"><?=vmm_h((string)$i['name']);?></option>
                    <?php endforeach; ?>
                </select>
                <span style="opacity:.75;margin-left:8px;"><?=gettext('Windows: virtio-win ISO as second CD (drivers for disk/network).');?></span>
            </td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Download ISO');?></td>
            <td class="celldata">
                <input type="text" name="iso_url" size="52" placeholder="https://.../debian-13-netinst.iso"/>
                <button type="submit" class="formbtn" name="act" value="download_iso"><?=gettext('Download');?></button>
                <a href="vmmngr_images.php" style="margin-left:8px;"><?=gettext('Images page');?></a>
                <div style="opacity:.75;margin-top:4px;"><?=gettext('Downloads run as a job; reload the page afterwards to see the new file in the lists.');?></div>
            </td>
        </tr>
        <tr>
            <td class="celltag"><?=gettext('Summary');?></td>
            <td class="celldata vmm-mono">
                vm create -t &lt;template&gt; -d &lt;datastore&gt; -s &lt;size&gt; -m &lt;memory&gt; -c &lt;cpu&gt; &lt;name&gt;
            </td>
        </tr>
        <tr>
            <td class="celltag">&nbsp;</td>
            <td class="celldata">
                <button type="submit" class="formbtn" name="act" value="create"><?=gettext('Create');?></button>
                <a class="formbtn" href="vmmngr.php"><?=gettext('Cancel');?></a>
            </td>
        </tr>
    </tbody>
</table>

<?php include 'formend.inc'; ?>
</td></tr></tbody></table></form>
<?php include 'fend.inc'; ?>
