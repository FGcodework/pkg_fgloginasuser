import socket, threading
import os
MSGS = os.environ.get('FG_MAILS', '/tmp/jtest/mails.txt')
open(MSGS, 'w').close()
srv = socket.socket(); srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(('127.0.0.1', 1025)); srv.listen(5)
def handle(c):
    f = c.makefile('rwb'); 
    def w(s):
        f.write((s + '\r\n').encode()); f.flush()
    w('220 sink ESMTP'); data = False; buf = []
    while True:
        line = f.readline()
        if not line: break
        t = line.decode('utf-8', 'replace').rstrip('\r\n')
        if data:
            if t == '.':
                open(MSGS, 'a').write('\n'.join(buf) + '\n=====\n'); buf = []; data = False; w('250 OK')
            else: buf.append(t)
            continue
        u = t.upper()
        if u.startswith('EHLO') or u.startswith('HELO'): w('250 sink')
        elif u.startswith('DATA'): data = True; w('354 go')
        elif u.startswith('QUIT'): w('221 bye'); break
        else: w('250 OK')
    c.close()
while True:
    c, _ = srv.accept(); threading.Thread(target=handle, args=(c,), daemon=True).start()
