# Install through OneButtonInstaller (OBI)

OBI keeps its extension list in `extensions.txt` inside the OneButtonInstaller
directory. Each line is tab-free, fields separated by `###`:

```
Name###Status###Folder###InstallCommand###Postinit###Description###Architectures
```

## Option A - add the entry to the local list

1. Open `Extensions → OneButtonInstaller → Configuration` and disable
   *Update extensions list automatically* (otherwise the upstream list
   overwrites your edit on the next fetch).
2. Edit `/mnt/<pool>/extensions/onebuttoninstaller/extensions.txt` and append
   this line (replace `<pool>` only if your extension storage differs):

```
VM Manager###<font color="green">RELEASE<br>0.12.x</font>###vmmngr###mkdir -p -m 0750 vmmngr && cd vmmngr && fetch --no-verify-peer https://github.com/macmattes/vmmngr/releases/latest/download/vmmngr-latest.tar.gz && tar xzf vmmngr-latest.tar.gz --strip-components 1 && ./vmm-init -o###-###bhyve/vm-bhyve manager: VMs, images, templates, datastores, switches, serial and VNC console in the browser.###14.3, amd64
```

3. Reload `Extensions → OneButtonInstaller` and install **VM Manager**.
4. Open `Extensions → VM Manager → Configuration` and press
   **Register in config.xml** so the postinit hook and the module entries are
   registered. OBI does not do this for this extension.

## Option B - install without OBI

```sh
mkdir -p -m 0750 /mnt/<pool>/extensions/vmmngr && cd /mnt/<pool>/extensions/vmmngr
fetch https://github.com/macmattes/vmmngr/releases/latest/download/vmmngr-latest.tar.gz
tar xzf vmmngr-latest.tar.gz --strip-components 1
chmod +x vmm-init && ./vmm-init -o
```

Then `Extensions → VM Manager → Setup`.

## Notes

- The `Postinit` column is intentionally `-`: this extension registers its own
  postinit hook through `Configuration → Register in config.xml` (writes
  `config.xml`, which is the only place that survives a reboot).
- OBI downloads the upstream list from `crestAT/nas4free-onebuttoninstaller`.
  A local entry therefore only survives while *Update extensions list* is off -
  or until the entry is merged upstream.
- The installer needs network access to `github.com` and, for the console
  features, optionally `pkg install novnc py312-websockify`.
