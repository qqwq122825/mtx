#!/usr/bin/env python3
"""Apply only to the exact documented custom baseline. No automatic reset."""
import argparse, hashlib, json, subprocess
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--installer',required=True);o=p.parse_args()
root=Path(o.installer).resolve();here=Path(__file__).resolve().parent
records=json.loads((here/'baseline.json').read_text())
def digest(path): return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None
actual={name:digest(root/name) for name in records}
if all(actual[n]==v['after'] for n,v in records.items()):
    print('Already applied.');raise SystemExit(0)
wrong=[n for n,v in records.items() if actual[n]!=v['before']]
if wrong: raise SystemExit('Baseline differs; preserve local changes and review manually: '+', '.join(wrong))
patch=here/'remote-update.patch'
subprocess.run(['git','apply','--check',str(patch)],cwd=root,check=True)
subprocess.run(['git','apply',str(patch)],cwd=root,check=True)
assert all(digest(root/n)==v['after'] for n,v in records.items())
print('Applied; export matching public binding before building.')
