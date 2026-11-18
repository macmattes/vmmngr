<?php
/**
 * vmmngr-config-lib.inc - boot integration through the XigmaNAS configuration.
 *
 * /boot/loader.conf.local and /etc/rc.conf are generated from /conf/config.xml
 * at boot, so writing those files directly does not survive a reboot. This is
 * the supported path: put the entries into $config and let write_config()
 * persist them.
 */

if (!function_exists('vmm_register_boot')) {
    /**
     * Ensure vmm_load/nmdm_load and the postinit hook exist in config.xml.
     *
     * @return array list of added entries ('' when everything was present)
     */
    function vmm_loaderconf_path(): string
    {
        // XigmaNAS nests loader.conf entries under <system><loaderconf>
        global $config;
        if (isset($config['system']) && is_array($config['system'])) {
            return 'system';
        }
        return '';
    }

    function vmm_register_boot(): array
    {
        global $config;

        $added = [];

        // 1) loader.conf entries live in <system><loaderconf><param>
        $scope = vmm_loaderconf_path() === 'system' ? 'system' : '';
        if ($scope === 'system') {
            if (!isset($config['system']['loaderconf']) || !is_array($config['system']['loaderconf'])) {
                $config['system']['loaderconf'] = [];
            }
            $lc = &$config['system']['loaderconf'];
        } else {
            if (!isset($config['loaderconf']) || !is_array($config['loaderconf'])) {
                $config['loaderconf'] = [];
            }
            $lc = &$config['loaderconf'];
        }
        if (!isset($lc['param']) || !is_array($lc['param'])) {
            $lc['param'] = [];
        }
        $have = [];
        foreach ($lc['param'] as $p) {
            if (isset($p['name'])) {
                $have[(string)$p['name']] = true;
            }
        }
        foreach (['vmm_load' => 'YES', 'nmdm_load' => 'YES'] as $key => $value) {
            if (isset($have[$key])) {
                continue;
            }
            $lc['param'][] = [
                'uuid' => uuid(),
                'enable' => '',
                'name' => $key,
                'value' => $value,
                'comment' => '',
            ];
            $added[] = $key;
        }
        unset($lc);

        // 2) postinit hook (<rc> is a top-level section)
        $cmd = VMM_ROOT . '/vmm-init -p';
        $found = false;
        if (isset($config['rc']['param']) && is_array($config['rc']['param'])) {
            foreach ($config['rc']['param'] as $p) {
                if (trim((string)($p['value'] ?? '')) === $cmd) {
                    $found = true;
                    break;
                }
            }
        } else {
            if (!isset($config['rc']) || !is_array($config['rc'])) {
                $config['rc'] = [];
            }
            $config['rc']['param'] = [];
        }
        if (!$found) {
            $config['rc']['param'][] = [
                'uuid' => uuid(),
                'name' => 'VM Manager Extension',
                'value' => $cmd,
                'comment' => 'Start VM Manager',
                'typeid' => '2',
                'enable' => true,
            ];
            $added[] = 'rc postinit';
        }

        if ($added !== []) {
            write_config();
        }
        return $added;
    }

    /**
     * Set a plain rc.conf variable through config.xml (survives reboots) and
     * apply it to the running system via sysrc.
     */
    function vmm_set_rc_param(string $name, string $value): bool
    {
        global $config;
        if (!isset($config['rc']['param']) || !is_array($config['rc']['param'])) {
            $config['rc'] = $config['rc'] ?? [];
            $config['rc']['param'] = [];
        }
        $found = false;
        foreach ($config['rc']['param'] as $i => $p) {
            if ((string)($p['name'] ?? '') === $name) {
                $config['rc']['param'][$i]['value'] = $value;
                $config['rc']['param'][$i]['enable'] = true;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $config['rc']['param'][] = [
                'uuid' => uuid(),
                'enable' => true,
                'name' => $name,
                'value' => $value,
                'comment' => '',
            ];
        }
        write_config();
        // apply now, the file is only regenerated at boot
        @exec('/usr/sbin/sysrc ' . escapeshellarg($name . '=' . $value) . ' >/dev/null 2>&1');
        return true;
    }

    function vmm_rc_param(string $name): string
    {
        global $config;
        foreach (($config['rc']['param'] ?? []) as $p) {
            if ((string)($p['name'] ?? '') === $name) {
                return (string)($p['value'] ?? '');
            }
        }
        return '';
    }

    function vmm_autostart_names(): array
    {
        $list = trim(vmm_rc_param('vm_list'));
        if ($list === '') {
            return [];
        }
        return preg_split('/\s+/', $list) ?: [];
    }

    function vmm_autostart_set(string $name, bool $on): bool
    {
        $names = vmm_autostart_names();
        $names = array_values(array_filter($names, static fn($n) => $n !== $name));
        if ($on) {
            $names[] = $name;
        }
        vmm_set_rc_param('vm_list', implode(' ', $names));
        return true;
    }

    function vmm_boot_state(): array
    {
        global $config;
        $params = $config['system']['loaderconf']['param'] ?? ($config['loaderconf']['param'] ?? []);
        $loader = [];
        foreach (($params ?: []) as $p) {
            if (isset($p['name'])) {
                $loader[(string)$p['name']] = (string)($p['value'] ?? '');
            }
        }
        $rcCmd = VMM_ROOT . '/vmm-init -p';
        $rcOk = false;
        foreach (($config['rc']['param'] ?? []) as $p) {
            if (trim((string)($p['value'] ?? '')) === $rcCmd) {
                $rcOk = true;
                break;
            }
        }
        return [
            'vmm_load' => $loader['vmm_load'] ?? '',
            'nmdm_load' => $loader['nmdm_load'] ?? '',
            'rc_postinit' => $rcOk,
        ];
    }
}
