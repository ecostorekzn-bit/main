from ftplib import FTP
from io import BytesIO
from pathlib import Path
import os
import sys


for line in Path('.env').read_text(encoding='utf-8-sig').splitlines():
    if line.strip() and not line.lstrip().startswith('#') and '=' in line:
        key, value = line.split('=', 1)
        os.environ.setdefault(key.strip(), value.strip())

remote = sys.argv[1]
buffer = BytesIO()
with FTP(os.environ['FTP_HOST'], timeout=30) as ftp:
    ftp.login(os.environ['FTP_USER'], os.environ['FTP_PASSWORD'])
    for part in os.environ['FTP_ROOT'].strip('/').split('/'):
        if part:
            ftp.cwd(part)
    ftp.retrbinary('RETR ' + remote, buffer.write)
text = buffer.getvalue().decode('utf-8', errors='replace')
lines = [line for line in text.splitlines() if line.strip()]
print('\n'.join(lines[-5:]))
