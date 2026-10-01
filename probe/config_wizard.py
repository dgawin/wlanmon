#!/usr/bin/env python3
"""Interactive setup for /etc/wlanmon-probe/config.yaml.

Asks for the values every probe needs (device ID, server URL, API key, TLS,
Wi-Fi interface, country, auto-update, ...), tests the connection to the
dashboard with the API key and writes the answers into config.yaml. Comments
and all other settings in the file stay untouched - only the asked-for values
are replaced. Existing values are offered as defaults, so the wizard can be
re-run at any time to change something:

    sudo wlanmon setup
    sudo /opt/wlanmon-probe/venv/bin/python3 config_wizard.py [config.yaml]

Started by setup_wlanmon_probe.sh on a new install. Standard library only
(PyYAML is used for reading the existing values if available).
"""
from __future__ import annotations

import json
import os
import re
import shutil
import socket
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

DEFAULT_CONFIG = Path("/etc/wlanmon-probe/config.yaml")
EXAMPLE_CONFIG = Path(__file__).resolve().parent / "config.example.yaml"
PLACEHOLDER_KEY = "CHANGE-ME"

BOLD, DIM, GRN, YEL, RED, RST = (
    ("\033[1m", "\033[2m", "\033[32m", "\033[33m", "\033[31m", "\033[0m")
    if sys.stdout.isatty() else ("",) * 6
)


# --- Input helpers ----------------------------------------------------------

def say(text: str = "") -> None:
    print(text)


def ask(question: str, default: str | None = None, validate=None, secret_default: bool = False) -> str:
    """Prompt until validate(answer) returns None (= ok) or an error text."""
    shown = ""
    if default:
        shown = f" [{'keep current' if secret_default else default}]"
    while True:
        try:
            answer = input(f"{BOLD}{question}{RST}{shown}: ").strip()
        except EOFError:
            sys.exit("\nAborted - no input.")
        if not answer and default is not None:
            answer = default
        error = validate(answer) if validate else (None if answer else "Please enter a value.")
        if error is None:
            return answer
        say(f"  {RED}{error}{RST}")


def ask_yes(question: str, default: bool) -> bool:
    hint = "Y/n" if default else "y/N"
    while True:
        try:
            answer = input(f"{BOLD}{question}{RST} [{hint}]: ").strip().lower()
        except EOFError:
            sys.exit("\nAborted - no input.")
        if not answer:
            return default
        if answer in ("y", "yes", "j", "ja"):
            return True
        if answer in ("n", "no", "nein"):
            return False
        say(f"  {RED}Please answer y or n.{RST}")


def ask_choice(question: str, options: list[tuple[str, str]], default: str) -> str:
    """options: (value, label). Returns the chosen value."""
    say(f"{BOLD}{question}{RST}")
    for i, (value, label) in enumerate(options, 1):
        marker = " (current)" if value == default else ""
        say(f"  {i}) {label}{DIM}{marker}{RST}")
    default_index = next((str(i) for i, (v, _) in enumerate(options, 1) if v == default), "1")
    answer = ask("Choice", default_index,
                 lambda a: None if a.isdigit() and 1 <= int(a) <= len(options) else f"1-{len(options)}")
    return options[int(answer) - 1][0]


def section(title: str) -> None:
    say()
    say(f"{BOLD}-- {title} {'-' * max(0, 60 - len(title))}{RST}")


# --- Reading and writing config.yaml ------------------------------------------

def load_values(path: Path) -> dict:
    try:
        import yaml  # noqa: PLC0415 - optional, only for defaults
        with open(path, encoding="utf-8") as fh:
            return yaml.safe_load(fh) or {}
    except Exception:  # noqa: BLE001 - no PyYAML or unreadable: no defaults
        return {}


def get(values: dict, dotted: str, fallback=None):
    node = values
    for part in dotted.split("."):
        if not isinstance(node, dict) or part not in node:
            return fallback
        node = node[part]
    return node


def yaml_scalar(value) -> str:
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, (int, float)):
        return str(value)
    return json.dumps(str(value), ensure_ascii=False)  # JSON string = valid YAML


def set_value(lines: list[str], dotted: str, value) -> None:
    """Replace the value of section.key in place, keeping indentation and any
    trailing comment. Only two-level keys (section: / key:) are supported -
    that is all the wizard writes."""
    section_name, key = dotted.split(".")
    in_section = False
    for i, line in enumerate(lines):
        if re.match(r"^\S", line) and not line.startswith("#"):
            in_section = line.rstrip().rstrip(":") == section_name or line.startswith(section_name + ":")
            continue
        if in_section:
            m = re.match(r"^(\s+)" + re.escape(key) + r":(\s*)([^#\n]*?)(\s+#.*)?$", line.rstrip("\n"))
            if m and len(m.group(1)) == 2:
                comment = m.group(4) or ""
                lines[i] = f"{m.group(1)}{key}: {yaml_scalar(value)}{comment}\n"
                return
    raise KeyError(f"{dotted} not found in config.yaml")


# --- Detection ---------------------------------------------------------------

