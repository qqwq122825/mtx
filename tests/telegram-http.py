#!/usr/bin/env python3
"""Real local HTTP/CSRF/routes; fake credentials only, no Telegram API calls."""
import http.cookiejar,json,os,re,secrets,signal,socket,subprocess,tempfile,time,urllib.request,urllib.error,urllib.parse
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BINARY','php');checks=0
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None
def check(ok,label):
    global checks
    assert ok,label
    checks+=1;print('PASS',label,flush=True)
with tempfile.TemporaryDirectory(prefix='mtx-telegram-http-') as t:
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
        req=urllib.request.Request(path if path.startswith('http') else base+path,data=body,headers=headers,method=method)
        try:r=opener.open(req,timeout=10)
        except urllib.error.HTTPError as e:r=e
        return r.status,r.read(),dict(r.headers)
    def csrf(path='/admin/telegram.php'):return re.search(rb'name="csrf" value="([^"]+)"',request(path)[1])[1].decode()
    try:
        for _ in range(50):
            try:request('/admin/login.php');break
            except urllib.error.URLError:time.sleep(.1)
        check(request('/admin/telegram.php')[0]==303,'bot panel requires password session')
        check(request('/admin/telegram.php',fields={'action':'save'})[0]==303,'unauthenticated bot changes require login')
        for path in ['/admin/telegram.php','/api/telegram-webhook.php','/storage/telegram/state.json']:
            check(request(origin+path)[0]==404,'bot routes hidden outside mount: '+path)
        login=csrf('/admin/login.php');check(request('/admin/login.php',fields={'csrf':login,'password':password})[0]==303,'password login')
        code,html,headers=request('/admin/telegram.php');check(code==200 and headers.get('Cache-Control')=='no-store','bot panel is private and not cached')
        check(b'type="password"' in html and b'name="admin_id"' in html and (mount+'/admin/telegram.php').encode() in html,'password Token input and prefixed action')
        check(request('/admin/telegram.php',fields={'csrf':'bad','action':'save','token':'bad','admin_id':'1'})[0]==403,'bot configuration CSRF enforced')
        token='123456:'+secrets.token_urlsafe(32)
        check(request('/admin/telegram.php',fields={'csrf':csrf(),'action':'save','token':token,'admin_id':'77777'})[0]==303,'save fake bot configuration via POST')
        statepath=storage/'telegram/state.json';state=json.loads(statepath.read_text());secret=state['settings']['secret']
        page=request('/admin/telegram.php')[1];check(token.encode() not in page and secret.encode() not in page and b'value="77777"' in page,'neither Token nor webhook secret reflected in HTML')
        check(not state['settings']['enabled'] and state['settings']['token']==token,'settings saved private and paused')
        for path in ['/storage/telegram/state.json','/telegram/state.json','/src/TelegramBot.php']:
            check(request(path)[0]==404,'private Telegram files not served: '+path)
        check(request('/api/telegram-webhook.php')[0]==405,'webhook accepts POST only')
        hook='/api/telegram-webhook.php'
        h={'Content-Type':'application/json','X-Telegram-Bot-Api-Secret-Token':secret}
        check(request(hook,body=b'{"update_id":1}',headers={'Content-Type':'application/json'})[0]==403,'webhook requires secret header')
        check(request(hook,body=b'{"update_id":1}',headers=h|{'X-Telegram-Bot-Api-Secret-Token':'wrong'})[0]==403,'wrong secret rejected')
        check(request(hook,body=b'{"update_id":1}',headers=h)[0]==200,'paused authenticated webhook acknowledged without dispatch')
        check(request(hook,body=b'bad',headers=h)[0]==422,'invalid webhook JSON rejected')
        check(request(hook,body=b'[]',headers=h)[0]==422,'webhook requires object')
        check(request(hook,body=b'{"update_id":"1"}',headers=h)[0]==422,'webhook update ID type checked')
        check(request(hook,body=b'{}',headers=h|{'Content-Type':'text/plain'})[0]==415,'webhook content type checked')
        check(request(hook,body=b' '*262145,headers=h)[0]==413,'webhook body bounded at 256 KiB')
        request('/admin/telegram.php',fields={'csrf':csrf(),'action':'connect'})
        page=request('/admin/telegram.php')[1];check('公网 HTTPS'.encode() in page and not json.loads(statepath.read_text())['settings']['enabled'],'local connect blocked before any Telegram call')
        state['settings']['enabled']=True;statepath.write_text(json.dumps(state))
        update={'update_id':2,'message':{'chat':{'type':'group','id':-123},'from':{'id':77777,'is_bot':False},'message_id':4,'date':int(time.time()),'text':'MUST_NOT_BE_SENT'}}
        check(request(hook,body=json.dumps(update).encode(),headers=h)[0]==200,'authenticated group update ignored over real HTTP')
        check(not json.loads(statepath.read_text())['updates'],'ignored group creates no relay job')
        request('/admin/telegram.php',fields={'csrf':csrf(),'action':'save','token':token,'admin_id':'88888'})
        check(json.loads(statepath.read_text())['settings']['admin_id']==77777,'active binding change rejected over HTTP')
        log.flush();check(token not in (t/'server.log').read_text() and secret not in (t/'server.log').read_text(),'server log contains no bot credentials')
        print(f'\n{checks} Telegram HTTP checks passed. No external requests.')
    finally:os.killpg(proc.pid,signal.SIGTERM);proc.wait(timeout=5);log.close()
