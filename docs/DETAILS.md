# LLDP for Unraid: details

The README has the short version. This is the reference.

## Where things are

| What | Where |
| --- | --- |
| Settings page | Settings > Network Services > LLDP |
| Dashboard tile | Dashboard, "LLDP Neighbors" |
| Plugin config | `/boot/config/plugins/ulldpd/ulldpd.cfg` |
| Custom lldpd config | `/boot/config/plugins/ulldpd/custom.conf` |
| Neighbour history | `/boot/config/plugins/ulldpd/history.json` |
| Service script | `/etc/rc.d/rc.lldpd` (`start`, `stop`, `restart`, `status`, `autostart`) |
| Daemon files | pid `/run/lldpd.pid`, socket `/run/lldpd.socket`, chroot `/run/lldpd` |

## Settings

Changes take effect when you press Apply; if the service is running it is restarted. Values that
fail validation are ignored (the default stays) and a warning is logged.

| Setting | Config key | Default | Notes |
| --- | --- | --- | --- |
| Start LLDP service | `SERVICE` | disable | Start at boot. |
| Force LLDP | `FORCE` | disable | Send LLDP even if a peer speaking another protocol is seen. |
| CDP / FDP / SONMP / EDP | `CDP` `FDP` `SONMP` `EDP` | disable | Cisco, Foundry, Nortel and Extreme protocols for gear without LLDP. |
| Receive only | `RXONLY` | disable | Listen without advertising. |
| Update interface descriptions | `IFALIAS` | disable | Write the neighbour's switch and port into each interface's alias. |
| Interface pattern | `IFACES` | `eth*` | Comma-separated lldpd patterns. `!name` excludes. A pattern that only excludes selects every other interface. |
| Management address pattern | `MGMT_PATTERN` | empty | Which addresses are advertised, for example `!fd7a:115c:a1e0:*` to hide a Tailscale address. Wildcards, hex digits, `.`, `:` and `!` only. CIDR is not supported by lldpd. |
| Chassis ID interface | `CHASSIS_IFACE` | empty | Interface whose MAC is used as the chassis ID. |
| System name / description | `SYSNAME` `SYSDESCR` | empty | Override what is advertised. Printable ASCII, no quotes or newlines. |
| Transmit interval / hold | `TX_INTERVAL` `TX_HOLD` | 30 s / 4 | 1-3600 s and 1-100. |
| Port ID subtype | `PORTID_SUBTYPE` | default | default, ifname, macaddress or local. `local` needs a value and may use `%i` for the interface name. |
| Port description | `PORTDESCR` | empty | Global text; `%i` is replaced by the interface name. |
| Bond slave source MAC | `BOND_SRC_MAC` | default | Which MAC LLDP frames from bond slaves use. |
| Notify on neighbour changes | `NOTIFY` `NOTIFY_LEVEL` | disable / normal | Unraid notifications; importance normal or warning. |

Per-port settings are stored in the same file as `PORT_STATUS_<if>`, `PORT_ID_<if>` and
`PORT_DESCR_<if>`, where a `.` in the interface name is written as `_x2e_` (so `bond0.100` becomes
`bond0_x2e_100`). Interface names must match `^[A-Za-z0-9_.-]{1,15}$`, and at most 64 ports are
read.

### Ports table

Lists the physical NICs and bonds that match the interface pattern, with link state, speed, MTU
and bond membership (master or slave, mode, LACP state). Each port has an LLDP status: rx-and-tx,
rx-only, tx-only or disabled. Ports can also override the port ID (local only) and port
description. LACP is shown as healthy only when the actor and partner churn states are both
`none`.

### Neighbour table

One row per neighbour, with an expandable details row: remote capabilities, PVID, VLANs, max frame
size, PMD autoneg and MAU type, link aggregation, PoE and LLDP-MED (device type, capabilities,
inventory). A mismatch between the remote max frame size and the local MTU, or between the remote
and local link speed, is highlighted. The table refreshes every 10 seconds.

### Custom lldpd config

An advanced box on the settings page. Only `configure ...`, `unconfigure ...` and `#` comment lines
are accepted, ASCII only, up to 16 KiB. It is saved to `custom.conf` and copied to
`/etc/lldpd.d/` when the service starts. It is applied last, so it can override the settings
above.

## Change notifications and history

A small watcher starts and stops with the service. It polls the neighbour list every few seconds
and records added, removed and changed neighbours. Neighbours are identified by interface, chassis
ID and port ID, so ports with several neighbours work.

- History is kept in RAM and written to `history.json` on the flash drive only when something
  changes (at most once a minute, and again on stop).
- A neighbour is only reported removed after it has been missing for a grace period. After a
  service restart the grace is the transmit interval times the hold, at least 90 seconds.
- Notifications are off by default. At most 5 are sent per 10 minutes, then a single summary
  reports how many were suppressed.
- Notification text is reduced to plain ASCII and passed to Unraid's notify script as arguments,
  never through a shell.
- The "Recent neighbor changes" table shows the last events and has a Clear button.

## What lldpd 1.0.22 can't do

- No per-port management address and no management VLAN setting. The management address pattern is
  global.
- No `ifalias` port ID subtype, and the per-port port ID override is local only (ifname and
  macaddress are global).
- Port ID and port description can't be unset at runtime. Resetting one needs a service restart.
- No CIDR in the management address pattern.

## Security notes

- The config on `/boot` is parsed as data with whitelisted keys and values in both `rc.lldpd` and
  PHP. It is never sourced as shell, so a bad value can't run code.
- Everything a neighbour sends is untrusted. `lldpcli -f json` output is decoded, normalised and
  HTML-escaped before it reaches a page, and text that could form an Unraid `_(...)_` translation
  marker is neutralised.
- lldpd and lldpcli are always called with separate arguments, not through a shell string.
- Endpoints that change anything are POST-only and CSRF-checked. They whitelist their parameters
  and limit sizes.
- lldpd runs with privilege separation: the packet parser runs as user `_lldpd`, chrooted to
  `/run/lldpd`.

## Building

- `build/build-lldpd.sh [outdir]` builds the lldpd Slackware package. With `DOCKER=1` it runs
  inside a Slackware 15.0 container, which is what produced the shipped package. lldpd is built
  with privilege separation, its own libevent, and without libxml2 or readline, so it needs
  nothing beyond libc.
- `build/build-plugin.sh <version>` packs `source/` into `archive/ulldpd-<version>-x86_64-1.txz`
  and writes the checksums.
- After building, update the version, package name, MD5 and SHA256 in `plugin/ulldpd.plg`.
  Unraid skips a package whose version looks older than the installed one, so versions must keep
  sorting upwards (the plugin uses dates like `2026.10.22`).
- Before committing, run `php -l`, `bash -n`, `shellcheck` and `xmllint` on what you changed.
