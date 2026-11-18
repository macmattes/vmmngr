# Changelog

All notable changes to the VM Manager extension.

## 0.12.0
- Job retention: finished jobs are pruned FIFO (default 100, configurable,
  0 = keep none), "Clear finished jobs" button, `vmm-ctl prune [n]`.

## 0.11.0
- VM detail: configuration editor for vCPU, memory, loader, VNC, network switch and
  autostart (stopped guests only); writes `<vm>.conf` with a `.vmmngr.bak` backup and
  keeps `vm_list` in sync.

## 0.10.2
- Create wizard: driver ISO (virtio-win) as second CD device, separate download row,
  hint when no virtio image is present.

## 0.10.1
- Create wizard: pick an ISO from `.iso`, download one inline by URL, optional
  "start right after create" (ISO is attached as CD before boot).

## 0.10.0
- Guided setup wizard (`Setup`): prerequisite check, default datastore
  (`vm_dir`/`vm_enable` written to config.xml + `vm init`), first switch.
- Dashboard shows a hint while the setup is incomplete.

## 0.9.0
- Switch editor: list/add/remove `manual` switches on existing host bridges,
  shows interface and IP, refuses removal while VMs use the switch.

## 0.8.1
- Create wizard: switch dropdown shows type, interface and IP; the chosen
  switch is written into the new VM config (previously ignored).

## 0.8.0
- Datastore management: add/remove, usage column, default protected.

## 0.7.2
- Boot integration reads/writes loader.conf entries under `<system><loaderconf>`
  (they were reported as missing).

## 0.7.1
- Dashboard tab stays clickable on the VM detail page.

## 0.7.0
- Setting `after_action`: stay on the page with a job status line (default) or
  jump to the job log; settings saved from the Configuration page.

## 0.6.1
- Serial web console uses a random base path token instead of basic auth, so the
  embedded frame no longer triggers a browser login dialog.

## 0.6.0
- noVNC support: websockify serves the VM's VNC screen in the browser, with an
  embedded frame; stopped together with the guest.

## 0.5.0
- Serial web console per VM via ttyd on `/dev/nmdm-<vm>.1B`, auto-stopped with
  the guest.

## 0.4.3
- VNC connect target derived from the VM's bridge IP instead of the host name.

## 0.4.1
- Detail page POSTs keep the VM name (actions no longer bounced to the dashboard).

## 0.4.0
- ISO install: attach boot ISO and optional virtio-win driver ISO as CD devices,
  eject again; virtio-win download preset.

## 0.3.3
- Jobs may carry their own argv (create), zero timeouts fixed, graceful stop
  waits for ACPI, template name without `.conf`.

## 0.3.0
- Clickable breadcrumb plus tab navigation.
- Images page (download/delete), templates page (CRUD + editor), create wizard.

## 0.2.3
- Forms include `formend.inc` (XigmaNAS requires `authtoken` on POST, otherwise
  401 + session loss).

## 0.2.1
- Jobs spawn the CLI PHP binary; API errors are logged; ZFS holds are released
  before destroy.

## 0.2.0
- Job engine with per-VM locks, logs and recovery.
- VM actions: start, stop, restart, reset, power-off, destroy.
- Theme-aware styling (no hardcoded dark panels).

## 0.1.0
- Read-only preview: dashboard, VM details, environment self-check.
