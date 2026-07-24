from ftplib import FTP
from pathlib import Path
import os
import sys


for line in Path(".env").read_text(encoding="utf-8-sig").splitlines():
    if line.strip() and not line.lstrip().startswith("#") and "=" in line:
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip())

remote = sys.argv[1].strip("/")
local = Path(sys.argv[2])
local.parent.mkdir(parents=True, exist_ok=True)
with FTP(os.environ["FTP_HOST"], timeout=30) as ftp:
    ftp.login(os.environ["FTP_USER"], os.environ["FTP_PASSWORD"])
    for part in os.environ["FTP_ROOT"].strip("/").split("/"):
        if part:
            ftp.cwd(part)
    with local.open("wb") as handle:
        ftp.retrbinary("RETR " + remote, handle.write)
print(f"Downloaded {remote} to {local}")
