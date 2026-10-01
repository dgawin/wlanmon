<p align="center">
  <img src="branding/wlanmon-logo.svg" alt="wlanmon" width="420">
</p>

# wlanmon

wlanmon monitors Wi-Fi quality and availability across multiple sites. Small
Linux devices (NanoPi, Raspberry Pi) periodically scan the Wi-Fi environment
and test connections to configured SSIDs (association, DHCP, reachability,
optionally iperf3). The results end up in a central web dashboard with
history, spectrum view and alerting.

Website: [wlanmon.com](https://wlanmon.com)

| Folder | Contents |
|---|---|
| [`probe/`](probe/) | Probe client (Python) for the measurement devices, including setup script and auto-update – see [probe/README.md](probe/README.md) |
| [`dashboard/`](dashboard/) | Server and web dashboard (PHP + MySQL/MariaDB), classic or as a Docker stack – see [dashboard/README.md](dashboard/README.md) |
| [`branding/`](branding/) | Logo and icon (SVG) |

## Quick start

1. Set up the dashboard – easiest as a Docker stack, see
   [dashboard/README.md, section "Docker"](dashboard/README.md#docker).
2. Add a device in the dashboard; it gives you the ready-made `server`
   configuration including the API key for the probe.
3. Set up the probe (only `git` is needed beforehand, the setup script
   installs everything else):

   ```bash
   sudo apt install -y git
   git clone https://github.com/dgawin/wlanmon.git ~/wlanmon
   cd ~/wlanmon/probe
   sudo ./setup_wlanmon_probe.sh
   ```

   Details (hardware, Wi-Fi adapters, configuration) in
   [probe/README.md](probe/README.md).

## Branches

- `main` – current development state
- `stable` – released state; the auto-updates of probe and dashboard pull from
  here by default

## License

MIT – see [LICENSE](LICENSE). The name and logo "wlanmon" are not covered by
the license.
