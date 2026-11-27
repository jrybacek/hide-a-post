#!/usr/bin/env python3
"""Pre-deploy checks for the Hide A Post WordPress.org release.

Usage: validate-release.py [TREE-ISH]

Environment:
  EXPECTED_VERSION  Version from the pushed git tag (a leading "v" is ignored).
                    When empty, the plugin header, HAP_VERSION, and the readme
                    Stable tag only need to agree with each other.

Checks:
  a) Tag version == "Version:" header == HAP_VERSION == readme "Stable tag:".
  b) Every "== Upgrade Notice ==" entry is at most 300 characters, and the
     current version has one.
  c) assets/blueprints/blueprint.json is valid JSON.
  d) `git archive TREE-ISH` (what the SVN deploy exports) contains only
     runtime plugin files.
"""

import io
import json
import os
import re
import subprocess
import sys
import tarfile

PLUGIN_FILE = "hide-a-post.php"
README_FILE = "readme.txt"
BLUEPRINT_FILE = "assets/blueprints/blueprint.json"
UPGRADE_NOTICE_MAX = 300
SHORT_DESCRIPTION_MAX = 150

# Everything the release package may contain.
ALLOWED_FILE = re.compile(
    r"^(hide-a-post\.php|uninstall\.php|readme\.txt|LICENSE|includes/[A-Za-z0-9_.-]+\.php)$"
)
REQUIRED_FILES = {PLUGIN_FILE, "uninstall.php", README_FILE, "LICENSE"}

errors = []


def fail(message):
    errors.append(message)
    print(f"::error::{message}")


def ok(message):
    print(f"ok: {message}")


def read(path):
    with io.open(path, encoding="utf-8") as handle:
        return handle.read().replace("\r\n", "\n")


def header(text, name):
    match = re.search(r"^[ \t/*#@]*" + re.escape(name) + r":[ \t]*(.+?)[ \t]*$", text, re.M | re.I)
    return match.group(1).strip() if match else None


def check_versions(plugin, readme):
    header_version = header(plugin, "Version")
    constant = re.search(r"define\(\s*'HAP_VERSION'\s*,\s*'([^']+)'\s*\)", plugin)
    hap_version = constant.group(1) if constant else None
    stable_tag = header(readme, "Stable tag")

    found = {
        f"{PLUGIN_FILE} Version": header_version,
        "HAP_VERSION": hap_version,
        f"{README_FILE} Stable tag": stable_tag,
    }
    for label, value in found.items():
        if not value:
            fail(f"Could not read {label}.")

    expected = os.environ.get("EXPECTED_VERSION", "").strip()
    if expected.startswith("v"):
        expected = expected[1:]
    if expected:
        found["git tag"] = expected

    values = {value for value in found.values() if value}
    if len(values) > 1:
        fail("Version mismatch: " + ", ".join(f"{k}={v}" for k, v in found.items()))
    elif values:
        ok("Versions agree: " + ", ".join(f"{k}={v}" for k, v in found.items()))

    for name in ("Requires at least", "Requires PHP"):
        if header(plugin, name) != header(readme, name):
            fail(f'"{name}" differs: {PLUGIN_FILE}={header(plugin, name)}, {README_FILE}={header(readme, name)}')

    return stable_tag


def check_short_description(readme):
    # The short description is the first non-empty paragraph after the header block.
    head = readme.split("\n== ", 1)[0]
    blocks = [b.strip() for b in head.split("\n\n")[1:] if b.strip()]
    if not blocks:
        fail("readme.txt has no short description.")
        return
    length = len(blocks[0])
    if length > SHORT_DESCRIPTION_MAX:
        fail(f"Short description is {length} characters (max {SHORT_DESCRIPTION_MAX}).")
    else:
        ok(f"Short description is {length} characters.")


def check_upgrade_notice(readme, version):
    match = re.search(r"^== Upgrade Notice ==[ \t]*\n(.*?)(?=^== |\Z)", readme, re.M | re.S)
    if not match:
        fail("readme.txt has no == Upgrade Notice == section.")
        return
    entries = re.findall(r"^= *([^=\n]+?) *=[ \t]*\n(.*?)(?=^= |\Z)", match.group(1), re.M | re.S)
    if version and version not in {name for name, _ in entries}:
        fail(f"Upgrade Notice has no entry for {version}.")
    for name, body in entries:
        text = " ".join(body.split())
        if len(text) > UPGRADE_NOTICE_MAX:
            fail(f"Upgrade Notice {name} is {len(text)} characters (max {UPGRADE_NOTICE_MAX}).")
        else:
            ok(f"Upgrade Notice {name} is {len(text)} characters.")


def check_blueprint():
    try:
        with io.open(BLUEPRINT_FILE, encoding="utf-8") as handle:
            blueprint = json.load(handle)
    except (OSError, ValueError) as exc:
        fail(f"{BLUEPRINT_FILE} is not valid JSON: {exc}")
        return
    if not isinstance(blueprint, dict) or not isinstance(blueprint.get("steps"), list):
        fail(f"{BLUEPRINT_FILE} must be an object with a steps array.")
        return
    ok(f"{BLUEPRINT_FILE} is valid JSON ({len(blueprint['steps'])} steps).")


def check_export(tree_ish):
    archive = subprocess.run(
        ["git", "archive", "--format=tar", tree_ish], check=True, stdout=subprocess.PIPE
    ).stdout
    with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
        files = sorted(m.name for m in tar.getmembers() if not m.isdir())

    leaked = [name for name in files if not ALLOWED_FILE.match(name)]
    missing = sorted(REQUIRED_FILES - set(files))
    if not any(name.startswith("includes/") for name in files):
        missing.append("includes/*.php")

    for name in leaked:
        fail(f"Non-runtime file in release package: {name}")
    for name in missing:
        fail(f"Missing from release package: {name}")
    if not leaked and not missing:
        ok(f"Release package has only runtime files ({len(files)}):")
        for name in files:
            print(f"    {name}")


def main():
    tree_ish = sys.argv[1] if len(sys.argv) > 1 else "HEAD"
    plugin = read(PLUGIN_FILE)
    readme = read(README_FILE)

    version = check_versions(plugin, readme)
    check_short_description(readme)
    check_upgrade_notice(readme, version)
    check_blueprint()
    check_export(tree_ish)

    if errors:
        print(f"\n{len(errors)} check(s) failed.")
        return 1
    print("\nAll release checks passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
