#!/usr/bin/env python3
"""The Windows smoke's welcome-card Escape, on a Linux PTY: does a lone ESC at the card's
first paint do anything? Reported, never failed. Usage: escape-probe.py <folder holding runtime/>"""
import base64, fcntl, os, pty, select, shutil, struct, sys, tempfile, termios, time

app = sys.argv[1]
runtime = os.path.join(app, 'runtime')
php = os.path.join(runtime, 'php')
work = tempfile.mkdtemp(prefix='escape-probe')
project = os.path.join(work, 'shop')
os.makedirs(project)
open(os.path.join(project, 'composer.json'), 'w').write('{}')
open(os.path.join(project, 'a.php'), 'w').write('<?php\n')
token = 'c' * 32
env = dict(os.environ, PORTA_DESKTOP='1', PORTA_DESKTOP_TOKEN=token, PORTA_HOME=os.path.join(work, 'home'),
           PORTA_CACHE_DIR=os.path.join(work, 'cache'), TERM='xterm-256color', SHELL='/bin/sh', LANG='C.UTF-8')

pid, fd = pty.fork()
if pid == 0:
    os.execve(php, [php, '-n', '-d', 'memory_limit=-1', '-d', 'display_errors=0', os.path.join(runtime, 'porta.phar'), project], env)
fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack('HHHH', 30, 100, 0, 0))
out = b''


def pump(seconds, until=None):
    global out
    end = time.time() + seconds
    while time.time() < end:
        ready, _, _ = select.select([fd], [], [], 0.01)
        if ready:
            try:
                out += os.read(fd, 65536)
            except OSError:
                return False
        if until is not None and until in out:
            return True
    return False


def drew_after(write, seconds=3.0):
    mark = len(out)
    os.write(fd, write)
    pump(seconds)
    return len(out) - mark


try:
    pump(120, b'Trust this project')
    os.write(fd, b'\r')
    pump(120, b';ready;')
    print('escape-probe: welcome card drawn:', pump(60, b'Welcome'))
    print('escape-probe: Escape at its first paint redrew', drew_after(b'\x1b'), 'bytes')
    print('escape-probe: a second Escape redrew', drew_after(b'\x1b'), 'bytes')
    request = '\x1b]7777;porta-desktop;%s;open;%s\x1b\\' % (token, base64.b64encode(os.path.join(project, 'a.php').encode()).decode())
    print('escape-probe: the open request redrew', drew_after(request.encode()), 'bytes')
finally:
    os.kill(pid, 9)
    shutil.rmtree(work, ignore_errors=True)
