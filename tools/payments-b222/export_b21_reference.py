"""Export pinned B2.1 code/tests to a NEW external directory, never merge."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument('destination', type=Path)
args = parser.parse_args()
repo = Path(__file__).resolve().parents[2]
manifest = json.loads((repo / 'tests/Support/HoldsSqlite/b21-reference.json').read_text())
destination = args.destination.absolute()
if destination == repo or repo in destination.parents:
    raise SystemExit('The reference must be external.')
blobs = {}
for name, digest in manifest['files'].items():
    blob = subprocess.check_output(['git', 'show', manifest['commit'] + ':' + name], cwd=repo)
    if hashlib.sha256(blob).hexdigest() != digest:
        raise SystemExit('Source mismatch: ' + name)
    blobs[name] = blob
destination.mkdir(mode=0o700)
for name, blob in blobs.items():
    path = destination / name
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open('xb') as output:
        output.write(blob)
print(destination)
