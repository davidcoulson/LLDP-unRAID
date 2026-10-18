# LLDP for Unraid

LLDP lets your server and your switches tell each other who they are. This plugin runs
[lldpd](https://github.com/lldpd/lldpd) on Unraid, so you can see which switch and port each
network interface is plugged into, and your switch can see your server.

I forked this from [desertwitch's plugin](https://github.com/desertwitch/LLDP-unRAID) when it went
unmaintained, and I'm keeping it going. All the original credit stays with desertwitch and the
authors named in the source files.

## Install

In Unraid go to **Plugins > Install Plugin** and paste:

```
https://raw.githubusercontent.com/davidcoulson/LLDP-unRAID/main/plugin/ulldpd.plg
```

If you're coming from the original plugin, your settings carry over. It also shows up in Community
Applications as "LLDP for UNRAID". Needs Unraid 6.12 or newer.

Then open **Settings > Network Services > LLDP**, set *Start LLDP Service* to Yes and hit Apply.

## What you get

- **Who's on the other end.** A live table of your neighbours (switch name, port, VLANs and so on),
  on the settings page and as a Dashboard tile.
- **Control per port.** Turn LLDP on, off, listen-only or send-only for each NIC, and set what port
  ID and description your switch sees.
- **Useful warnings.** If your switch's jumbo frame size or link speed doesn't match what the server
  has, the table points it out. Bond and LACP state is shown too.
- **Know when a cable moves.** Optional Unraid notifications when a neighbour appears, disappears
  or changes, and a short history of recent changes.
- **Choose what you advertise.** Pick the interfaces, hide management addresses you don't want
  shown (a Tailscale address, say), override the system name and description, and change the
  transmit timing.
- **An escape hatch.** A box for custom lldpd config if you need something the UI doesn't cover.
- **Older gear.** CDP, FDP, SONMP and EDP are still there for Cisco, Foundry, Nortel and Extreme
  switches that don't speak LLDP.

Under the hood it's lldpd 1.0.22 running with privilege separation rather than as root, and the
settings page treats everything a neighbour sends as untrusted.

## More detail

Every setting, how the change notifications behave, what lldpd can't do, how it's built and the
security notes are in [docs/DETAILS.md](docs/DETAILS.md). The full version history is in the
changelog inside [plugin/ulldpd.plg](plugin/ulldpd.plg).

## Help and feedback

Bugs and ideas go in [GitHub issues](https://github.com/davidcoulson/LLDP-unRAID/issues). It's
tested on Unraid 7.4 with Dell SONiC switches, so reports from other setups are especially useful.
See [CONTRIBUTING](CONTRIBUTING) if you want to send a pull request.

## Licence

Same as the original: ISC, GPL2 and/or GPL3 where applicable. lldpd's source is at
https://github.com/lldpd/lldpd.
