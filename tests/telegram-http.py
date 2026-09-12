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
        req=urllib.request.Request(path if path.startswith('http') else (origin+path if path.startswith('/admin/') else base+path),data=body,headers=headers,method=method)
        try:r=opener.open(req,timeout=10)
        except urllib.error.HTTPError as e:r=e
        return r.status,r.read(),dict(r.headers)
    def csrf(path='/admin/telegram.php'):return re.search(rb'name="csrf" value="([^"]+)"',request(path)[1])[1].decode()
    try:
        for _ in range(50):
            try:request('/admin/login.php');break
            except urllib.error.URLError:time.sleep(.1)
        check(request('/admin/telegram.php')[0]==303,'bot panel requires password session')
        check(request('/admin/telegram-announcements.php')[0]==303,'announcement panel requires password session')
        check(request(base+'/admin/telegram-announcements.php')[0]==404,'old prefixed announcement route removed')
        check(request('/bin/telegram-tick.php')[0]==404,'scheduler is CLI-only, no public trigger')
        check(request('/admin/telegram.php',fields={'action':'save'})[0]==303,'unauthenticated bot changes require login')
        for path in [mount+'/admin/telegram.php','/api/telegram-webhook.php','/storage/telegram/state.json']:
            check(request(origin+path)[0]==404,'bot routes hidden outside mount: '+path)
        login=csrf('/admin/login.php');check(request('/admin/login.php',fields={'csrf':login,'password':password})[0]==303,'password login')
        code,html,headers=request('/admin/telegram.php');check(code==200 and headers.get('Cache-Control')=='no-store','bot panel is private and not cached')
        check(b'type="password"' in html and b'name="admin_id"' in html and b'/admin/telegram.php' in html,'password Token input and prefixed action')
        check(request('/admin/telegram.php',fields={'csrf':'bad','action':'save','token':'bad','admin_id':'1'})[0]==403,'bot configuration CSRF enforced')
        ann='/admin/telegram-announcements.php'
        code,page,headers=request(ann)
        check(code==200 and headers.get('Cache-Control')=='no-store' and b'announcement-preview' in page,'announcement editor has uncached preview')
        check(b'iosAs' not in page and b'name="schedule_enabled" value="1" checked' not in page and b'name="delete_enabled" value="1" checked' not in page,'third-party accounts absent and both timers default off')
        check(request('/assets/telegram-announcements.js')[0]==200,'preview script served under mount')
        check(request(ann,fields={'csrf':'bad','action':'pause'})[0]==403,'announcement actions require CSRF')
        draft={'text':'**Title** <script>not-executed</script>','target':'@TestChannel','contact':'','bot_contact':'','button1_text':'Card','button1_url':'https://example.com','button2_text':'','button2_url':'','interval_minutes':'5','delete_minutes':'60','schedule_mode':'interval'}
        check(request(ann,fields=draft|{'csrf':csrf(ann),'action':'save'})[0]==303,'save announcement draft through real HTTP')
        page=request(ann)[1]
        check(b'<b>Title</b>' in page and b'&lt;script&gt;not-executed&lt;/script&gt;' in page and b'<script>not-executed</script>' not in page,'server preview formats allowed emphasis and escapes HTML')
        check(request(ann,fields=draft|{'csrf':csrf(ann),'action':'save','target':'12345'})[0]==200 and json.loads((storage/'telegram/state.json').read_text())['announcements']['card']['target']=='@TestChannel','invalid target never replaces stored draft')
        page=request(ann,fields={'csrf':csrf(ann),'action':'test','request_id':'a'*32})[1]
        check('请先'.encode() in page and not json.loads((storage/'telegram/state.json').read_text())['announcements']['jobs'],'unconfigured test stops before network')
        reply_draft={'welcome':'你好，满天星 <script>not-executed</script>', 'consult':'项目咨询', 'install':'安装帮助', 'support':'售后反馈', 'received':'消息已收到'}
        check(request('/admin/telegram.php',fields=reply_draft|{'csrf':'bad','action':'replies'})[0]==403,'auto-reply changes require CSRF')
        check(request('/admin/telegram.php',fields=reply_draft|{'csrf':csrf(),'action':'replies'})[0]==303,'save auto-reply draft through HTTP without sending messages')
        page=request('/admin/telegram.php')[1]
        check(b'&lt;script&gt;not-executed&lt;/script&gt;' in page and b'<script>not-executed</script>' not in page,'reply editor escapes custom text')
        token='123456:'+secrets.token_urlsafe(32)
        check(request('/admin/telegram.php',fields={'csrf':csrf(),'action':'save','token':token,'admin_id':'77777'})[0]==303,'save fake bot configuration via POST')
        statepath=storage/'telegram/state.json';state=json.loads(statepath.read_text());secret=state['settings']['secret']
        check(state['announcements']['card']['target']=='@TestChannel','initial bot binding preserves prepared announcement draft')
        check(state['auto_replies']==reply_draft,'initial bot binding preserves welcome drafts')
        check(token.encode() not in request(ann)[1] and secret.encode() not in request(ann)[1],'announcement page never exposes bot secrets')
        request(ann,fields=draft|{'csrf':csrf(ann),'action':'save','schedule_enabled':'1'})
        check(not json.loads(statepath.read_text())['announcements']['card']['schedule_enabled'],'paused bot cannot enable announcement schedule')
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
        before=json.loads(statepath.read_text());edited=reply_draft|{'welcome':'你好，这里是满天星 ✨'}
        check(request('/admin/telegram.php',fields=edited|{'csrf':csrf(),'action':'replies','token':'ignored','admin_id':'99999'})[0]==303,'edit auto-replies while bot remains enabled')
        current=json.loads(statepath.read_text())
        check(current['auto_replies']==edited and current['settings']==before['settings'] and current['announcements']==before['announcements'] and current['routes']==before['routes'],'reply edit preserves credentials, routes and announcement draft')
        for bad in ['', '字'*1001]:
            request('/admin/telegram.php',fields=edited|{'welcome':bad,'csrf':csrf(),'action':'replies'})
            check(json.loads(statepath.read_text())['auto_replies']==edited,'invalid template leaves configured replies intact')
        request('/admin/telegram.php',fields={'csrf':csrf(),'action':'save','token':token,'admin_id':'88888'})
        check(json.loads(statepath.read_text())['settings']['admin_id']==77777,'active binding change rejected over HTTP')
        log.flush();check(token not in (t/'server.log').read_text() and secret not in (t/'server.log').read_text(),'server log contains no bot credentials')
        state=json.loads(statepath.read_text());state['announcements']['card']['schedule_enabled']=True;state['announcements']['next_at']=int(time.time())+3600;statepath.write_text(json.dumps(state))
        check(request(ann,fields={'csrf':csrf(ann),'action':'pause'})[0]==303 and not json.loads(statepath.read_text())['announcements']['card']['schedule_enabled'],'pause schedule through authenticated HTTP')
        check(request(ann,method='PUT')[0]==405,'announcement mutations accept POST only')
        print(f'\n{checks} Telegram HTTP checks passed. No external requests.')
    finally:os.killpg(proc.pid,signal.SIGTERM);proc.wait(timeout=5);log.close()
