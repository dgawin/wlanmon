<p align="center">
  <img src="branding/wlanmon-logo.svg" alt="wlanmon" width="420">
</p>

# wlanmon

wlanmon tells you how your Wi-Fi is doing at every site, before your users do.

Small Linux boards (Raspberry Pi, NanoPi) sit in your offices and behave like a
client: they scan the air, connect to your SSIDs and measure how long each step
takes – association, 802.1X, DHCP, reachability and, if you like, throughput
with iperf3. Everything lands in one web dashboard with history, a spectrum
view and alerts by e-mail or Telegram. If you already monitor with Zabbix,
it can discover all probes as hosts and alert on their results. SSIDs and test
settings are kept in profiles, so a changed password is edited once rather than
on every probe.

If your access points are managed by Alcatel-Lucent Enterprise OmniVista
Cirrus, the dashboard can optionally connect to its API (read-only). It then
shows the AP name for every BSSID the probes see, the radio state of your APs
(channel, utilisation, noise, transmit power) and their channel load next to
the probes' own measurements – so you can tell whether a slow or failed test
was down to the RF situation.

Website: [wlanmon.com](https://wlanmon.com)

| Folder | What's inside |
|---|---|
| [`probe/`](probe/) | The software for the measuring devices (Python), with setup script and automatic updates – [probe/README.md](probe/README.md) |
| [`dashboard/`](dashboard/) | Server and web dashboard (PHP + MySQL/MariaDB), installed classically or as a Docker stack – [dashboard/README.md](dashboard/README.md) |
| [`branding/`](branding/) | Logo and icon (SVG) |

## Getting started

1. Set up the dashboard. Docker is the quickest way:
   [dashboard/README.md, "Docker"](dashboard/README.md#docker).
2. Add a device in the dashboard and note its device ID and API key – the key
   is shown only once.
3. Install the probe. All you need beforehand is `git`; the setup script takes
   care of the rest and then walks you through the configuration:

   ```bash
   sudo apt update && sudo apt install -y git
   git clone https://github.com/dgawin/wlanmon.git ~/wlanmon && cd ~/wlanmon/probe
   sudo ./setup_wlanmon_probe.sh
   ```

   Hardware, Wi-Fi adapters and all settings are explained in
   [probe/README.md](probe/README.md).

## Tested hardware

Raspberry Pi 5 and 3 B, NanoPi NEO2/NEO3, with USB adapters or built-in Wi-Fi –
details in the [table in the probe README](probe/README.md#tested-hardware).

## Branches

- `main` – work in progress
- `stable` – released versions; probes and dashboard update from here by
  default

## License

MIT – see [LICENSE](LICENSE). The name and logo "wlanmon" are not covered by
the license.

## Trademarks

wlanmon is an independent project and is not affiliated with, endorsed by or
sponsored by any of the companies named here. Alcatel-Lucent is a trademark of
Nokia used under license by ALE; OmniVista is a trademark of ALE. Raspberry Pi
is a trademark of Raspberry Pi Ltd. All other product and company names are
trademarks of their respective owners and are used only to describe
compatibility.
