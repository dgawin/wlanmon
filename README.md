<p align="center">
  <img src="branding/wlanmon-logo.svg" alt="wlanmon" width="420">
</p>

# wlanmon

wlanmon tells you how your Wi-Fi is doing at every site, before your users do.

Small Linux boards (Raspberry Pi, NanoPi) sit in your offices and behave like a
client: they scan the air, connect to your SSIDs and measure how long each step
takes – association, 802.1X, DHCP, reachability and, if you like, throughput
with iperf3. Everything lands in one web dashboard with history, a spectrum
view and alerts by e-mail or Telegram.

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
