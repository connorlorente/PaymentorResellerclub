"""Build lean upload zips for Paymenter's Admin > Extensions > Upload Extension.

Usage (from the repo root):  python build.py
Output: dist/ResellerClub.zip, dist/PleskDomains.zip, dist/InvoiceDomains.zip
Tests, vendor/ and dev files are left out; Paymenter's own autoloader loads the classes.
"""
import os
import zipfile

ROOT = os.path.dirname(os.path.abspath(__file__))


def build(src, name, exclude_dirs=(), exclude_files=()):
    src = os.path.join(ROOT, src)
    os.makedirs(os.path.join(ROOT, "dist"), exist_ok=True)
    out = os.path.join(ROOT, "dist", f"{name}.zip")
    count = 0
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for root, dirs, files in os.walk(src):
            rel = os.path.relpath(root, src).replace(os.sep, "/")
            dirs[:] = [d for d in dirs if (d if rel == "." else f"{rel}/{d}") not in exclude_dirs]
            for f in files:
                r = f if rel == "." else f"{rel}/{f}"
                if r in exclude_files:
                    continue
                z.write(os.path.join(root, f), f"{name}/{r}")
                count += 1
    print(f"dist/{name}.zip: {count} files, {os.path.getsize(out) // 1024} KB")


if __name__ == "__main__":
    build(
        "extensions/Servers/ResellerClub",
        "ResellerClub",
        exclude_dirs=("vendor", "tests"),
        exclude_files=("phpunit.xml", "composer.json", "composer.lock", ".phpunit.result.cache", "PHASE0.md"),
    )
    build("extensions/Servers/PleskDomains", "PleskDomains")
    build("other-extensions/InvoiceDomains", "InvoiceDomains")
