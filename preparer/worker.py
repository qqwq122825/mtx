#!/usr/bin/env python3
"""One bounded queue run, as PHP's user. No network or uploaded-code execution."""
import argparse, fcntl, json, os, re, shutil, subprocess
from pathlib import Path
from prepare import PreparationError, prepare

def run(php):
    os.umask(0o077)
    root=Path(__file__).resolve().parents[1]
    def queue(data):
        result=subprocess.run([php,str(root/'bin/preparation-queue.php')],input=json.dumps(data),capture_output=True,text=True,timeout=30,cwd=root)
        if result.returncode: raise RuntimeError('queue operation failed')
        return json.loads(result.stdout)
    # A global per-worker lock prevents overlapping cron jobs on the same host.
    # State claims independently protect concurrent workers and stale completions.
    lockdir=Path(queue({'action':'location'})['work_root']);lockdir.mkdir(mode=0o700,parents=True,exist_ok=True)
    with (lockdir/'worker.lock').open('a') as lock:
        try: fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError: return
        for _ in range(2):
            job=queue({'action':'claim'})
            if job is None: break
            if not all(re.fullmatch('[0-9a-f]{32}',job[k]) for k in ['release_id','token']): raise RuntimeError('invalid job')
            workroot=Path(job['work_root']);workroot.mkdir(mode=0o700,parents=True,exist_ok=True)
            work=workroot/(job['release_id']+'-'+job['token'])
            work.mkdir(mode=0o700)
            try:
                output=prepare(job,work)
                queue({'action':'finish','release_id':job['release_id'],'token':job['token'],'path':str(output)})
                print(json.dumps({'release_id':job['release_id'],'status':'ready'}),flush=True)
            except Exception as error:
                message=str(error) if isinstance(error,PreparationError) else '自动处理未完成，请刷新后重试。'
                try: queue({'action':'fail','release_id':job['release_id'],'token':job['token'],'message':message})
                except Exception: pass # Withdrawn or replaced job must not be resurrected.
                print(json.dumps({'release_id':job['release_id'],'status':'failed'}),flush=True)
            finally:
                shutil.rmtree(work)

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php',default='/usr/bin/php')
    run(parser.parse_args().php)
