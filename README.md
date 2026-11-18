# VM Manager for XigmaNAS (vmmngr)

A OneButton-style extension that puts **bhyve / vm-bhyve** under the XigmaNAS web GUI:
list machines, start/stop them, create new ones, manage ISOs, templates, datastores and
switches, and open a console or the graphical screen in the browser.

Status: **0.12.1** – feature-complete for daily use on a single node.

---

## Features

| Area | What you get |
|---|---|
| Dashboard | all guests with state, loader, vCPU, RAM, datastore, autostart; start/stop/restart inline |
| VM actions | start, stop, restart, reset, power-off, destroy (typed name required, ZFS holds released first) |
| VM settings | edit vCPU, memory, loader, graphics/VNC, network switch and autostart of a stopped VM |
| Create wizard | template, datastore, vCPU/RAM/disk, switch, optional boot ISO, optional virtio-win driver ISO, optional start, optional cloud-init image |
| Images | ISO/IMG inventory, HTTP(S) downloads as jobs, delete with typed confirmation, virtio-win preset |
| Templates | list, edit, duplicate, delete, create from skeleton |
| Datastores | add/remove vm-bhyve datastores, usage column, default cannot be removed |
| Switches | add/remove `manual` switches on existing host bridges, shows interface and IP |
| Console | serial web terminal (ttyd, token URL) and graphical screen via noVNC/websockify |
| Setup wizard | guided first run: prerequisites, default datastore, first switch |
| Jobs | every mutating action runs as a locked job with log; survives browser reloads; FIFO retention (default 100, clearable) |
| Config | provider selection (`auto/system/bundle`), after-action behaviour, boot integration check |

## Requirements

- XigmaNAS **14.x** (amd64), PHP 8.1+, web GUI reachable
- `vm-bhyve` **1.6+** — either installed in the system or supplied in `vendor/vm-bhyve`
- `vmm.ko` and `nmdm.ko`
- optional: `pkg install novnc py312-websockify` for the graphical browser console
- optional: `ttyd` for the serial web terminal (bundled or from pkg)
- network: VNC `5900+`, noVNC `6080-6099`, serial terminal `7681-7699` (bound to the
  VM bridge IP)

The addon never installs or upgrades vm-bhyve itself. `vm_source = auto` prefers the
system installation and only fills gaps from the bundled tree.

## Install

### From the release page (recommended)

Every release ships a ready package:

- `vmmngr-<version>.tar.gz` - the extension
- `vmmngr-latest.tar.gz` - same file with a stable name (used by the OBI entry)
- `*.sha256` - checksums

```sh
mkdir -p -m 0750 /mnt/tank/extensions/vmmngr && cd /mnt/tank/extensions/vmmngr
fetch https://github.com/macmattes/vmmngr/releases/latest/download/vmmngr-latest.tar.gz
tar xzf vmmngr-latest.tar.gz --strip-components 1
chmod +x vmm-init && ./vmm-init -o
```

### Through OneButtonInstaller

See [`docs/onebutton.md`](docs/onebutton.md) - includes the ready-made
`extensions.txt` line.

### From source

```sh
mkdir -p -m 0750 /mnt/tank/extensions/vmmngr && cd /mnt/tank/extensions/vmmngr
fetch https://raw.githubusercontent.com/macmattes/vmmngr/main/vmm-init
chmod +x vmm-init && ./vmm-init -o
```

Then open **Extensions → VM Manager → Setup** and follow the wizard.

### From the registry (packaged build)

The release workflow publishes the tarball as an OCI artifact:

```sh
oras pull ghcr.io/macmattes/vmmngr:latest
tar -xzf vmmngr-*.tar.gz -C /mnt/tank/extensions/
cd /mnt/tank/extensions/vmmngr && ./vmm-init -o
```

## First run

`Setup` walks through three steps:

1. **Check** – vm-bhyve, kernel modules, host bridges
2. **Datastore** – pick a mounted dataset below `/mnt` or create one; writes `vm_dir`
   and `vm_enable` to `config.xml`, applies them to the running system and runs `vm init`
3. **Switch** – create the first `manual` switch on an existing host bridge
4. **Register** – `Configuration → Register in config.xml` writes the postinit hook
   and the `vmm_load`/`nmdm_load` entries into `config.xml` (the only place that
   survives a reboot)

## How it works

- **Never overlays.** Existing system binaries win; the bundle only fills gaps.
- **One write path.** The GUI never calls `vm` directly – everything goes through
  `bin/vmm-ctl`, mutations run as jobs in `bin/vmm-jobd`.
