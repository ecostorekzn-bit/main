from ftplib import FTP
from pathlib import Path
import os
import sys


def load_env(path=Path('.env')):
    for line in path.read_text(encoding='utf-8-sig').splitlines():
        if line.strip() and not line.lstrip().startswith('#') and '=' in line:
            key, value = line.split('=', 1)
            os.environ.setdefault(key.strip(), value.strip())


load_env()
local = Path(sys.argv[1])
remote_dir = sys.argv[2]
remote_name = sys.argv[3] if len(sys.argv) > 3 else local.name

with FTP(os.environ['FTP_HOST'], timeout=30) as ftp:
    ftp.login(os.environ['FTP_USER'], os.environ['FTP_PASSWORD'])
    root = os.environ['FTP_ROOT'].strip('/')
    if root:
        for part in root.split('/'):
            ftp.cwd(part)
    for part in remote_dir.strip('/').split('/'):
        if not part:
            continue
        try:
            ftp.cwd(part)
        except Exception:
            ftp.mkd(part)
            ftp.cwd(part)
    with local.open('rb') as handle:
        ftp.storbinary(f'STOR {remote_name}', handle)
    print(f'Uploaded {remote_name} to /{root}/{remote_dir.strip("/")}/')
