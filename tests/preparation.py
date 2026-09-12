#!/usr/bin/env python3
"""Queue regression with disposable state; a real TIPA is read, never run."""
import argparse, hashlib, json, os, secrets, subprocess, tempfile, time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BINARY','php')
p=argparse.ArgumentParser();p.add_argument('--source',required=True);a=p.parse_args();source=Path(a.source).resolve();checks=0

def check(ok,label):
 global checks
 assert ok,label
 checks+=1; print('PASS',label,flush=True)

with tempfile.TemporaryDirectory(prefix='mtx-auto-prepare-') as td:
 t=Path(td);config=t/'config.php';storage=t/'storage';env=os.environ|{'MTX_CONFIG':str(config)}
 subprocess.run([PHP,str(ROOT/'bin/setup.php'),'--url','http://127.0.0.1:19999','--local-http','--config',str(config),'--storage',str(storage),'--password-stdin'],input=secrets.token_hex(20)+'\n',text=True,check=True,capture_output=True)
 storage=storage.resolve()
 def php(code,*args):
  return subprocess.run([PHP,'-r','require "vendor/autoload.php";'+code,*map(str,args)],env=env,cwd=ROOT,text=True,capture_output=True,check=True).stdout
 def queue(data,ok=True):
  r=subprocess.run([PHP,str(ROOT/'bin/preparation-queue.php')],input=json.dumps(data),env=env,cwd=ROOT,text=True,capture_output=True)
  check(r.returncode==0 if ok else r.returncode!=0,'queue '+data['action']+(' accepted' if ok else 'rejected'))
  return json.loads(r.stdout) if r.returncode==0 else None
 def read():return json.loads((storage/'state.json').read_text())
 def write(s):(storage/'state.json').write_text(json.dumps(s))
 rid=php('$a=new MTX\\App();echo (new MTX\\Releases($a))->upload("mtx-dfm-cn",$argv[1],[]);',source)
 check(read()['releases'][rid]['preparation']['status']=='queued','single TIPA queues preparation')
 check('path' not in queue({'action':'location'}) and queue({'action':'location'})['work_root']==str(storage/'preparation'),'custom storage honored')
 job=queue({'action':'claim'});check(job['release_id']==rid,'claim expected record')
 check(queue({'action':'claim'}) is None,'concurrent worker does not duplicate lease')
 queue({'action':'finish','release_id':rid,'token':'0'*32,'path':'/tmp/wrong'},False)
 queue({'action':'finish','release_id':rid,'token':job['token'],'path':'/tmp/wrong'},False)
 s=read();s['releases'][rid]['preparation']['started_at']=int(time.time())-400;write(s)
 second=queue({'action':'claim'});check(second['token']!=job['token'],'expired lease receives new token')
 queue({'action':'fail','release_id':rid,'token':job['token'],'message':'obsolete'},False)
 queue({'action':'fail','release_id':rid,'token':second['token'],'message':'fixture error'})
 check(queue({'action':'claim'}) is None,'failed job waits for explicit retry')
 php('(new MTX\\Preparation(new MTX\\App()))->retry("mtx-dfm-cn",$argv[1]);',rid)
 check(read()['releases'][rid]['preparation']['status']=='queued','retry preserves upload without another file')
 result=subprocess.run(['python3',str(ROOT/'preparer/worker.py'),'--php',PHP],env=env,cwd=ROOT,text=True,capture_output=True,timeout=130)
 check(result.returncode==0,'worker runs with custom storage: '+result.stderr[:100])
 r=read()['releases'][rid]
 check(r['state']=='ready','single real TIPA becomes ready: '+str(r.get('preparation')))
 check(r['source_sha256']==hashlib.sha256(source.read_bytes()).hexdigest(),'original uploaded bytes preserved')
 check(r['sequence'] is None and read()['apps']['mtx-dfm-cn']['current_release_id'] is None,'preparation does not publish')
 check(not list((storage/'preparation').glob('*-*')),'private job temporary files removed')
 php('$a=new MTX\\App();MTX\\Packages::prepared($a->object($argv[1]),$a->store->read()["releases"][$argv[2]]);',r['artifact_sha256'],rid)
 check(True,'generated TAR matches existing native contract')
 # Late completions and historical records must never become live implicitly.
 s=read();s['releases'][rid]['state']='draft';s['releases'][rid]['preparation']={'status':'queued','attempts':0};write(s)
 job=queue({'action':'claim'});s=read();s['releases'][rid]['state']='withdrawn';write(s)
 queue({'action':'fail','release_id':rid,'token':job['token'],'message':'late'},False)
 check(read()['releases'][rid]['state']=='withdrawn','withdrawn record stays withdrawn')
 # Existing drafts without preparation metadata are picked up unchanged.
 s=read();s['releases'][rid]['state']='draft';s['releases'][rid].pop('preparation',None);write(s)
 job=queue({'action':'claim'});check(job['release_id']==rid,'historical source-only draft queues automatically')
 # Failed attempts never become published, and repeated interruption is bounded.
 s=read();s['releases'][rid]['preparation']['attempts']=3;s['releases'][rid]['preparation']['started_at']=int(time.time())-400;write(s)
 check(queue({'action':'claim'}) is None and read()['releases'][rid]['preparation']['status']=='failed','three interrupted attempts stop for manual retry')
 php('(new MTX\\Preparation(new MTX\\App()))->retry("mtx-dfm-cn",$argv[1]);',rid)
 # Corrupt only the disposable stored fixture, not the supplied source.
 obj=storage/'objects'/r['source_sha256'];saved=obj.read_bytes();obj.write_bytes(saved[:-1])
 result=subprocess.run(['python3',str(ROOT/'preparer/worker.py'),'--php',PHP],env=env,cwd=ROOT,text=True,capture_output=True,timeout=130)
 failed=read()['releases'][rid]
 check(failed['state']=='draft' and failed['preparation']['status']=='failed','bad source digest stops before native processing')
 check(not list((storage/'preparation').glob('*-*')),'failed work directory removed')
 obj.write_bytes(saved)
 php('(new MTX\\Preparation(new MTX\\App()))->retry("mtx-dfm-cn",$argv[1]);',rid)
 job=queue({'action':'claim'});work=storage/'preparation'/(rid+'-'+job['token']);work.mkdir(mode=0o700)
 output=work/'payload.tar';output.symlink_to(storage/'objects'/r['artifact_sha256'])
 queue({'action':'finish','release_id':rid,'token':job['token'],'path':str(output)},False)
 check(read()['releases'][rid]['state']=='draft','symlink output is rejected without changing state')
 output.unlink();output.write_bytes(b'invalid tar')
 queue({'action':'finish','release_id':rid,'token':job['token'],'path':str(output)},False)
 check(read()['releases'][rid]['state']=='draft','invalid prepared output stays unpublished')
 s=read();s['releases'][rid]['state']='withdrawn';write(s)
 output.write_bytes((storage/'objects'/r['artifact_sha256']).read_bytes())
 queue({'action':'finish','release_id':rid,'token':job['token'],'path':str(output)},False)
 check(read()['releases'][rid]['state']=='withdrawn','late valid completion never resurrects withdrawn release')
print(f'{checks} automatic preparation checks passed; all temporary state removed.')
