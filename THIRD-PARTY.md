# Third-party software

This extension is a management layer. It **does not ship** the third-party
programs it drives: `vm-bhyve`, `bhyve`, noVNC, websockify and ttyd are used at
runtime from the operating system (FreeBSD packages, base system) or from the
`vendor/` tree of the *installed* extension on systems that have no package for
them.

The repository and the release package contain only our own code, so the
licences below apply to "use", not to "redistribution". If you redistribute a
build that includes `vendor/` binaries (`tools/build-package.sh --with-vendor`),
the obligations of the respective licence apply to you.

| Component | Used as | Licence | Notes |
|---|---|---|---|
| **vm-bhyve** | CLI (`/usr/local/sbin/vm`, libs) | BSD-2-Clause | optional bundled copy in `vendor/vm-bhyve` |
| **bhyve / bhyvectl / nmdm** | FreeBSD base system | BSD-2-Clause | never bundled |
| **noVNC** | static web client (`pkg install novnc`) | MPL-2.0 | not bundled; file-level copyleft if modified |
| **websockify** | WebSocket ↔ VNC bridge (`pkg install py312-websockify`) | LGPL-3.0 | not bundled; dynamic use, no linking of our code |
| **ttyd** | serial console web terminal | MIT | optional bundled copy in `vendor/ttyd` |
| **edk2-bhyve** | UEFI firmware for guests (`pkg install edk2-bhyve`) | BSD-3-Clause | optional bundled copy in `vendor/edk2-bhyve` |
| **grub2-bhyve** | BIOS/GRUB loader for guests | **GPL-3.0** | bundled only if `vendor/` is shipped — then you must provide the corresponding source and licence texts |
| **XigmaNAS** | host platform (this extension runs inside it) | BSD-2-Clause | separate project |

## Consequences for publishing

- Plain release (no `vendor/`): nothing to do beyond this notice. The user
  installs the packages themselves; their licences are accepted through `pkg`.
- Release with bundled binaries: ship the licence text of every bundled
  component next to the binary (`vendor/<component>/LICENSE`), and for
  `grub2-bhyve` (GPL-3.0) also provide the source or a written offer.
- Do not link our code against LGPL/MPL/GPL components; we call them as
  separate programs, which keeps our BSD-2-Clause licensing valid.
- Names and trademarks (XigmaNAS, FreeBSD, noVNC, GRUB) are used descriptively;
  there is no endorsement by those projects.

## Our own licence

`LICENSE` — BSD-2-Clause. Keep the copyright line in derived versions.