def wifi_interfaces() -> list[tuple[str, str]]:
    """(name, description) of all Wi-Fi interfaces."""
    result = []
    for dev in sorted(Path("/sys/class/net").glob("*")):
        if not (dev / "wireless").exists() and not (dev / "phy80211").exists():
            continue
        driver = ""
        try:
            driver = os.path.basename(os.readlink(dev / "device" / "driver"))
        except OSError:
            pass
        bus = "USB" if "usb" in str((dev / "device").resolve()) else "onboard"
        mac = (dev / "address").read_text().strip() if (dev / "address").exists() else "?"
        result.append((dev.name, f"{dev.name}  ({bus}, driver {driver or '?'}, MAC {mac})"))
    return result


def default_route_interface() -> str | None:
    try:
        for line in Path("/proc/net/route").read_text().splitlines()[1:]:
            fields = line.split()
            if len(fields) > 1 and fields[1] == "00000000":
                return fields[0]
    except OSError:
        pass
    return None


# --- Connection test -----------------------------------------------------------

def test_connection(url: str, device_id: str, api_key: str, verify) -> tuple[bool, str]:
    """GET <url>/devices/<id>/config with the API key. 200/404 = ID and key
    accepted (404 only means: no central config yet). The response body may
    contain Wi-Fi passwords and is never printed."""
    target = f"{url.rstrip('/')}/devices/{urllib.parse.quote(device_id, safe='')}/config"
    request = urllib.request.Request(target, headers={"Authorization": f"Bearer {api_key}"})
    context = None
    if url.lower().startswith("https://"):
        if verify is False:
            context = ssl._create_unverified_context()  # noqa: S323 - explicitly chosen by the user
        elif isinstance(verify, str):
            context = ssl.create_default_context(cafile=verify)
        else:
            context = ssl.create_default_context()
    try:
        with urllib.request.urlopen(request, timeout=15, context=context) as response:
            return True, f"HTTP {response.status} - device ID and API key accepted, central configuration found."
    except urllib.error.HTTPError as exc:
        if exc.code == 404:
            return True, ("HTTP 404 - device ID and API key accepted. No central configuration yet: "
                          "set up the target SSIDs for this device in the dashboard.")
        if exc.code == 401:
            return False, "HTTP 401 - device ID or API key wrong (both must match the device in the dashboard)."
        return False, f"HTTP {exc.code} - unexpected server response. Is the URL right (ends with /api/v1)?"
    except urllib.error.URLError as exc:
        reason = exc.reason
        if isinstance(reason, ssl.SSLCertVerificationError):
            return False, (f"Certificate not trusted ({reason.verify_message}). Internal server? "
                           "Choose the CA file option for TLS.")
        if isinstance(reason, socket.gaierror):
            return False, "Host name not found (DNS). Check the URL."
        return False, f"Server not reachable: {reason}"
    except (OSError, ValueError) as exc:
        return False, f"Connection failed: {exc}"


# --- Wizard --------------------------------------------------------------------

def validate_url(answer: str) -> str | None:
    if not re.match(r"^https?://[^/\s]+", answer):
        return "Must start with https:// (or http://), e.g. https://wlanmon.example.com/api/v1"
    return None


def normalize_url(answer: str) -> str:
    url = answer.rstrip("/")
    if not url.endswith("/api/v1"):
        url += "/api/v1"
    return url


