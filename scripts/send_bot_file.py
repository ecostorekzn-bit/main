import base64
import json
import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from ai_seller.bitrix import BitrixClient


for line in Path(".env").read_text(encoding="utf-8-sig").splitlines():
    if line.strip() and not line.lstrip().startswith("#") and "=" in line:
        key, value = line.split("=", 1)
        os.environ[key.strip()] = value.strip()

dialog_id = sys.argv[1]
file_path = Path(sys.argv[2]).resolve()
message = sys.argv[3] if len(sys.argv) > 3 else ""
content = base64.b64encode(file_path.read_bytes()).decode("ascii")

result = BitrixClient(os.environ["BITRIX_WEBHOOK_BASE"]).call(
    "imbot.v2.File.upload",
    {
        "botId": int(os.environ["BITRIX_BOT_ID"]),
        "botToken": os.environ["BOT_TOKEN"],
        "dialogId": dialog_id,
        "fields": {
            "name": file_path.name,
            "content": content,
            "message": message,
        },
    },
)
if "result" not in result:
    raise SystemExit(
        json.dumps(
            {k: result.get(k) for k in ("error", "error_description", "http_status")},
            ensure_ascii=False,
        )
    )
print(
    json.dumps(
        {
            "ok": True,
            "message_id": result.get("result", {}).get("messageId"),
            "file_id": result.get("result", {}).get("file", {}).get("id"),
        },
        ensure_ascii=False,
    )
)
