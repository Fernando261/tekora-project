#!/usr/bin/env python3
"""Export only manifest-listed exact Git text blobs into a NEW external directory."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys

repo, target = Path(sys.argv[1]).resolve(), Path(sys.argv[2]).absolute()
manifest = json.loads((Path(__file__).resolve().parents[2] / 'tests/Support/PaymentsIntegration/references.json').read_text())
target.mkdir(mode=0o700)  # never adopt an existing export
for ref, entry in manifest.items():
    for name, digest in entry['files'].items():
        if name.startswith('/') or '..' in Path(name).parts:
            raise RuntimeError('Unsafe manifest path')
        data = subprocess.check_output(['git', '-C', str(repo), 'show', entry['commit'] + ':' + name])
        if hashlib.sha256(data).hexdigest() != digest:
            raise RuntimeError('Reference hash differs: ' + ref + ':' + name)
        dest = target / ref / name
        dest.parent.mkdir(parents=True, exist_ok=True)
        with dest.open('xb') as output:
            output.write(data)
        dest.chmod(0o444)
print(json.dumps({'references': {k:v['commit'] for k,v in manifest.items()}, 'files':sum(len(v['files']) for v in manifest.values())}))