def run(path: Path) -> int:
    if os.geteuid() != 0:
        sys.exit("Please run as root: sudo wlanmon setup")
    fresh = not path.exists()
    if fresh:
        if not EXAMPLE_CONFIG.exists():
            sys.exit(f"{path} is missing and there is no {EXAMPLE_CONFIG} to start from.")
        path.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy(EXAMPLE_CONFIG, path)
        path.chmod(0o600)
    values = load_values(path)
    answers: dict[str, object] = {}

    say(f"{BOLD}WLANMON probe setup{RST}")
    say(f"Writes {path}. Press Enter to keep the value in [brackets]; Ctrl+C aborts without changes.")
    say("Needed from the dashboard: the device ID and its API key (Devices -> Add device).")

    section("Device")
    answers["device.id"] = ask(
        "Device ID (exactly as created in the dashboard)",
        socket.gethostname() if fresh else str(get(values, "device.id") or socket.gethostname()),
        lambda a: None if re.match(r"^[A-Za-z0-9._-]+$", a) else "Letters, digits, . _ - only.",
    )

    section("Dashboard connection")
    current_url = str(get(values, "server.url") or "")
    url = normalize_url(ask(
        "Dashboard API URL",
        current_url if current_url and "example.com" not in current_url else None,
        validate_url,
    ))
    answers["server.url"] = url
    current_key = str(get(values, "server.api_key") or "")
    has_key = bool(current_key) and current_key != PLACEHOLDER_KEY
    answers["server.api_key"] = ask(
        "API key (shown once when the device was created)",
        current_key if has_key else None, secret_default=True,
    )

    verify: object = True
    if url.lower().startswith("http://"):
        say(f"  {YEL}Warning: http:// sends the API key and the central configuration including Wi-Fi "
            f"passwords unencrypted. Only use it in an isolated test network.{RST}")
        if not ask_yes("Continue with http:// anyway?", False):
            say("Please re-run the wizard with an https:// URL.")
            return 1
    else:
        current_verify = get(values, "server.verify_tls", True)
        current_mode = "ca" if isinstance(current_verify, str) else ("off" if current_verify is False else "on")
        mode = ask_choice("Server certificate", [
            ("on", "Public certificate (e.g. Let's Encrypt) - recommended"),
            ("ca", "Internal CA - trust a CA certificate file on this probe"),
            ("off", "Do not verify (self-signed, no CA file) - encrypted, but not protected against interception"),
        ], current_mode)
        if mode == "ca":
            verify = ask(
                "Path to the CA certificate",
                current_verify if isinstance(current_verify, str) else "/etc/wlanmon-probe/wlanmon-ca.crt",
                lambda a: None if Path(a).is_file() else f"{a} not found - copy the CA file onto the probe first.",
            )
        elif mode == "off":
            verify = False
    answers["server.verify_tls"] = verify

    while True:
        say("  Testing the connection ...")
        ok, message = test_connection(url, str(answers["device.id"]), str(answers["server.api_key"]), verify)
        say(f"  {GRN if ok else RED}{message}{RST}")
        if ok or not ask_yes("Change the answers and test again?", True):
            if not ok and not ask_yes("Save anyway (the probe will keep retrying)?", False):
                return 1
            break
        answers["server.url"] = url = normalize_url(ask("Dashboard API URL", url, validate_url))
        answers["device.id"] = ask("Device ID", str(answers["device.id"]))
        answers["server.api_key"] = ask("API key", str(answers["server.api_key"]), secret_default=True)

    section("Wi-Fi interface for the tests")
    interfaces = wifi_interfaces()
    uplink = default_route_interface()
    current_iface = str(get(values, "interface.name") or "")
    if interfaces:
        options = [(name, label + (f"  {YEL}<- carries the network uplink{RST}" if name == uplink else ""))
                   for name, label in interfaces]
        default_iface = current_iface if current_iface in dict(interfaces) else next(
            (n for n, l in interfaces if "USB" in l and n != uplink), interfaces[0][0])
        iface = ask_choice("Which adapter should run the tests? It is disconnected and reconfigured for "
                           "every test.", options, default_iface)
        if iface == uplink:
            say(f"  {YEL}Warning: {iface} carries the default route. The tests interrupt this connection - "
                f"use Ethernet for the uplink.{RST}")
    else:
        say(f"  {YEL}No Wi-Fi interface found (adapter plugged in? driver loaded?).{RST}")
        iface = ask("Interface name", current_iface or "wlan0")
    answers["interface.name"] = iface
    answers["interface.country"] = ask(
        "Country code for the regulatory domain (allowed channels, e.g. DE, AT, CH, US)",
        str(get(values, "interface.country") or "DE"),
        lambda a: None if re.match(r"^[A-Za-z]{2}$", a) else "Two letters, e.g. DE.",
    ).upper()

    section("Operation")
    answers["remote_config.enabled"] = ask_yes(
        "Fetch the test configuration (SSIDs, intervals) from the dashboard? Recommended",
        bool(get(values, "remote_config.enabled", True)))
    answers["auto_update.enabled"] = ask_yes(
        "Install updates automatically from the git checkout?", bool(get(values, "auto_update.enabled", False)))
    if answers["auto_update.enabled"]:
        answers["auto_update.branch"] = ask_choice("Update channel", [
            ("stable", "stable - released versions (recommended)"),
            ("main", "main - every change immediately (test devices)"),
        ], str(get(values, "auto_update.branch") or "stable"))
    answers["watchdog.enabled"] = ask_yes(
        "Reboot automatically if the Wi-Fi adapter disappears (USB hotplug watchdog)?",
        bool(get(values, "watchdog.enabled", False)))
    answers["display.enabled"] = ask_yes(
        "Is a NanoHat OLED display with buttons attached?", bool(get(values, "display.enabled", False)))

    section("Summary")
    for key, value in answers.items():
        shown = "**** (set)" if key == "server.api_key" else yaml_scalar(value)
        say(f"  {key:<24} {shown}")
    if not ask_yes(f"Write these values to {path}?", True):
        say("Nothing changed.")
        return 1

    lines = path.read_text(encoding="utf-8").splitlines(keepends=True)
    for key, value in answers.items():
        set_value(lines, key, value)
    backup = path.with_name(f"{path.name}.bak.{time.strftime('%Y%m%d%H%M%S')}")
    shutil.copy2(path, backup)
    tmp = path.with_name(path.name + ".tmp")
    tmp.write_text("".join(lines), encoding="utf-8")
    tmp.chmod(0o600)
    os.replace(tmp, path)
    say(f"  {GRN}Saved.{RST} Previous version: {backup}")
    return 0


def main() -> int:
    path = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_CONFIG
    try:
        return run(path)
    except KeyboardInterrupt:
        say("\nAborted - nothing changed.")
        return 1


if __name__ == "__main__":
    sys.exit(main())
