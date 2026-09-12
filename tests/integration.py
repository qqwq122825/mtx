#!/usr/bin/env python3
"""HTTP integration tests. Only generated fixtures; disposable server state; no external calls."""
import base64, concurrent.futures, hashlib, html, http.cookiejar, io, json, os
from pathlib import Path
import plistlib, re, secrets, signal, socket, subprocess, sys, tarfile, tempfile, time
import urllib.error, urllib.parse, urllib.request, zipfile

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')
PASSWORD = 'fixture-password-2026'
passed = []

def check(condition, label):
    if not condition: raise AssertionError(label)
    passed.append(label)
    print('PASS', label, flush=True)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl): return None

class Client:
    def __init__(self, base):
        self.base = base
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(NoRedirect, urllib.request.HTTPCookieProcessor(self.cookies))
    def request(self, path, fields=None, file=None, body=None, headers=None, method=None):
        headers = dict(headers or {})
        if file is not None:
            filename, raw = file
            boundary = '----mtx' + secrets.token_hex(16)
            parts = []
            for key, value in (fields or {}).items():
                parts += [f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()]
            parts += [f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode(), raw, f'\r\n--{boundary}--\r\n'.encode()]
            body = b''.join(parts)
            headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
            headers['X-MTX-Upload'] = '1'
        elif fields is not None:
            body = urllib.parse.urlencode(fields).encode()
            headers['Content-Type'] = 'application/x-www-form-urlencoded'
        parsed = urllib.parse.urlparse(self.base)
        origin = parsed.scheme + '://' + parsed.netloc
        if path.startswith('http'): url = path
        elif path == '/admin' or path.startswith('/admin/'): url = origin + path
        elif parsed.path and path.startswith(parsed.path+'/'): url = origin + path
        else: url = self.base + path
        req = urllib.request.Request(url, data=body, headers=headers, method=method)
        try: response = self.opener.open(req, timeout=30)
        except urllib.error.HTTPError as exc: response = exc
        return response.code, response.read(), dict(response.headers)
    def csrf(self):
        status, data, _ = self.request('/admin/')
        if status == 303: status, data, _ = self.request('/admin/login.php')
        match = re.search(r'name="csrf" value="([^"]+)"', data.decode())
        assert match, (status, data[:500])
        return html.unescape(match[1])
    def action(self, action, game_id=1, file=None, **fields):
        values = {'csrf': self.csrf(), 'action': action, **fields}
        if action != 'logout': values['game_id']=game_id
        return self.request('/admin/action.php', fields=values, file=file)

def make_tipa(build='1', bundle='com.mtx.scmtxdfm', binary=True, extra=None, minimum='14.0'):
    info = {'CFBundleIdentifier': bundle, 'CFBundleName': 'Fixture', 'CFBundleDisplayName': '测试游戏', 'CFBundleShortVersionString': '1.0', 'CFBundleVersion': build, 'MinimumOSVersion': minimum, 'CFBundleExecutable': 'Fixture'}
    out = io.BytesIO()
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
        z.writestr('Payload/Fixture.app/Info.plist', plistlib.dumps(info, fmt=plistlib.FMT_BINARY if binary else plistlib.FMT_XML))
        z.writestr('Payload/Fixture.app/Fixture', b'SYNTHETIC FIXTURE - NOT EXECUTABLE\n' * 8)
        if extra: z.writestr(*extra)
    return out.getvalue()

def prepared(source, bundle='com.mtx.scmtxdfm', bad=False):
    files = {'Main': b'SYNTHETIC PREPARED CONTENT - NOT EXECUTABLE', 'MTXMenuIcons.ttf': b'fixture-font'}
    manifest = {'SchemaVersion': 1, 'BundleIdentifier': bundle, 'Version': '1.0', 'SourceSHA256': hashlib.sha256(source).hexdigest(), 'Files': {n: hashlib.sha256(raw).hexdigest() for n, raw in files.items()}, 'InfoOverrides': {}}
    if bad: manifest['Files']['Main'] = '0' * 64
    files['Manifest.plist'] = plistlib.dumps(manifest)
    out = io.BytesIO()
    with tarfile.open(fileobj=out, mode='w', format=tarfile.USTAR_FORMAT) as tar:
        for name, raw in files.items():
            item = tarfile.TarInfo(name); item.size = len(raw); tar.addfile(item, io.BytesIO(raw))
    return out.getvalue()

