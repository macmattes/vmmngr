<?php
/**
 * vmmngr-boot.inc - bootstrap for all VM Manager pages.
 *
 * Works both in the extension tree and through the symlinks in /usr/local/www:
 * realpath() resolves the link, so the shared library is found either way.
 */

require_once 'auth.inc';
require_once 'guiconfig.inc';

$vmm_self = realpath(__FILE__);
$vmm_root = dirname(dirname($vmm_self));
require_once $vmm_root . '/bin/vmm-lib.php';

if (!function_exists('vmm_badge')) {
    function vmm_badge(string $state): string
    {
        $map = [
            'running'   => ['vmm-running', gettext('Running')],
            'stopped'   => ['vmm-stopped', gettext('Stopped')],
            'suspended' => ['vmm-suspended', gettext('Suspended')],
            'starting'  => ['vmm-starting', gettext('Starting')],
            'locked'    => ['vmm-locked', gettext('Locked')],
        ];
        [$cls, $label] = $map[$state] ?? ['vmm-unknown', ucfirst($state)];
        return sprintf('<span class="vmm-badge %s">%s</span>', $cls, htmlspecialchars($label));
    }

    function vmm_h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    function vmm_source_label(array $p): string
    {
        $icon = match ($p['source']) {
            'system' => '🖥',
            'bundle' => '📦',
            default => '⚠',
        };
        return sprintf('%s %s', $icon, $p['source']);
    }
}

if (!function_exists('vmm_nav')) {
    /**
     * Clickable breadcrumb + section tabs (the XigmaNAS title itself is plain
     * text, so navigation lives here).
     */
    function vmm_nav(string $current, string $crumb = ''): void
    {
        $items = [
            'dashboard' => ['vmmngr.php', gettext('Dashboard')],
            'create' => ['vmmngr_create.php', gettext('Create VM')],
            'images' => ['vmmngr_images.php', gettext('Images')],
            'templates' => ['vmmngr_templates.php', gettext('Templates')],
            'datastores' => ['vmmngr_datastores.php', gettext('Datastores')],
            'switches' => ['vmmngr_switches.php', gettext('Switches')],
            'jobs' => ['vmmngr_jobs.php', gettext('Jobs')],
            'config' => ['vmmngr_config.php', gettext('Configuration')],
            'setup' => ['vmmngr_setup.php', gettext('Setup')],
        ];
        $label = $crumb !== '' ? $crumb : ($items[$current][1] ?? gettext('VM Manager'));
        ?>
<div class="vmm-crumbs"><a href="vmmngr.php"><?=gettext('VM Manager');?></a><span class="vmm-sep">›</span><strong><?=vmm_h($label);?></strong></div>
<div class="vmm-tabs">
        <?php foreach ($items as $key => [$href, $text]): ?>
            <?php if ($key === $current && $current !== ''): ?>
                <span class="vmm-tab vmm-tab-cur"><?=vmm_h($text);?></span>
            <?php else: ?>
                <a class="vmm-tab" href="<?=vmm_h($href);?>"><?=vmm_h($text);?></a>
            <?php endif; ?>
        <?php endforeach; ?>
</div>
        <?php
    }
}

if (!function_exists('vmm_css')) {
    /**
     * Theme-aware styles: no hardcoded dark panels — colours derive from the
     * XigmaNAS theme variables, so light and dark themes both stay readable.
     */
    function vmm_css(): void
    {
        ?>
<style>
.vmm-badge{display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;border:1px solid currentColor;background:transparent;white-space:nowrap}
.vmm-running{color:#2f9e63}
.vmm-stopped{color:#c25656}
.vmm-suspended,.vmm-starting{color:#b98a1f}
.vmm-unknown,.vmm-locked{color:#7d7dab}
.vmm-grid{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0 14px}
.vmm-card{flex:1 1 160px;min-width:160px;padding:10px 12px;border:1px solid rgba(127,127,127,.35);border-radius:6px;background:var(--bgc-area-data,transparent);color:inherit}
.vmm-card .k{font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.7}
.vmm-card .v{font-size:20px;font-weight:600;margin-top:2px}
.vmm-card .s{font-size:11px;opacity:.75;margin-top:2px}
.vmm-mono{font-family:monospace;font-size:12px}
.vmm-raw{background:var(--bgc-input-ro,rgba(127,127,127,.08));color:inherit;border:1px solid rgba(127,127,127,.3);border-radius:4px;padding:10px;overflow:auto;max-height:340px;white-space:pre-wrap}
.vmm-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.vmm-job{display:none;margin:12px 0;border:1px solid rgba(127,127,127,.35);border-radius:6px;padding:10px;background:var(--bgc-area-data,transparent)}
.vmm-job-head{font-size:12px;margin-bottom:6px;opacity:.9}
.vmm-job-state{font-weight:600;margin-left:6px}
.vmm-crumbs{font-size:13px;margin:4px 0 8px}
.vmm-crumbs a{text-decoration:none}
.vmm-sep{margin:0 6px;opacity:.6}
.vmm-tabs{display:flex;flex-wrap:wrap;gap:4px;margin:0 0 12px;border-bottom:1px solid rgba(127,127,127,.35);padding-bottom:6px}
.vmm-tab{display:inline-block;padding:4px 10px;border:1px solid rgba(127,127,127,.35);border-radius:4px;text-decoration:none;font-size:12px}
.vmm-tab:hover{background:rgba(127,127,127,.12)}
.vmm-tab-cur{font-weight:600;background:rgba(127,127,127,.16);border-color:rgba(127,127,127,.55)}
.vmm-ok{color:#2f9e63}
.vmm-err{color:#c25656}
</style>
        <?php
    }
}
