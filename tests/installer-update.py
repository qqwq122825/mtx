#!/usr/bin/env python3
"""macOS native client test: synthetic bytes, temporary keys, loopback HTTP only.
No iOS code or installation/exploit methods are executed.
"""
import argparse, base64, hashlib, http.server, io, json, os, plistlib, secrets, shutil
import subprocess, tarfile, tempfile, threading, time, urllib.parse, zipfile
from pathlib import Path
from datetime import datetime, timezone
ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BINARY','php')
parser=argparse.ArgumentParser();parser.add_argument('--installer',required=True);args=parser.parse_args()
installer=Path(args.installer).resolve()
checks=0

def check(value,label):
    global checks
    assert value,label
    checks+=1;print('PASS',label,flush=True)

def sha(b): return hashlib.sha256(b).hexdigest()

def make_tar(source, traversal=False):
    files={'Main':b'SYNTHETIC DATA ONLY\n'*8192,'MTXMenuIcons.ttf':b'TEST FONT'}
    manifest=plistlib.dumps({'SchemaVersion':1,'BundleIdentifier':'com.mtx.scmtxdfm','SourceSHA256':sha(source), 'Version':'1.0','Files':{k:sha(v) for k,v in files.items()},'InfoOverrides':{}})
    files['Manifest.plist']=manifest
    out=io.BytesIO()
    with tarfile.open(fileobj=out,mode='w',format=tarfile.USTAR_FORMAT) as tar:
        for name,raw in files.items():
            member=tarfile.TarInfo('../Main' if traversal and name=='Main' else name);member.size=len(raw);tar.addfile(member,io.BytesIO(raw))
    return out.getvalue(),sha(manifest)

