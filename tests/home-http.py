#!/usr/bin/env python3
"""Real local HTTP/CSRF/routes; fake credentials only, no Telegram API calls."""
import io,zipfile,http.cookiejar,json,os,re,secrets,signal,socket,subprocess,tempfile,time,urllib.request,urllib.error,urllib.parse
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BINARY','php');checks=0
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None
def check(ok,label):
    global checks
    assert ok,label
    checks+=1;print('PASS',label,flush=True)
with tempfile.TemporaryDirectory(prefix='mtx-home-http-') as t:
    t=Path(t)
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    origin=f'http://127.0.0.1:{port}';mount='/r-telegram-http-fixture-1234';base=origin+mount;password=secrets.token_hex(16)
    config=t/'config.php';storage=t/'storage';log=(t/'server.log').open('w+')
    subprocess.run([PHP,str(ROOT/'bin/setup.php'),'--url',origin,'--local-http','--mount',mount,'--config',str(config),'--storage',str(storage),'--password-stdin'],input=(password+'\n').encode(),check=True,capture_output=True)
    env=os.environ|{'MTX_CONFIG':str(config)}
    proc=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(ROOT/'public'),str(ROOT/'public/router.php')],env=env,cwd=ROOT,stdout=log,stderr=log,start_new_session=True)
    opener=urllib.request.build_opener(NoRedirect(),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(path,fields=None,body=None,headers=None,method=None):
        headers=dict(headers or {})
        if fields is not None:body=urllib.parse.urlencode(fields).encode();headers['Content-Type']='application/x-www-form-urlencoded'
        req=urllib.request.Request(path if path.startswith('http') else (origin+path if path.startswith('/admin/') else base+path),data=body,headers=headers,method=method)
        try:r=opener.open(req,timeout=10)
        except urllib.error.HTTPError as e:r=e
        return r.status,r.read(),dict(r.headers)
    def csrf(path='/admin/telegram.php'):return re.search(rb'name="csrf" value="([^"]+)"',request(path)[1])[1].decode()
    try:
        for _ in range(50):
            try:request('/admin/login.php');break
            except urllib.error.URLError:time.sleep(.1)

        check(request(origin+'/')[0]==200,'public homepage available')
        check(b'idatariver.com/zh-cn/m/681c1436f3fead534d87b1ba?t=default' in request(origin+'/')[1],'default buy destination')
        check(b'/admin' not in request(origin+'/')[1],'home does not reveal admin')
        check(request(origin+'/home.css')[0]==200,'home stylesheet served')
        check(request('/admin/home.php')[0]==303,'settings require login')
        token=csrf('/admin/login.php')
        check(request('/admin/login.php',fields={'csrf':token,'password':password})[0]==303,'login')
        token=csrf('/admin/home.php')
        check(request('/admin/home.php',fields={'csrf':'bad'})[0]==403,'CSRF required')
        for url in ['javascript:alert(1)','http://example.com','https://u:p@example.com']:
            check(request('/admin/home.php',fields={'csrf':token,'revision':'0','buy_url':url})[0]==422,'reject unsafe link')
        check(request('/admin/home.php',fields={'csrf':token,'revision':'0','buy_url':'https://example.com/buy?a=1&b=2','download_url':'https://example.com/installer.tipa'})[0]==303,'save links')
        check(b'https://example.com/buy?a=1&amp;b=2' in request(origin+'/')[1],'escaped saved link on home')
        check(request('/admin/home.php',fields={'csrf':token,'revision':'0','buy_url':''})[0]==409,'stale settings rejected')
        payload=io.BytesIO()
        with zipfile.ZipFile(payload,'w') as z:z.writestr('Payload/Fixture.app/Info.plist','<plist/>')
        def upload(data,game='1',name='fixture.tipa'):
            boundary='----MTXHomeFixture'
            parts=[]
            for k,v in {'csrf':token,'action':'upload','game_id':game}.items():
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="installer"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+data+f'\r\n--{boundary}--\r\n'.encode())
            return request('/admin/home.php',body=b''.join(parts),headers={'Content-Type':'multipart/form-data; boundary='+boundary})
        check(upload(b'bad')[0]==422,'invalid archive rejected')
        check(upload(payload.getvalue())[0]==303,'upload game installer')
        check(b'/installer?game_id=1' in request(origin+'/?view=downloads')[1],'per game download on list')
        code,body,headers=request(origin+'/installer?game_id=1')
        check(code==200 and body==payload.getvalue() and 'attachment' in headers['Content-Disposition'],'public download bytes match installer')
        check(request(origin+'/installer?game_id=999')[0] in (404,422),'unknown game rejected')
        check(request(origin+'/installer?game_id=1',method='HEAD')[1]==b'','HEAD has no body')
        check(request(origin+'/storage/state.json')[0]==404,'private storage remains inaccessible')
        print(f'{checks} homepage checks passed')
    finally:
        os.killpg(proc.pid,signal.SIGTERM);proc.wait(timeout=5);log.close()
