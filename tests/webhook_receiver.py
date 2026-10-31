#!/usr/bin/env python3
"""HTTPS receiver for tests/release150.py (webhook). Listens on 127.0.0.1:9443 and appends every POST to /tmp/hook/received.jsonl.
Paths: /fail answers 500, /redir answers 302, /slow waits 8 s. Setup: create /tmp/hook/cert.pem + key.pem
(openssl req -x509 -newkey rsa:2048 -nodes -keyout key.pem -out cert.pem -days 30 -subj /CN=localhost
-addext subjectAltName=DNS:localhost,IP:127.0.0.1), append cert.pem to the CA bundle of the test Joomla
(libraries/vendor/composer/ca-bundle/res/cacert.pem) and start this script. Test environment only."""
import http.server, ssl, json, sys, time
LOG='/tmp/hook/received.jsonl'
MODE={'status':200,'delay':0}
class H(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        n=int(self.headers.get('Content-Length',0)); body=self.rfile.read(n).decode()
        if self.path.startswith('/slow'): time.sleep(8)
        with open(LOG,'a') as f: f.write(json.dumps({'path':self.path,'headers':dict(self.headers),'body':body})+'\n')
        code = 500 if self.path.startswith('/fail') else (302 if self.path.startswith('/redir') else 200)
        self.send_response(code)
        if code==302: self.send_header('Location','http://127.0.0.1:9/')
        self.end_headers(); self.wfile.write(b'ok')
    def log_message(self,*a): pass
s=http.server.ThreadingHTTPServer(('127.0.0.1',9443),H)
c=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); c.load_cert_chain('/tmp/hook/cert.pem','/tmp/hook/key.pem')
s.socket=c.wrap_socket(s.socket,server_side=True); s.serve_forever()
