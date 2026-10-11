#!/usr/bin/env python3
"""Build and validate the WordPress-installable GitHub release archive."""
import argparse
import pathlib
import re
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
MAIN = ROOT / "arcwind-init.php"
VERSION_RE = re.compile(r"^\s*\* Version:\s*([0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?)", re.M)
REQUIRED = {"arcwind-init.php", "includes/class-arcwind-init-tracker.php",
            "includes/class-arcwind-init-oneuptime.php",
            "includes/class-arcwind-init-oneuptime-dashboard.php",
            "includes/class-arcwind-init-diagnostics.php",
            "includes/class-arcwind-init-github-updater.php",
            "includes/class-arcwind-init-settings-trait.php",
            "includes/class-arcwind-init-dashboard-trait.php",
            "includes/class-arcwind-init-feedback-trait.php",
            "includes/class-arcwind-init-posthog-trait.php",
            "assets/js/arcwind-init-tracker.js", "README.md", "CHANGELOG.md", "LICENSE"}


def version():
    match = VERSION_RE.search(MAIN.read_text())
    if not match:
        raise ValueError("Plugin version header missing or invalid")
    return match.group(1)


def build(output):
    files = [MAIN, *sorted((ROOT / "includes").glob("*.php")),
             *sorted((ROOT / "assets").rglob("*.js")),
             ROOT / "README.md", ROOT / "CHANGELOG.md", ROOT / "LICENSE"]
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for file in files:
            archive.write(file, "arcwind-init/" + file.relative_to(ROOT).as_posix())


def validate(output, expected):
    with zipfile.ZipFile(output) as archive:
        names = archive.namelist()
        if len(names) != len(set(names)) or any(not name.startswith("arcwind-init/") or ".." in pathlib.PurePosixPath(name).parts for name in names):
            raise ValueError("Archive has duplicate or unsafe paths")
        packaged = {name.removeprefix("arcwind-init/") for name in names}
        if not REQUIRED.issubset(packaged):
            raise ValueError("Archive is missing required files: " + ", ".join(sorted(REQUIRED - packaged)))
        header = archive.read("arcwind-init/arcwind-init.php").decode()
        match = VERSION_RE.search(header)
        if not match or match.group(1) != expected:
            raise ValueError("Archive version does not match expected version")
    return expected


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=pathlib.Path, default=ROOT / "dist/arcwind-init.zip")
    parser.add_argument("--tag", help="Git tag, e.g. v1.8.0; must match the plugin header")
    parser.add_argument("--validate-only", action="store_true")
    args = parser.parse_args()
    expected = version()
    if args.tag and args.tag.lstrip("vV") != expected:
        parser.error("Tag must match plugin version header")
    if not args.validate_only:
        build(args.output)
    validate(args.output, expected)
    print(f"Validated {args.output} (ArcWind Init {expected})")
