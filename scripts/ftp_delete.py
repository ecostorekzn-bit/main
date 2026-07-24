from ftplib import FTP
from pathlib import Path
import os
import sys


for line in Path('.env').read_text(encoding='utf-8-sig').splitlines():
    if line.strip() and not line.lstrip().startswith('#') and '=' in line:
        key, value = line.split('=', 1)
        os.environ.setdefault(key.strip(), value.strip())

remote = sys.argv[1].strip('/')
parts = remote.split('/')
name = parts.pop()
with FTP(os.environ['FTP_HOST'], timeout=30) as ftp:
    ftp.login(os.environ['FTP_USER'], os.environ['FTP_PASSWORD'])
    for part in os.environ['FTP_ROOT'].strip('/').split('/') + parts:
        if part:
            ftp.cwd(part)
    ftp.delete(name)
    if parts:
        ftp.cwd('..')
        try:
            ftp.rmd(parts[-1])
        except Exception:
            pass
print('Remote diagnostic removed')
