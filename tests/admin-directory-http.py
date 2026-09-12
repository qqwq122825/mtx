#!/usr/bin/env python3
"""Rename a copied public/admin while a real PHP server keeps running. No live project/data moves."""
import base64, http.cookiejar, io, json, os, plistlib, re, secrets, shutil, signal, socket, subprocess, tempfile, time, urllib.error, urllib.parse, urllib.request, zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BINARY','php');count=0
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None
def check(ok,label):
    global count
    assert ok,label
    count+=1;print('PASS',label,flush=True)
with tempfile.TemporaryDirectory(prefix='mtx-admin-folder-') as t:
    t=Path(t);root=t/'project';root.mkdir()
    for name in ['src','public','bin','vendor']:shutil.copytree(ROOT/name,root/name)
    public=root/'public';folder='admin';mount='/r-api-fixture-0123456789';password=secrets.token_hex(16)
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    origin=f'http://127.0.0.1:{port}';config=t/'config.php';storage=t/'storage';log=(t/'server.log').open('w+')
    result=subprocess.run([PHP,str(root/'bin/setup.php'),'--url',origin,'--mount',mount,'--local-http','--password-stdin','--config',str(config),'--storage',str(storage)],input=(password+'\n').encode(),capture_output=True,check=True)
    check((origin+'/admin/').encode() in result.stdout,'setup reports default /admin/ URL')
    env=os.environ|{'MTX_CONFIG':str(config)};proc=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(public),str(public/'router.php')],cwd=root,env=env,stdout=log,stderr=log,start_new_session=True)
    jar=http.cookiejar.CookieJar();opener=urllib.request.build_opener(NoRedirect(),urllib.request.HTTPCookieProcessor(jar))
    def request(path,fields=None,headers=None,body=None):
        headers=dict(headers or {})
        if fields is not None:body=urllib.parse.urlencode(fields).encode();headers['Content-Type']='application/x-www-form-urlencoded'
        req=urllib.request.Request(origin+path,data=body,headers=headers)
        try:r=opener.open(req,timeout=15)
        except urllib.error.HTTPError as e:r=e
        return r.status,r.read(),dict(r.headers)
    def csrf():return re.search(rb'name="csrf" value="([^"]+)"',request('/'+folder+'/login.php')[1])[1].decode()
    def login():return request('/'+folder+'/login.php',fields={'csrf':csrf(),'password':password})
    try:
        for _ in range(50):
            try:request('/admin/login.php');break
            except urllib.error.URLError:time.sleep(.1)
        check(request('/admin/')[2].get('Location')=='/admin/login.php','default admin redirects to default login')
        check(login()[2].get('Location')=='/admin/' and request('/admin/')[0]==200,'default password login succeeds')
        check(any(c.path=='/admin' for c in jar),'default cookie scoped to physical folder')
        api=mount+'/api/update.php?'+urllib.parse.urlencode({'game_id':1,'nonce':secrets.token_urlsafe(32),'installer_version':'0.8.3','os_version':'16.1.2','profile':'mtx-dfm-remote-v1'});before=request(api)
        check(before[0]==200 and json.loads(base64.b64decode(json.loads(before[1])['payload_base64']))['status']=='no_release','fixed update API initially has no release')
        original_config=config.read_bytes()
        (public/'admin').rename(public/'console_random_123');folder='console_random_123'
        for old in ['/admin','/admin/','/admin/login.php',mount+'/admin/',mount, mount+'/']:
            code,body,headers=request(old);check(code==404 and 'Location' not in headers and folder.encode() not in body,'old URL never redirects/reveals new folder: '+old)
        check(request('/'+folder+'/')[2].get('Location')=='/'+folder+'/login.php','renamed folder immediately recognized without server restart')
        code,page,_=request('/'+folder+'/login.php');check(code==200 and ('action="/'+folder+'/login.php"').encode() in page,'renamed login form has updated action')
        check(login()[2].get('Location')=='/'+folder+'/' and request('/'+folder+'/')[0]==200,'new folder password login works')
        check(any(c.path=='/'+folder for c in jar),'renamed session cookie uses new path')
        page=request('/'+folder+'/')[1];token=re.search(rb'name="csrf" value="([^"]+)"',page)[1].decode()
        for file in ['telegram.php','telegram-announcements.php']:
            code,body,_=request('/'+folder+'/'+file);check(code==200 and ('/'+folder+'/').encode() in body and (mount+'/admin/').encode() not in body,'bot page and links follow rename: '+file)
        check(request('/'+folder+'/action.php',fields={'action':'toggle','game_id':'1','csrf':'bad','revision':'0'})[0]==403,'renamed admin actions retain CSRF protection')
        # Upload a tiny synthetic TIPA through the renamed action and verify its XHR redirect.
        game=next(iter(json.loads((storage/'state.json').read_text())['apps'].values()))
        payload=io.BytesIO()
        with zipfile.ZipFile(payload,'w') as z:
            z.writestr('Payload/Fixture.app/Info.plist',plistlib.dumps({'CFBundleIdentifier':game['bundle_id'],'CFBundleName':'Fixture','CFBundleShortVersionString':'1.0','CFBundleVersion':'1','CFBundleExecutable':'Fixture','MinimumOSVersion':'14.0'}))
            z.writestr('Payload/Fixture.app/Fixture',b'SYNTHETIC FIXTURE, NOT EXECUTABLE')
        boundary='mtx'+secrets.token_hex(12);parts=[]
        for k,v in {'csrf':token,'action':'upload','game_id':'1','changelog':''}.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
        parts += [f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="fixture.tipa"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode(),payload.getvalue(),f'\r\n--{boundary}--\r\n'.encode()]
        code,body,_=request('/'+folder+'/action.php',body=b''.join(parts),headers={'Content-Type':'multipart/form-data; boundary='+boundary,'X-MTX-Upload':'1'})
        check(code==200 and json.loads(body)['redirect']=='/'+folder+'/?game_id=1','TIPA upload and progress-controller redirect work after folder rename')
        check(request(api)[0]==before[0] and config.read_bytes()==original_config,'admin rename/upload leaves fixed API address and keys untouched')
        for file in ['app.css','app.js','telegram-announcements.js']:
            check(request(mount+'/assets/'+file)[0]==200,'shared asset URL remains valid: '+file)
        for path in ['/'+folder+'/.mtx-admin','/'+folder+'/other.php','/'+folder+'/../config.local.php','/config.local.php','/src/App.php','/storage/state.json','/admin%2flogin.php']:
            check(request(path)[0]==404,'non-allowlisted path not served: '+path)
        (public/'linked_console').symlink_to(public/folder,target_is_directory=True)
        check(request('/linked_console/login.php')[0]==404,'symlink is not an additional backend')
        (public/'linked_console').unlink()
        shutil.copytree(public/folder,public/'duplicate_console')
        code,body,_=request('/'+folder+'/login.php');check(code==503 and b'Fatal error' not in body,'duplicate backend folders fail closed without recursive error handler')
        check(request(api)[0]==before[0],'duplicate backend does not interrupt installer API')
        shutil.rmtree(public/'duplicate_console')
        (public/folder).rename(public/'admin');folder='admin'
        check(request('/admin/login.php')[0]==303 and request('/console_random_123/login.php')[0]==404,'renaming back restores default entry without alias')
        # Validate the generic Nginx page rule mirrors supported folder and handler shapes.
        nginx=(ROOT/'deploy/nginx.conf.example').read_text();pattern=re.search(r'location ~ "([^"]+)"',nginx)[1]
        check(all(re.fullmatch(pattern,p) for p in ['/admin','/admin/','/console_random_123/login.php','/CONSOLE-9/telegram-announcements.php']),'Nginx template matches renamed page shapes')
        check(all(not re.fullmatch(pattern,p) for p in ['/admin/.mtx-admin','/admin/secret.php','/admin/sub/login.php','/admin/loginXphp']), 'Nginx template only matches allowlisted page files')
        print(f'\n{count} admin-folder HTTP checks passed. Only a disposable copy was renamed.')
    finally:os.killpg(proc.pid,signal.SIGTERM);proc.wait(timeout=5);log.close()
