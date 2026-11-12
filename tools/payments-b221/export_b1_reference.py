"""Export only pinned B1 code/test fixtures into a NEW external directory; never merge."""

import argparse
import hashlib
import json
import pathlib
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument("destination", type=pathlib.Path)
args = parser.parse_args()
repo = pathlib.Path(__file__).resolve().parents[2]
manifest = json.loads((repo / "tests/Support/PricingSqlite/b1-reference.json").read_text())
destination = args.destination.absolute()
if destination == repo or repo in destination.parents:
    raise SystemExit("The reference must be outside the repository.")
sources = {}
for name, digest in manifest["files"].items():
    content = subprocess.check_output(["git", "show", manifest["commit"] + ":" + name], cwd=repo)
    if hashlib.sha256(content).hexdigest() != digest:
        raise SystemExit("Pinned B1 source mismatch: " + name)
    sources[name] = content
destination.mkdir(mode=0o700)  # Refuse any pre-existing destination.
for name, content in sources.items():
    target = destination / name
    target.parent.mkdir(parents=True, exist_ok=True)
    with target.open("xb") as output:
        output.write(content)
print(destination)
