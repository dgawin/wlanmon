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
KEEP_BACKUPS = 5  # config.yaml.bak.<timestamp> files kept after saving

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


def is_checkout(path: Path) -> bool:
    """Probe sources with a .git in the folder itself or a parent (probe/
    subfolder of the shared repo) - same rule as git_toplevel() in
    update_probe.py."""
    if not (path / "update_probe.py").is_file():
        return False
    return any((d / ".git").exists() for d in (path, *path.parents))


# --- Questions -------------------------------------------------------------------
# One function per value, so the summary can ask a single value again.

def ask_device_id(default: str) -> str:
    return ask("Device ID (exactly as created in the dashboard)", default,
               lambda a: None if re.match(r"^[A-Za-z0-9._-]+$", a) else "Letters, digits, . _ - only.")


def ask_url(default: str | None) -> str:
    return normalize_url(ask("Dashboard API URL", default, validate_url))


def ask_api_key(current: str) -> str:
    has_key = bool(current) and current != PLACEHOLDER_KEY
    return ask("API key (shown once when the device was created)",
               current if has_key else None, secret_default=True)


def ask_tls(url: str, current) -> object | None:
    """Value for server.verify_tls; None = user does not want http://."""
    if url.lower().startswith("http://"):
        say(f"  {YEL}Warning: http:// sends the API key and the central configuration including Wi-Fi "
            f"passwords unencrypted. Only use it in an isolated test network.{RST}")
        if not ask_yes("Continue with http:// anyway?", False):
            return None
        return current if current is not None else True
    current_mode = "ca" if isinstance(current, str) else ("off" if current is False else "on")
    mode = ask_choice("Server certificate", [
        ("on", "Public certificate (e.g. Let's Encrypt) - recommended"),
        ("ca", "Internal CA - trust a CA certificate file on this probe"),
        ("off", "Do not verify (self-signed, no CA file) - encrypted, but not protected against interception"),
    ], current_mode)
    if mode == "ca":
        return ask(
            "Path to the CA certificate",
            current if isinstance(current, str) else "/etc/wlanmon-probe/wlanmon-ca.crt",
            lambda a: None if Path(a).is_file() else f"{a} not found - copy the CA file onto the probe first.",
        )
    return mode == "on"


def check_connection(answers: dict) -> bool:
    """Tests until it works or the user gives up. False = do not save."""
    while True:
        say("  Testing the connection ...")
        ok, message = test_connection(str(answers["server.url"]), str(answers["device.id"]),
                                      str(answers["server.api_key"]), answers["server.verify_tls"])
        say(f"  {GRN if ok else RED}{message}{RST}")
        if ok:
            return True
        if not ask_yes("Change the answers and test again?", True):
            return ask_yes("Save anyway (the probe will keep retrying)?", False)
        answers["server.url"] = ask_url(str(answers["server.url"]))
        answers["device.id"] = ask_device_id(str(answers["device.id"]))
        answers["server.api_key"] = ask_api_key(str(answers["server.api_key"]))


def ask_interface(current: str) -> tuple[str, bool | None]:
    """(interface, is_usb); is_usb is None if no adapter was found."""
    interfaces = wifi_interfaces()
    if not interfaces:
        say(f"  {YEL}No Wi-Fi interface found (adapter plugged in? driver loaded?).{RST}")
        return ask("Interface name", current or "wlan0"), None
    uplink = default_route_interface()
    options = [(name, label + (f"  {YEL}<- carries the network uplink{RST}" if name == uplink else ""))
               for name, label in interfaces]
    default_iface = current if current in dict(interfaces) else next(
        (n for n, l in interfaces if "USB" in l and n != uplink), interfaces[0][0])
    iface = ask_choice("Which adapter should run the tests? It is disconnected and reconfigured for "
                       "every test.", options, default_iface)
    if iface == uplink:
        say(f"  {YEL}Warning: {iface} carries the default route. The tests interrupt this connection - "
            f"use Ethernet for the uplink.{RST}")
    return iface, "(USB," in dict(interfaces)[iface]


