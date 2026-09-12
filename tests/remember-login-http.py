#!/usr/bin/env python3
"""Exercise password-only remembered sessions using disposable files and real HTTP."""
import http.cookiejar, os, re, secrets, shutil, signal, socket, subprocess, tempfile, time
import urllib.error, urllib.parse, urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')
passed = 0

def check(ok, label):
    global passed
    assert ok, label
    passed += 1
    print('PASS', label, flush=True)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args): return None

with tempfile.TemporaryDirectory(prefix='mtx-remember-login-') as tmp:
    tmp = Path(tmp)
    root = tmp / 'project'
    root.mkdir()
    for name in ['src', 'public', 'bin', 'vendor']:
        shutil.copytree(ROOT / name, root / name)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    origin = f'http://127.0.0.1:{port}'
    config, storage = tmp / 'config.php', tmp / 'storage'
    password = secrets.token_hex(16)
    subprocess.run([PHP, str(root/'bin/setup.php'), '--url', origin, '--local-http',
                    '--password-stdin', '--config', str(config), '--storage', str(storage)],
                   input=(password+'\n').encode(), check=True, capture_output=True)
    log = (tmp/'server.log').open('w+')
    proc = subprocess.Popen([PHP, '-d', 'session.gc_probability=1', '-d', 'session.gc_divisor=1',
                             '-S', f'127.0.0.1:{port}', '-t', str(root/'public'), str(root/'public/router.php')],
                            cwd=root, env=os.environ | {'MTX_CONFIG': str(config)},
                            stdout=log, stderr=log, start_new_session=True)
    folder = 'admin'

    class Client:
        def __init__(self, jar=None):
            self.jar = jar if jar is not None else http.cookiejar.MozillaCookieJar()
            self.opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(self.jar))

        def request(self, path='', fields=None):
            body = None if fields is None else urllib.parse.urlencode(fields).encode()
            req = urllib.request.Request(origin+'/'+folder+'/'+path, data=body)
            try: result = self.opener.open(req, timeout=10)
            except urllib.error.HTTPError as e: result = e
            return result.status, result.read(), result.headers

        def token(self):
            page = self.request()[1]
            if b'name="csrf"' not in page: page = self.request('login.php')[1]
            return re.search(rb'name="csrf" value="([^"]+)"', page)[1].decode()

        def cookie(self): return next(c for c in self.jar if c.name == 'mtx_admin' and c.path == '/'+folder)

        def login(self, remember=True):
            fields = {'csrf': self.token(), 'password': password}
            if remember: fields['remember'] = '1'
            return self.request('login.php', fields)

    try:
        client = Client()
        for _ in range(80):
            try: client.request('login.php'); break
            except urllib.error.URLError: time.sleep(.1)
        else: raise AssertionError('PHP server did not start')
        check(client.request()[2].get('Location') == '/admin/login.php', 'admin opens password login')
        page = client.request('login.php')[1]
        check(b'name="password"' in page and b'name="username"' not in page, 'password only, no username')
        check(re.search(rb'name="remember"[^>]+checked', page), 'remember 30 days selected by default')
        check(client.cookie().discard and client.cookie().expires is None, 'anonymous cookie is not persistent')
        anonymous_id, old_csrf = client.cookie().value, client.token()
        check(client.request('login.php', {'csrf':'wrong', 'password':password, 'remember':'1'})[0] == 403,
              'remembered login still validates CSRF')
        check(client.request('login.php', {'csrf':client.token(), 'password':'incorrect', 'remember':'1'})[0] == 401,
              'remember does not bypass password')
        check(client.login()[0] == 303 and client.request()[0] == 200, 'remembered login succeeds')
        cookie = client.cookie()
        check(cookie.value != anonymous_id and client.token() != old_csrf, 'login rotates session ID and CSRF')
        check(not cookie.discard and abs(cookie.expires-time.time()-30*86400) < 10, 'cookie expires after 30 days')
        check(cookie.path == '/admin' and cookie.has_nonstandard_attr('HttpOnly') and cookie.get_nonstandard_attr('SameSite') == 'Strict',
              'cookie retains scoped path, HttpOnly and SameSite')
        session_file = storage/'sessions'/('sess_'+cookie.value)
        check(password.encode() not in session_file.read_bytes() and password not in cookie.value, 'no plaintext password in session or cookie')
        jar_file = tmp/'browser-cookies.txt'
        client.jar.save(str(jar_file), ignore_discard=False)
        resumed_jar = http.cookiejar.MozillaCookieJar(str(jar_file))
        resumed_jar.load(ignore_discard=False)
        resumed = Client(resumed_jar)
        check(resumed.request()[0] == 200, 'persisted cookie restores login after browser restart')
        expiry_before = re.search(r'expires\|i:(\d+);', session_file.read_text())[1]
        resumed.request()
        check(re.search(r'expires\|i:(\d+);', session_file.read_text())[1] == expiry_before, 'normal visits do not extend absolute expiry')
        os.utime(session_file, (time.time()-2*86400, time.time()-2*86400))
        Client().request('login.php')
        check(session_file.exists() and resumed.request()[0] == 200, 'PHP garbage collection retains two-day-old remembered session')
        saved_id = resumed.cookie().value
        check(resumed.request('action.php', {'csrf':resumed.token(), 'action':'logout'})[0] == 303, 'logout succeeds')
        check(not any(c.name == 'mtx_admin' for c in resumed.jar), 'logout removes persistent cookie')
        replay = Client()
        old_cookie = client.cookie()
        replay.jar.set_cookie(old_cookie)
        check(replay.request()[0] == 303 and not (storage/'sessions'/('sess_'+saved_id)).exists(), 'old cookie is invalid after logout')
        normal = Client()
        check(normal.login(False)[0] == 303 and normal.cookie().discard and normal.cookie().expires is None, 'unchecked option uses browser-session cookie')
        normal_session = storage/'sessions'/('sess_'+normal.cookie().value)
        check(abs(int(re.search(r'expires\|i:(\d+);', normal_session.read_text())[1])-time.time()-8*3600) < 10,
              'unchecked option keeps eight-hour server limit')
        expired = Client()
        expired.login()
        expired_file = storage/'sessions'/('sess_'+expired.cookie().value)
        expired_file.write_text(re.sub(r'expires\|i:\d+;', 'expires|i:1;', expired_file.read_text()))
        check(expired.request()[0] == 303, 'server rejects expired credential despite unexpired browser cookie')
        remembered = Client()
        remembered.login()
        changed = subprocess.run([PHP, str(root/'bin/password.php')], input=(secrets.token_hex(16)+'\n').encode(),
                                 env=os.environ | {'MTX_CONFIG':str(config)}, capture_output=True)
        check(changed.returncode == 0 and remembered.request()[0] == 303, 'password rotation invalidates remembered login')
        folder = 'panel_abc123'
        (root/'public/admin').rename(root/'public'/folder)
        check(Client().request('login.php')[0] == 200, 'renamed backend keeps password login')
        # Production cookie attributes are checked without sending credentials over plain HTTP.
        php = 'require $argv[1]."/vendor/autoload.php";$a=new MTX\\App();$m=new ReflectionMethod(MTX\\Security::class,"cookieOptions");echo json_encode($m->invoke(null,$a));'
        raw = config.read_text().replace("'local_http' => true", "'local_http' => false")
        config.write_text(raw)
        result = subprocess.run([PHP,'-r',php,str(root)], env=os.environ | {'MTX_CONFIG':str(config)}, capture_output=True, check=True)
        import json
        options = json.loads(result.stdout)
        check(options['secure'] and options['httponly'] and options['samesite']=='Strict' and options['path']=='/'+folder,
              'production and renamed-folder cookie remains Secure/HttpOnly/SameSite')
        check('session_start(): ' not in (tmp/'server.log').read_text(), 'no session configuration warnings')
        print(f'{passed} checks passed')
    finally:
        os.killpg(proc.pid, signal.SIGTERM)
        proc.wait(timeout=5)
        log.close()