with tempfile.TemporaryDirectory(prefix='mtx-tests-') as tmp:
    tmp = Path(tmp)
    with socket.socket() as sock: sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    origin = f'http://127.0.0.1:{port}'
    mount = '/r-fixture-private-0123456789'
    base = origin + mount
    config = tmp/'config.php'; storage = tmp/'storage'
    setup = subprocess.run([PHP, str(ROOT/'bin/setup.php'), '--url', origin, '--mount', mount, '--local-http', '--password-stdin', '--config', str(config), '--storage', str(storage)], input=(PASSWORD+'\n').encode(), capture_output=True)
    check(setup.returncode == 0, 'setup without database: ' + setup.stderr.decode())
    again = subprocess.run([PHP, str(ROOT/'bin/setup.php'), '--url', origin, '--mount', mount, '--local-http', '--password-stdin', '--config', str(config), '--storage', str(storage)], input=(PASSWORD+'\n').encode(), capture_output=True)
    check(again.returncode != 0, 'setup never overwrites configuration')
    state = lambda: json.loads((storage/'state.json').read_text())
    env = os.environ | {'MTX_CONFIG': str(config), 'PHP_CLI_SERVER_WORKERS': '4'}
    log = (tmp/'server.log').open('w+')
    proc = subprocess.Popen([PHP,'-d','upload_max_filesize=256M','-d','post_max_size=260M','-d','memory_limit=256M','-S',f'127.0.0.1:{port}','-t',str(ROOT/'public'),str(ROOT/'public/router.php')],cwd=ROOT,env=env,stdout=log,stderr=log,start_new_session=True)
    try:
        client = Client(base)
        public_client = Client(origin)
        for _ in range(80):
            try: client.request('/admin/login.php'); break
            except (urllib.error.URLError, ConnectionError): time.sleep(.1)
        else: raise AssertionError('PHP server did not start')
        for hidden in ['/', mount+'/admin/', mount+'/admin/login.php', '/api/update.php', '/assets/app.css', '/index.php']:
            check(public_client.request(hidden)[0] == 404, 'unmounted route hidden: ' + hidden)
        status, data, headers = client.request('/admin/')
        check(status == 303 and headers.get('Location') == '/admin/login.php', 'admin requires password login')
        status, data, headers = client.request('/admin/login.php')
        check(status == 200 and b'password' in data, 'login page renders')
        check(b'/admin/login.php' in data and (mount+'/assets/app.css').encode() in data, 'physical admin folder and stable API-namespace asset URLs')
        check(all(c.path == '/admin' for c in client.cookies), 'session cookie scoped to private admin')
        check('frame-ancestors' in headers.get('Content-Security-Policy',''), 'CSP supplied')
        cookies = str(headers.get('Set-Cookie', ''))
        check(any(c.has_nonstandard_attr('HttpOnly') for c in client.cookies), 'session cookie HttpOnly')
        status, _, _ = client.request('/admin/login.php', fields={'csrf':'wrong','password':PASSWORD})
        check(status == 403, 'login CSRF enforced')
        status, _, _ = client.request('/admin/login.php', fields={'csrf':client.csrf(),'password':'wrong'})
        check(status == 401, 'incorrect password rejected')
        status, _, headers = client.request('/admin/login.php', fields={'csrf':client.csrf(),'password':PASSWORD})
        check(status == 303 and headers.get('Location') == '/admin/', 'correct password logs in')
        check(state()['apps']['mtx-dfm-cn']['game_id']==1 and state()['next_game_id']==2, 'setup initializes stable numeric ID and next counter')
        token = client.csrf()
        status, data, _ = client.request('/admin/')
        check(status == 200 and '满天星三角洲国服'.encode() in data and '尚未发布'.encode() in data, 'dashboard empty state')
        check(b'name="game_id" value="1"' in data and b'app_key' not in data, 'admin forms and public binding expose numeric identity only')
        for path in ['/config.local.php','/storage/state.json','/vendor/autoload.php','/.git/config','/src/App.php','/router.php']:
            check(client.request(path)[0] == 404, 'private path protected: ' + path)
        check(client.request('/admin/action.php')[0] == 405, 'write endpoint rejects GET')
        check(client.request('/admin/action.php',fields={'action':'toggle','game_id':1,'csrf':'bad','revision':0})[0] == 403,'write CSRF enforced')
        check(client.request('/api/update.php?game_id=1')[0] == 422, 'API requires client context and nonce')
        params = {'game_id':1,'nonce':base64.urlsafe_b64encode(secrets.token_bytes(32)).decode().rstrip('='),'installer_version':'0.8.0','os_version':'16.1.2','profile':'mtx-dfm-remote-v1'}
        def update(**overrides):
            code, raw, hdr = client.request('/api/update.php?' + urllib.parse.urlencode(params | overrides))
            assert code == 200, (code,raw)
            envelope = json.loads(raw)
            return json.loads(base64.b64decode(envelope['payload_base64'])), envelope, hdr
        payload, envelope, _ = update()
        check(payload['status'] == 'no_release','signed no-release response')
        def update_id(game_id=1, **overrides):
            fields=params | {'game_id':game_id} | overrides
            return client.request('/api/update.php?'+urllib.parse.urlencode(fields))
        check(update_id()[0]==200 and json.loads(base64.b64decode(json.loads(update_id()[1])['payload_base64']))['game_id']==1, 'shared update endpoint resolves numeric ID')
        check(update_id(99999)[0]==404, 'unknown numeric game rejected')
        for invalid_id in ['0','-1','01','1.5','2147483648','abc']:
            check(update_id(invalid_id)[0]==422, 'invalid game ID rejected: '+invalid_id)
        for field in ['app','app_key']:
            old={k:v for k,v in params.items() if k!='game_id'} | {field:'mtx-dfm-cn'}
            check(client.request('/api/update.php?'+urllib.parse.urlencode(old))[0]==422, 'text-only update route removed: '+field)
            check(update_id(1, **{field:'mtx-dfm-cn'})[0]==422, 'mixed update route removed: '+field)
        check(client.request('/api/update.php?'+urllib.parse.urlencode({k:v for k,v in params.items() if k!='game_id'}))[0]==422, 'update game ID is mandatory')
        source1 = make_tipa()
        bad_sources = [('bad.tipa',b'not a zip'),('bad.php',source1),('wrong.tipa',make_tipa(bundle='com.fixture.other')),('traversal.tipa',make_tipa(extra=('../outside',b'test'))),('duplicate.tipa',make_tipa(extra=('Payload/Fixture.app/info.plist',b'test')))]
        link = zipfile.ZipInfo('Payload/Fixture.app/link'); link.create_system = 3; link.external_attr = (0o120777 << 16)
        bad_sources.append(('symlink.tipa',make_tipa(extra=(link,b'/tmp'))))
        for name, raw in bad_sources:
            code, data, _ = client.action('upload',file=(name,raw),min_installer_version='0.8.0',max_ios='16.6.1',changelog='test')
            check(code == 422, 'invalid upload rejected: '+name+' '+str(code))
        check(len(state()['releases']) == 0,'failed uploads never create releases')
        check(client.action('upload',file=('new-ios.tipa',make_tipa(minimum='17.0')),max_ios='99.0',changelog='test')[0] == 422,'internal OS range rejects unsupported source regardless of form override')
        code, data, _ = client.action('upload',file=('fixture.tipa',source1),changelog='<script>alert(1)</script> 后台私有备注')
        check(code == 200, 'binary plist TIPA upload: '+data.decode()[:180])
        r1 = next(iter(state()['releases'])); release1 = state()['releases'][r1]
        check(release1['min_installer_version']=='0.8.0' and release1['max_ios']=='16.6.1', 'compatibility captured internally without form fields')
        check(release1['state'] == 'draft' and release1['bundle_version'] == '1', 'TIPA creates draft and parses metadata')
        check(client.action('upload',file=('fixture.tipa',source1),min_installer_version='0.8.0',max_ios='16.6.1',changelog='repeat')[0] == 409, 'duplicate pending upload rejected')
        check(client.action('publish',release_id=r1,revision=0,confirmed=1)[0] == 409,'draft cannot publish')
        check(update()[0]['status'] == 'no_release','unprepared source stays private')
        page = client.request('/admin/')[1]
        check(b'name="min_installer_version"' not in page and b'name="max_ios"' not in page, 'upload form omits version inputs')
        check('后台备注（可选）'.encode() in page and '用户将在安装器内看到'.encode() not in page, 'notes clearly admin-only and optional')
        check(b'&lt;script&gt;' in page and b'<script>alert' not in page, 'changelog XSS escaped')
        check(client.action('attach',file=('bad.tar',prepared(source1,bad=True)),release_id=r1)[0] == 422, 'prepared internal hash mismatch rejected')
        check(client.action('attach',file=('bad.tar',prepared(b'wrong')),release_id=r1)[0] == 422, 'prepared source binding mismatch rejected')
        artifact1 = prepared(source1)
        code, data, _ = client.action('attach',file=('fixture.tar',artifact1),release_id=r1)
        check(code == 200, 'prepared TAR upload: '+data.decode()[:150])
        check(state()['releases'][r1]['state'] == 'ready', 'prepared release becomes ready')
        check(client.action('publish',release_id=r1,revision=0)[0] == 422, 'explicit compatibility acknowledgement required')
        check(client.action('publish',release_id=r1,revision=9,confirmed=1)[0] == 409,'stale publication revision rejected')
        check(client.action('publish',release_id=r1,revision=0,confirmed=1)[0] == 303,'first publication succeeds')
        check(client.action('publish',release_id=r1,revision=0,confirmed=1)[0] == 303,'repeat publication idempotent')
        check(state()['apps']['mtx-dfm-cn']['next_sequence'] == 2,'idempotent retry does not increment sequence')
        payload, envelope, headers = update()
        check(payload['status'] == 'version_unknown' and payload['release']['sequence'] == 1,'unknown installed version preserved')
        check(payload['nonce'] == params['nonce'] and headers.get('Cache-Control') == 'no-store','nonce echoed and response not shared cached')
        verify_code = '$c=require $argv[1];$e=json_decode(stream_get_contents(STDIN),true);echo sodium_crypto_sign_verify_detached(base64_decode($e["signature_base64"]),base64_decode($e["payload_base64"]),base64_decode($c["sign_public"]))?"VALID":"INVALID";'
        def verify(e): return subprocess.check_output([PHP,'-r',verify_code,str(config)],input=json.dumps(e).encode()).decode()
        check('changelog' not in payload['release'] and '后台私有备注' not in json.dumps(payload,ensure_ascii=False), 'admin notes excluded from public signed payload')
        check(verify(envelope) == 'VALID','Ed25519 signature verifies')
        native_code='$c=require $argv[1];$e=json_decode(stream_get_contents(STDIN),true);$k=openssl_pkey_get_private($c["native_sign_secret"]);$p=openssl_pkey_get_details($k)["key"];echo openssl_verify(base64_decode($e["payload_base64"]),base64_decode($e["native_signature_base64"]),$p,OPENSSL_ALGO_SHA256);'
        check(subprocess.check_output([PHP,'-r',native_code,str(config)],input=json.dumps(envelope).encode())==b'1','PHP native P-256 signature verifies')
        tampered = envelope | {'payload_base64':base64.b64encode(b'tampered').decode()}
        check(verify(tampered) == 'INVALID','tampered manifest signature fails')
        check(update(installed_sequence=0)[0]['status'] == 'update_available','update decision')
        check(update(installed_sequence=1)[0]['status'] == 'up_to_date','up-to-date decision')
        check(update(installed_sequence=99)[0]['status'] == 'version_unknown','future local sequence not incorrectly latest')
        check(update(installer_version='0.7.1')[0]['status'] == 'client_upgrade_required','old installer filtered')
        check(update(profile='other-game')[0]['status'] == 'client_upgrade_required','wrong profile filtered')
        check(update(os_version='17.0')[0]['status'] == 'incompatible','unsupported OS filtered')
        check(update(os_version='14')[0]['status'] == 'version_unknown','short OS version normalized before comparison')
        numeric_payload=json.loads(base64.b64decode(json.loads(update_id()[1])['payload_base64']))
        check(numeric_payload['game_id']==1 and 'app_key' not in numeric_payload and numeric_payload['release']==payload['release'], 'signed payload uses numeric identity only')
        sha1 = hashlib.sha256(artifact1).hexdigest()
        def ticket(rid=r1, sha=sha1, game_id=1):
            return client.request('/api/download-ticket.php',body=json.dumps({'game_id':game_id,'release_id':rid,'artifact_id':sha}).encode(),headers={'Content-Type':'application/json'})
        check(payload['release']['source_bytes']==len(source1), 'signed source length included')
        check(len(payload['release']['manifest_sha256'])==64, 'signed prepared manifest digest included')
        code, source_ticket, _ = ticket(sha=hashlib.sha256(source1).hexdigest())
        check(code==200, 'TIPA source ticket issued')
        source_url=json.loads(source_ticket)['url']
        source_status, source_raw, source_hdr=client.request(source_url)
        check(source_status==200 and source_raw==source1 and '.tipa' in source_hdr['Content-Disposition'], 'source download is actual original TIPA')
        code, data, _ = ticket()
        check(code == 200,'download ticket issued')
        numeric_ticket=client.request('/api/download-ticket.php',body=json.dumps({'game_id':1,'release_id':r1,'artifact_id':sha1}).encode(),headers={'Content-Type':'application/json'})
        check(numeric_ticket[0]==200 and json.loads(numeric_ticket[1])['game_id']==1 and 'app_key' not in json.loads(numeric_ticket[1]), 'numeric ID download ticket')
        for field in ['app','app_key']:
            for numeric in [{},{'game_id':1}]:
                body=numeric | {field:'mtx-dfm-cn','release_id':r1,'artifact_id':sha1}
                check(client.request('/api/download-ticket.php',body=json.dumps(body).encode(),headers={'Content-Type':'application/json'})[0]==422, 'textual ticket route removed: '+field+str(bool(numeric)))
        check(client.request('/api/download-ticket.php',body=json.dumps({'release_id':r1,'artifact_id':sha1}).encode(),headers={'Content-Type':'application/json'})[0]==422, 'ticket game ID is mandatory')
        download_url = json.loads(data)['url']
        code, downloaded, hdr = client.request(download_url)
        check(code == 200 and downloaded == artifact1,'full download matches exact artifact bytes')
        check(hdr.get('ETag') == '"'+sha1+'"' and int(hdr['Content-Length']) == len(artifact1),'download metadata')
        code, downloaded, hdr = client.request(download_url,method='HEAD')
        check(code == 200 and downloaded == b'' and int(hdr['Content-Length']) == len(artifact1),'HEAD response')
        code, downloaded, hdr = client.request(download_url,headers={'Range':'bytes=5-19'})
        check(code == 206 and downloaded == artifact1[5:20] and hdr['Content-Range']==f'bytes 5-19/{len(artifact1)}','Range download')
        code, downloaded, _ = client.request(download_url,headers={'Range':'bytes=-8'})
        check(code == 206 and downloaded == artifact1[-8:],'suffix range')
        code, downloaded, _ = client.request(download_url,headers={'Range':'bytes=5-9','If-Range':'"changed"'})
        check(code == 200 and downloaded == artifact1,'If-Range mismatch restarts full download')
        check(client.request(download_url,headers={'Range':'bytes=999999999-'})[0] == 416,'out-of-bounds range')
        check(client.request(download_url,headers={'Range':'bytes=0-1,4-5'})[0] == 416,'multiple ranges rejected')
        check(client.request(download_url+'&token='+'0'*64)[0] == 403,'tampered download token rejected')
        parsed=urllib.parse.urlsplit(download_url); q=urllib.parse.parse_qs(parsed.query); q['expires']=['1']
        check(client.request(parsed.path+'?'+urllib.parse.urlencode(q,doseq=True))[0] == 403,'expired download token rejected')
        check(ticket(sha='0'*64)[0] == 404,'ticket artifact binding checked')
        source2=make_tipa(build='2',binary=False)
        check(client.action('upload',file=('v2.tipa',source2),min_installer_version='99.0',max_ios='99.0')[0] == 200,'XML plist version 2 upload')
        r2=next(r for r in state()['releases'] if r!=r1)
        check(state()['releases'][r2]['changelog']=='', 'upload succeeds with no admin note')
        check(state()['releases'][r2]['min_installer_version']=='0.8.0' and state()['releases'][r2]['max_ios']=='16.6.1', 'posted version fields never override fixed contract')
        check(client.action('attach',file=('v2.tar',prepared(source2)),release_id=r2)[0] == 200,'second prepared artifact')
        # Create separate authenticated sessions to exercise cross-process file locking.
        clients=[]
        for _ in range(2):
            c=Client(base); c.request('/admin/login.php',fields={'csrf':c.csrf(),'password':PASSWORD}); clients.append(c)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            codes=list(pool.map(lambda c:c.action('publish',release_id=r2,revision=1,confirmed=1)[0],clients))
        check(codes==[303,303] and state()['releases'][r2]['sequence']==2 and state()['apps']['mtx-dfm-cn']['next_sequence']==3,'concurrent publication atomic and idempotent')
        check(update(installed_sequence=1)[0]['status']=='update_available','same display version new sequence updates')
        check(client.request(source_url)[0] == 404, 'old source ticket invalidated')
        check(client.request(download_url)[0] == 404,'old download ticket stops after activation changes')
        check(client.action('withdraw',release_id=r2,revision=2)[0] == 303,'withdraw current release')
        check(update()[0]['status']=='no_release','withdraw does not silently choose older release')
        check(client.action('republish',release_id=r1)[0] == 303,'old content cloned as new draft')
        r3=next(r for r in state()['releases'] if r not in [r1,r2])
        check(client.action('publish',release_id=r3,revision=3,confirmed=1)[0] == 303 and state()['releases'][r3]['sequence']==3,'republish old bytes uses increasing sequence')
        check(client.action('toggle',revision=4)[0] == 303 and update()[0]['status']=='no_release','disabled game stops updates')
        check(client.action('toggle',revision=5)[0] == 303,'game re-enabled')
        code,_,_=client.action('create',game_id=2,name='其他测试游戏',bundle_id='com.fixture.other',format='tipa')
        check(code == 303,'new game can be created')
        check(client.action('upload',game_id=2,file=('wrong.tipa',source1),min_installer_version='0.8.0',max_ios='16.6.1',changelog='test')[0] == 422,'cross-game source rejected')
        check(client.action('withdraw',game_id=2,release_id=r3,revision=0)[0] == 404,'cross-game action rejected')
        check(ticket(rid=r3,game_id=2)[0] == 404,'cross-game download rejected')
        rawother=make_tipa(bundle='com.fixture.other')
        check(client.action('upload',game_id=2,file=('other.tipa',rawother),min_installer_version='0.8.0',max_ios='16.6.1',changelog='raw mode')[0] == 200,'raw TIPA mode upload')
        other=next(r for r,v in state()['releases'].items() if v['app_key']=='game-2')
        check(state()['releases'][other]['state']=='ready','raw mode has no TAR requirement')
        check(client.action('publish',game_id=2,release_id=other,revision=0,confirmed=1)[0] == 303,'raw TIPA mode publish')
        # Different version IDs contend for the same expected revision: exactly one wins.
        check(client.action('create',game_id=3,name='并发测试',bundle_id='com.fixture.race',format='tipa')[0] == 303,'concurrent test game created')
        for build in ['1','2']:
            check(client.action('upload',game_id=3,file=('race.tipa',make_tipa(build=build,bundle='com.fixture.race')),min_installer_version='0.8.0',max_ios='16.6.1',changelog='race test')[0] == 200,'race release uploaded '+build)
        racers=[rid for rid,r in state()['releases'].items() if r['app_key']=='game-3']
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            codes=list(pool.map(lambda pair:pair[0].action('publish',game_id=3,release_id=pair[1],revision=0,confirmed=1)[0],zip(clients,racers)))
        check(sorted(codes)==[303,409] and state()['apps']['game-3']['next_sequence']==2,'distinct concurrent releases have one winner')
        ready=next(rid for rid in racers if state()['releases'][rid]['state']=='ready')
        sha=state()['releases'][ready]['artifact_sha256']; obj=storage/'objects'/sha; original=obj.read_bytes(); obj.write_bytes(b'corrupt')
        check(client.action('publish',game_id=3,release_id=ready,revision=1,confirmed=1)[0] == 409 and state()['apps']['game-3']['revision']==1,'corrupt stored artifact never published and state unchanged')
        obj.write_bytes(original)
        before=state()['next_game_id']
        check(client.action('create',name='自动 ID 游戏',bundle_id='com.fixture.auto',format='prepared-payload-v1',game_id='99999')[0]==303, 'game creation needs no manually chosen key or profile')
        auto=next(g for g in state()['apps'].values() if g['game_id']==before)
        check(auto['app_key']=='game-'+str(before) and auto['profile_id']==auto['app_key']+'-remote-v1', 'server generates stable internal binding and ignores requested ID')
        check(client.request('/admin/?game_id='+str(before))[0]==200 and client.request('/admin/?game_id='+str(before)+'&app=mtx-dfm-cn')[0]==422, 'admin uses numeric ID and rejects textual parameters')
        duplicate=client.action('create',app_key=auto['app_key'],name='重复',bundle_id='com.fixture.duplicate',format='prepared-payload-v1')[0]
        check(duplicate==422 and state()['next_game_id']==before+1, 'removed explicit-key creation leaves counter unchanged')
        check(client.action('toggle',game_id=before,revision=0)[0]==303 and state()['apps'][auto['app_key']]['game_id']==before, 'disabling game preserves ID')
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            codes=list(pool.map(lambda pair:pair[1].action('create',name='并发自动 ID '+str(pair[0]),bundle_id='com.fixture.auto'+str(pair[0]),format='prepared-payload-v1')[0],enumerate(clients)))
        current=state();ids=[g['game_id'] for g in current['apps'].values()]
        check(codes==[303,303] and len(ids)==len(set(ids)) and current['next_game_id']==before+3, 'concurrent game creation allocates distinct IDs under lock')
        snapshot=(storage/'state.json').read_bytes()
        check(client.request('/admin/')[0]==200 and (storage/'state.json').read_bytes()==snapshot, 'normal reads do not migrate or rewrite catalog')
        malformed=state(); malformed['apps']['mtx-dfm-cn'].pop('game_id'); (storage/'state.json').write_text(json.dumps(malformed)); bad_bytes=(storage/'state.json').read_bytes()
        check(client.request('/admin/')[0]==500 and (storage/'state.json').read_bytes()==bad_bytes, 'missing stored ID fails without automatic migration')
        (storage/'state.json').write_bytes(snapshot)
        for field in ['app','app_key']:
            check(client.request('/admin/?'+field+'=mtx-dfm-cn')[0]==422, 'textual admin route removed: '+field)
            check(client.request('/admin/action.php',fields={'csrf':client.csrf(),'action':'toggle',field:'mtx-dfm-cn','revision':6})[0]==422, 'textual admin write removed: '+field)
        check(client.action('create',name='test',bundle_id='com.fixture.oldprofile',format='tipa',profile_id='manual-v1')[0]==422, 'creation does not accept manually assigned profile')
        exported=tmp/'game.h'
        result=subprocess.run([PHP,str(ROOT/'bin/export-installer.php'),'--url','https://updates.example.com','--game-id',str(before),'--out',str(exported)],env=env,capture_output=True)
        check(result.returncode==0 and f'MTX_REMOTE_GAME_ID @{before}' in exported.read_text() and '?app=' not in exported.read_text() and 'MTX_REMOTE_APP_KEY' not in exported.read_text() and auto['bundle_id'] in exported.read_text(), 'ID build export fills identity and shared endpoint')
        check(client.action('logout')[0] == 303 and client.request('/admin/')[0]==303,'logout clears access')
        for attempt in range(5):
            check(client.request('/admin/login.php',fields={'csrf':client.csrf(),'password':'bad'})[0] == 401,f'wrong password attempt {attempt+1}')
        check(client.request('/admin/login.php',fields={'csrf':client.csrf(),'password':PASSWORD})[0] == 429,'login rate limit persists across requests')
        changed=subprocess.run([PHP,str(ROOT/'bin/password.php')],env=env,input=b'new-fixture-password-2026\n',capture_output=True)
        password_status=clients[0].request('/admin/')[0]
        check(changed.returncode==0 and password_status==303,'password change invalidates existing sessions: '+str((changed.returncode,password_status,changed.stderr.decode())))
        check(all(p.suffix not in ['.db','.sqlite'] for p in storage.rglob('*')),'no database files created')
        check(len(state()['audit'])>10,'audit log persisted')
        # Exercise malformed binary plist cycle without HTTP so limits are easy to assert.
        cycle=b'bplist00'+b'\xa1\x00'+b'\x08'+bytes(6)+bytes([1,1])+(1).to_bytes(8,'big')+(0).to_bytes(8,'big')+(10).to_bytes(8,'big')
        cyclepath=tmp/'cycle.plist'; cyclepath.write_bytes(cycle)
        testcode='require "vendor/autoload.php"; try { MTX\\Plist::decode(file_get_contents($argv[1])); exit(2); } catch (MTX\\Problem $e) { echo $e->status; }'
        output=subprocess.check_output([PHP,'-r',testcode,str(cyclepath)],cwd=ROOT,timeout=5)
        check(output==b'422','cyclic binary plist bounded')
        xxe=b'<?xml version="1.0"?><!DOCTYPE plist [<!ENTITY xx SYSTEM "file:///etc/passwd">]><plist><dict><key>a</key><string>&xx;</string></dict></plist>'
        cyclepath.write_bytes(xxe)
        check(subprocess.check_output([PHP,'-r',testcode,str(cyclepath)],cwd=ROOT,timeout=5)==b'422','XML external entities blocked')
    except Exception:
        log.flush(); print('\nSERVER LOG (tail):\n'+(tmp/'server.log').read_text()[-7000:]); raise
    finally:
        os.killpg(proc.pid,signal.SIGTERM); proc.wait(timeout=10); log.close()
print(f'\n{len(passed)} checks passed. All fixture state removed.')