def ask_country(default: str) -> str:
    return ask("Country code for the regulatory domain (allowed channels, e.g. DE, AT, CH, US)", default,
               lambda a: None if re.match(r"^[A-Za-z]{2}$", a) else "Two letters, e.g. DE.").upper()


def ask_branch(default: str) -> str:
    return ask_choice("Update channel", [
        ("stable", "stable - released versions (recommended)"),
        ("main", "main - every change immediately (test devices)"),
    ], default)


def ask_repo_dir(current: str) -> str:
    """Git checkout the updates come from. Default: the configured one if it is
    a checkout, otherwise the folder this wizard runs from."""
    own = Path(__file__).resolve().parent
    default = current if current and is_checkout(Path(current)) else (str(own) if is_checkout(own) else current)
    return ask("Git checkout for the updates (repo_dir)", default or None,
               lambda a: None if is_checkout(Path(a)) else
               f"{a} is not a probe checkout (needs update_probe.py and a .git folder).")


def ask_watchdog(default: bool, is_usb: bool | None) -> bool:
    if is_usb is False:
        # Onboard-Adapter (Pi, brcmfmac) verschwinden nicht per Hotplug.
        say(f"  {DIM}USB hotplug watchdog: off (the adapter is built in).{RST}")
        return False
    return ask_yes("Reboot automatically if the Wi-Fi adapter disappears (USB hotplug watchdog)?", default)


def ask_display(default: bool, i2c_port) -> bool:
    enabled = ask_yes("Is a NanoHat OLED display with buttons attached?", default)
    if enabled and not Path(f"/dev/i2c-{i2c_port}").exists():
        # Frisches Armbian: Overlay "i2c0" ist aus, das Display bleibt dann
        # dunkel und der Dienst loggt nur "I2C device not found".
        say(f"  {YEL}Warning: /dev/i2c-{i2c_port} does not exist - the I2C bus is not enabled. On Armbian add "
            f"\"i2c{i2c_port}\" to the overlays= line in /boot/armbianEnv.txt (or use armbian-config) "
            f"and reboot.{RST}")
    return enabled


def ask_auto_update(answers: dict, values: dict, default: bool) -> None:
    answers["auto_update.enabled"] = ask_yes("Install updates automatically from the git checkout?", default)
    if answers["auto_update.enabled"]:
        answers["auto_update.branch"] = ask_branch(
            str(answers.get("auto_update.branch") or get(values, "auto_update.branch") or "stable"))
        answers["auto_update.repo_dir"] = ask_repo_dir(
            str(answers.get("auto_update.repo_dir") or get(values, "auto_update.repo_dir") or ""))
    else:
        answers.pop("auto_update.branch", None)
        answers.pop("auto_update.repo_dir", None)


ORDER = ["device.id", "server.url", "server.api_key", "server.verify_tls", "interface.name",
         "interface.country", "remote_config.enabled", "auto_update.enabled", "auto_update.branch",
         "auto_update.repo_dir", "watchdog.enabled", "display.enabled"]