- **vm-bhyve files are the truth.** No second inventory of machines.
- **Config lives in `config.xml`.** `/etc/rc.conf` and `/boot/loader.conf.local` are
  generated from it at boot – direct edits do not survive.
- **Symlinks, no mounts.** Only missing targets are linked; `vmm-init -p` repairs them
  at every boot (postinit hook).

## XigmaNAS traps this addon handles

- **Every POST needs `authtoken`.** `auth.inc` answers `401` *and destroys the session*
  without it. All forms include `formend.inc`, which emits the field.
- **`vm` returns rc=0 on refusal.** Jobs therefore verify the resulting state
  (`start→running`, `stop→stopped`, `destroy→absent`) instead of trusting the exit code.
- **`vm list -r` lists running guests only.** Use `vm list`.
- **Transient states.** A booting guest reports `bootloader`/`locked` for a while;
  jobs wait instead of failing.
- **Destroy needs free snapshots.** ZFS holds (znapzend/zrepl) are released first and
  logged.
- **VNC bind `0.0.0.0` is not an address.** The connect target is derived from the
  VM's bridge IP (`bridge0` → `192.0.2.10`).

## Build & release

```sh
./tools/build-package.sh      # -> dist/vmmngr-<version>.tar.gz (+ .sha256)
```

The workflow `.github/workflows/release.yml` runs on tags `v*` or manually:

1. builds the tarball
2. uploads it as a workflow artifact
3. pushes it as OCI artifact to `ghcr.io/<owner>/<repo>:<version>` and `:latest`
   using `oras`

On GitHub no configuration is needed - the push authenticates with the built-in
`GITHUB_TOKEN` and `packages: write`. Optional overrides:

| Secret | Default | Purpose |
|---|---|---|
| `REGISTRY` | `ghcr.io` | registry host |
| `REGISTRY_REPO` | `$GITHUB_REPOSITORY` | image path inside the registry |
| `REGISTRY_USER` | `github.actor` | only needed for a registry other than GHCR |
| `REGISTRY_TOKEN` | `GITHUB_TOKEN` | only needed for a registry other than GHCR |

Release a new version:

```sh
git tag -a v0.12.1 -m "vmmngr 0.12.1"
git push origin v0.12.1
```

The GitHub release itself is created from the workflow artifact: upload
`dist/vmmngr-<version>.tar.gz`, `dist/vmmngr-latest.tar.gz` and the `.sha256`
file as release assets so the OBI entry can point at the stable name.

## Layout

```
vmm-init                 installer/updater/self-heal/uninstaller (-o -u -p -r -s)
bin/vmm-ctl              CLI, single write path (PHP)
bin/vmm-jobd             job runner
bin/vmm-lib.php          shared library (providers, parsers, jobs, console)
gui/                     PHP pages, symlinked into /usr/local/www
gui/ext/vmmngr/menu.inc  Extensions menu entry
vendor/                  bundled binaries for systems without vm-bhyve
tools/build-package.sh   packaging
docs/                    OneButtonInstaller instructions
.github/workflows/       release workflow (package + GHCR artifact)
templates/               skeleton templates shipped with the extension
state/ log/ backup/      runtime data (never shipped, never overwritten by updates)
```

## Licences and attribution

Our code is **BSD-2-Clause** (`LICENSE`). The extension only *drives* third-party
software; it does not ship it:

| Component | Licence | bundled? |
|---|---|---|
| vm-bhyve | BSD-2-Clause | only if a `vendor/` build is used |
| bhyve, nmdm | BSD-2-Clause (FreeBSD base) | no |
| noVNC | MPL-2.0 | no (`pkg install novnc`) |
| websockify | LGPL-3.0 | no (`pkg install py312-websockify`) |
| ttyd | MIT | only in a `vendor/` build |
| edk2-bhyve | BSD-3-Clause | only in a `vendor/` build |
| grub2-bhyve | **GPL-3.0** | only in a `vendor/` build — see `THIRD-PARTY.md` |

The release package built by the workflow contains **no** third-party binaries,
so there is nothing to redistribute. `tools/build-package.sh --with-vendor`
exists for embedded systems and ships licence files alongside; using it means
you are redistributing those binaries and must honour their terms (notably
GPL-3.0 for grub2-bhyve: source or written offer).

Details and consequences: [`THIRD-PARTY.md`](THIRD-PARTY.md).

## Uninstall

```sh
cd /mnt/tank/extensions/vmmngr && ./vmm-init -r --yes
```

Removes symlinks and the directory; VMs, images and datastores are untouched.