with tempfile.TemporaryDirectory(prefix='mtx-native-client-') as temp:
    t=Path(temp);config=t/'config.php'
    subprocess.run([PHP,'-r', 'require $argv[1]."/vendor/autoload.php";$k=MTX\\Security::nativeKeys();file_put_contents($argv[2],"<?php return ".var_export($k,true).";");echo $k["native_sign_public"];',str(ROOT),str(config)],check=True,stdout=(t/'pub').open('w'))
    header=t/'Remote.generated.h'
    values={'MTX_REMOTE_GAME_ID':1,'MTX_REMOTE_GAME_NAME':'满天星三角洲国服','MTX_REMOTE_UPDATE_URL':'https://fixture.invalid/r-fixture/api/update.php','MTX_REMOTE_APP_KEY':'mtx-dfm-cn','MTX_REMOTE_PROFILE':'mtx-dfm-remote-v1','MTX_REMOTE_BUNDLE_ID':'com.mtx.scmtxdfm','MTX_REMOTE_KEY_ID':'fixture-1','MTX_REMOTE_PUBLIC_KEY':(t/'pub').read_text()}
    header.write_text(''.join(f'#define {k} @{json.dumps(v)}\n' for k,v in values.items()))
    bridge=t/'Bridge.h';bridge.write_text(f'#define MTX_TESTING 1\n#import "{installer}/TrollInstallerX/Installer/MTXHostInstaller.h"\n')
    obj=t/'native.o'
    subprocess.run(['xcrun','clang','-c','-fobjc-arc','-DMTX_TESTING=1',f'-DMTX_REMOTE_CONFIG_HEADER="{header}"','-Wno-deprecated-declarations','-I',str(installer/'TrollInstallerX/Installer'),str(installer/'TrollInstallerX/Installer/MTXHostInstaller.m'),'-o',str(obj)],check=True)
    swift=t/'ClientTests.swift'
    swift.write_text(r'''
import Foundation
@main struct ClientTest {
 static func main() {
    let endpoint = URL(string:CommandLine.arguments[1])!, dir=URL(fileURLWithPath:CommandLine.arguments[2]), mode=CommandLine.arguments[3]
    let done = DispatchSemaphore(value:0)
    DispatchQueue.global().async {
        let suite="MTXFixture-"+UUID().uuidString, defaults=UserDefaults(suiteName:"MTXFixture-"+UUID().uuidString)!
        defaults.removePersistentDomain(forName:suite)
        if mode == "rollback" { defaults.set(2,forKey:"MTXRemoteMaxSequence") }
        if mode == "scoped_rollback" { defaults.set(2,forKey:"MTXRemoteMaxSequence.game.1") }
        if mode == "sibling_sequence" { defaults.set(999,forKey:"MTXRemoteMaxSequence.game.2") }
        var phases:[String]=[], byteEvents=0, ok=false, detail="", handoff=false
        do {
            let updater=MTXRemoteUpdater(endpoint:endpoint,directory:dir,defaults:defaults,progress:{ p in
                if phases.last != p.title { phases.append(p.title) }
                if p.total > 0 && p.received > 0 { byteEvents += 1 }
            })
            let payload=try updater.prepare()
            _ = try updater.prepare() // immutable files reused, new nonce responses required
            let stage=dir.appendingPathComponent("handoff")
            try FileManager.default.createDirectory(at:stage,withIntermediateDirectories:false)
            var error:NSError?
            guard MTXTestWritePayload(payload,stage.path,&error), MTXLoadStagedPayload(stage.path,&error) != nil else { fatalError("handoff validation failed") }
            let main=stage.appendingPathComponent("Main"), original=try Data(contentsOf:main)
            try Data("changed".utf8).write(to:main)
            guard MTXLoadStagedPayload(stage.path,&error) == nil else { fatalError("tampered staged file accepted") }
            try original.write(to:main)
            let envelope=stage.appendingPathComponent("RemoteRelease.json"), signed=try Data(contentsOf:envelope)
            try Data("{}".utf8).write(to:envelope)
            guard MTXLoadStagedPayload(stage.path,&error) == nil else { fatalError("bad envelope accepted") }
            try signed.write(to:envelope)
            let manifest=stage.appendingPathComponent("Manifest.plist"), originalManifest=try Data(contentsOf:manifest)
            var dict=try PropertyListSerialization.propertyList(from:originalManifest,format:nil) as! [String:Any]
            dict["Version"]="changed"
            try PropertyListSerialization.data(fromPropertyList:dict,format:.xml,options:0).write(to:manifest)
            guard MTXLoadStagedPayload(stage.path,&error) == nil else { fatalError("changed manifest accepted") }
            try originalManifest.write(to:manifest)
            try FileManager.default.removeItem(at:envelope)
            guard MTXLoadStagedPayload(stage.path,&error) == nil else { fatalError("missing remote credential accepted") }
            handoff=true;ok=true
        } catch { detail=error.localizedDescription }
        defaults.removePersistentDomain(forName:suite)
        let result:[String:Any] = ["ok":ok,"detail":detail,"phases":phases,"byte_events":byteEvents,"handoff":handoff]
        print(String(data:try! JSONSerialization.data(withJSONObject:result,options:.sortedKeys),encoding:.utf8)!)
        done.signal()
    }
    if done.wait(timeout:.now()+60) != .success { fatalError("client test timeout") }
 }
}
'''.replace('let suite="MTXFixture-"+UUID().uuidString, defaults=UserDefaults(suiteName:"MTXFixture-"+UUID().uuidString)!','let suite="MTXFixture-"+UUID().uuidString\n        let defaults=UserDefaults(suiteName:suite)!'))
    exe=t/'client'
    subprocess.run(['xcrun','--toolchain','com.apple.dt.toolchain.XcodeDefault','swiftc','-D','MTX_REMOTE_TESTING','-import-objc-header',str(bridge),str(swift),str(installer/'TrollInstallerX/Installer/MTXRemoteUpdater.swift'),str(obj),'-framework','Foundation','-framework','Security','-o',str(exe)],check=True)
    source_buf=io.BytesIO()
    with zipfile.ZipFile(source_buf,'w') as z:z.writestr('Payload/Fixture.app/Fixture',b'SYNTHETIC, NOT EXECUTABLE\n'*10000)
    source=source_buf.getvalue();normal_tar,manifest_sha=make_tar(source)
    mode='success';calls=[];update_calls=0
    def sign(payload):
        raw=json.dumps(payload,ensure_ascii=False,separators=(',',':')).encode()
        sig=subprocess.check_output([PHP,'-r','$c=require $argv[1];$r=stream_get_contents(STDIN);openssl_sign($r,$s,$c["native_sign_secret"],OPENSSL_ALGO_SHA256);echo base64_encode($s);',str(config)],input=raw).decode()
        return json.dumps({'key_id':'fixture-1','payload_base64':base64.b64encode(raw).decode(),'native_signature_base64':sig if mode!='bad_signature' else base64.b64encode(b'bad').decode()}).encode()
    class Handler(http.server.BaseHTTPRequestHandler):
        def log_message(self,*a): pass
        def send(self,raw,status=200,headers=None):
            self.send_response(status);self.send_header('Content-Length',str(len(raw)))
            for k,v in (headers or {}).items():self.send_header(k,v)
            self.end_headers()
            try:
                for i in range(0,len(raw),16384):self.wfile.write(raw[i:i+16384]);self.wfile.flush()
            except (BrokenPipeError,ConnectionResetError):pass
        def do_GET(self):
            global update_calls
            p=urllib.parse.urlparse(self.path);q=urllib.parse.parse_qs(p.query)
            calls.append(p.path)
            if p.path.endswith('update.php'):
                if q.get('game_id')!=['1'] or 'app' in q: self.send(b'wrong route',422);return
                update_calls+=1
                tar=make_tar(source,True)[0] if mode=='traversal' else normal_tar
                release={'release_id':('b' if mode=='switched' and update_calls>=2 else 'a')*32,'sequence':1,'bundle_id':'com.mtx.scmtxdfm','profile_id':'mtx-dfm-remote-v1','artifact_format':'prepared-payload-v1','artifact_sha256':sha(tar),'artifact_bytes':len(tar),'source_sha256':sha(source),'source_bytes':len(source),'manifest_sha256': '0'*64 if mode=='manifest_hash' else manifest_sha,'min_ios':'14.0.0','max_ios':'16.6.1','min_installer_version':'0.8.0'}
                now=time.time();stamp=lambda n:datetime.fromtimestamp(n,timezone.utc).isoformat(timespec='seconds')
                self.send(sign({'schema_version':1,'game_id':(2 if mode=='wrong_id' else True if mode=='boolean_id' else 1),'app_key':'wrong' if mode=='wrong_game' else 'mtx-dfm-cn','nonce':'wrong' if mode=='wrong_nonce' else q['nonce'][0],'issued_at':stamp(now-700 if mode=='expired' else now),'expires_at':stamp(now-100 if mode=='expired' else now+600),'status':'no_release' if mode=='no_release' else 'version_unknown','release':None if mode=='no_release' else release}))
            elif p.path.endswith('download.php'):
                is_source=q['sha'][0]==sha(source)
                raw=source if is_source else (make_tar(source,True)[0] if mode=='traversal' else normal_tar)
                if is_source and mode=='source_corrupt': raw=b'x'+raw[1:]
                if is_source and mode=='source_short': raw=raw[:-1]
                self.send(raw)
            else:self.send(b'',404)
        def do_POST(self):
            data=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
            if data.get('game_id')!='1' or 'app_key' in data: self.send(b'wrong route',422);return
            calls.append('ticket:'+data['artifact_id'])
            if mode=='redirect':self.send(b'',302,{'Location':self.path});return
            url=f'http://127.0.0.1:{server.server_port}/r-test/api/download.php?sha='+data['artifact_id']
            if mode=='external_ticket':url='https://other.invalid/download.php'
            self.send(json.dumps(data|{'game_id':2 if mode=='wrong_ticket_id' else 1,'app_key':'mtx-dfm-cn','url':url}).encode())
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    try:
        for mode in ['success','sibling_sequence','scoped_rollback','source_corrupt','source_short','bad_signature','wrong_id','boolean_id','wrong_ticket_id','wrong_nonce','wrong_game','expired','no_release','redirect','external_ticket','manifest_hash','traversal','rollback','switched']:
            calls=[];update_calls=0
            result=subprocess.run([str(exe),f'http://127.0.0.1:{server.server_port}/r-test/api/update.php',str(t/mode),mode],capture_output=True,text=True,timeout=65)
            check(result.returncode==0,'native process completed: '+mode+' '+result.stderr[:200])
            report=json.loads(result.stdout.strip().splitlines()[-1])
            check(report['ok']==(mode in ['success','sibling_sequence']),'native validation result: '+mode+' '+report['detail'])
            if mode in ['success','sibling_sequence']:
                check(report['phases'].index('正在下载 TIPA')<report['phases'].index('正在下载安装资源'),'source TIPA precedes prepared resource')
                check(report['byte_events']>=4,'byte progress delivered')
                check((t/mode/(sha(source)+'.tipa')).read_bytes()==source,'downloaded local TIPA exact bytes')
                check(len([c for c in calls if c.endswith('download.php')])==2,'second prepare uses verified local cache')
                check(report['handoff'],'helper handoff revalidates signature, raw manifest, files and missing envelope')
            else:
                check(not list((t/mode).glob('*.part')),'partial file cleanup: '+mode)
                if mode in ['source_corrupt','source_short']:
                    check(not any(c=='ticket:'+sha(normal_tar) for c in calls),'failed source stops before artifact: '+mode)
    finally: server.shutdown();server.server_close()
print(f'{checks} native client checks passed; keys and fixtures removed.')