def change(key: str, answers: dict, values: dict, state: dict) -> bool:
    """Asks one value again (current answer as default). False = abort."""
    if key == "device.id":
        answers[key] = ask_device_id(str(answers[key]))
    elif key == "server.url":
        answers[key] = ask_url(str(answers[key]))
        verify = ask_tls(str(answers[key]), answers["server.verify_tls"])
        if verify is None:
            return False
        answers["server.verify_tls"] = verify
    elif key == "server.api_key":
        answers[key] = ask_api_key(str(answers[key]))
    elif key == "server.verify_tls":
        verify = ask_tls(str(answers["server.url"]), answers[key])
        if verify is None:
            return False
        answers[key] = verify
    elif key == "interface.name":
        answers[key], state["is_usb"] = ask_interface(str(answers[key]))
        answers["watchdog.enabled"] = ask_watchdog(bool(answers["watchdog.enabled"]), state["is_usb"])
    elif key == "interface.country":
        answers[key] = ask_country(str(answers[key]))
    elif key == "remote_config.enabled":
        answers[key] = ask_yes("Fetch the test configuration (SSIDs, intervals) from the dashboard? "
                               "Recommended", bool(answers[key]))
    elif key == "auto_update.enabled":
        ask_auto_update(answers, values, bool(answers[key]))
    elif key == "auto_update.branch":
        answers[key] = ask_branch(str(answers[key]))
    elif key == "auto_update.repo_dir":
        answers[key] = ask_repo_dir(str(answers[key]))
    elif key == "watchdog.enabled":
        answers[key] = ask_watchdog(bool(answers[key]), None if state["is_usb"] is False else state["is_usb"])
    elif key == "display.enabled":
        answers[key] = ask_display(bool(answers[key]), get(values, "display.i2c_port", 0))
    if key.startswith(("device.", "server.")):
        return check_connection(answers)
    return True


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
    state: dict[str, object] = {"is_usb": None}

    say(f"{BOLD}WLANMON probe setup{RST}")
    say(f"Writes {path}. Press Enter to keep the value in [brackets]; Ctrl+C aborts without changes.")
    say("Needed from the dashboard: the device ID and its API key (Devices -> Add device).")

    section("Device")
    answers["device.id"] = ask_device_id(
        socket.gethostname() if fresh else str(get(values, "device.id") or socket.gethostname()))

    section("Dashboard connection")
    current_url = str(get(values, "server.url") or "")
    answers["server.url"] = ask_url(current_url if current_url and "example.com" not in current_url else None)
    answers["server.api_key"] = ask_api_key(str(get(values, "server.api_key") or ""))
    verify = ask_tls(str(answers["server.url"]), get(values, "server.verify_tls", True))
    if verify is None:
        say("Please re-run the wizard with an https:// URL.")
        return 1
    answers["server.verify_tls"] = verify
    if not check_connection(answers):
        return 1

    section("Wi-Fi interface for the tests")
    answers["interface.name"], state["is_usb"] = ask_interface(str(get(values, "interface.name") or ""))
    answers["interface.country"] = ask_country(str(get(values, "interface.country") or "DE"))

    section("Operation")
    answers["remote_config.enabled"] = ask_yes(
        "Fetch the test configuration (SSIDs, intervals) from the dashboard? Recommended",
        bool(get(values, "remote_config.enabled", True)))
    ask_auto_update(answers, values, bool(get(values, "auto_update.enabled", False)))
    answers["watchdog.enabled"] = ask_watchdog(bool(get(values, "watchdog.enabled", False)), state["is_usb"])
    answers["display.enabled"] = ask_display(bool(get(values, "display.enabled", False)),
                                             get(values, "display.i2c_port", 0))

    while True:
        section("Summary")
        keys = [k for k in ORDER if k in answers]
        for i, key in enumerate(keys, 1):
            shown = "**** (set)" if key == "server.api_key" else yaml_scalar(answers[key])
            say(f"  {i:>2}) {key:<24} {shown}")
        if ask_yes(f"Write these values to {path}?", True):
            break
        choice = ask(f"Number of the value to change (1-{len(keys)}, Enter = quit without saving)", "",
                     lambda a: None if not a or (a.isdigit() and 1 <= int(a) <= len(keys))
                     else f"1-{len(keys)}, or Enter to quit.")
        if not choice:
            say("Nothing changed.")
            return 1
        if not change(keys[int(choice) - 1], answers, values, state):
            say("Nothing changed.")
            return 1

    old_text = path.read_text(encoding="utf-8")
    lines = old_text.splitlines(keepends=True)
    for key, value in answers.items():
        set_value(lines, key, value)
    if "".join(lines) == old_text:
        say(f"  {GRN}No changes{RST} - {path} stays as it is.")
        return 0
    backup = path.with_name(f"{path.name}.bak.{time.strftime('%Y%m%d%H%M%S')}")
    shutil.copy2(path, backup)
    # Nur die letzten Sicherungen behalten (Zeitstempel sortieren lexikalisch).
    for old in sorted(path.parent.glob(f"{path.name}.bak.*"))[:-KEEP_BACKUPS]:
        old.unlink(missing_ok=True)
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
