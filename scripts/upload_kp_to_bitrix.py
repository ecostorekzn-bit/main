import base64
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from ai_seller.bitrix import BitrixClient


deal_id = int(sys.argv[1])
pdf_path = Path(sys.argv[2]).resolve()
field_name = sys.argv[3] if len(sys.argv) > 3 else "UF_CRM_1778228992"

if not pdf_path.is_file():
    raise SystemExit(f"Файл не найден: {pdf_path}")

encoded = base64.b64encode(pdf_path.read_bytes()).decode("ascii")
client = BitrixClient()
result = client.call(
    "crm.deal.update",
    {
        "id": deal_id,
        "fields": {
            field_name: [
                {
                    "fileData": [
                        pdf_path.name,
                        encoded,
                    ]
                }
            ]
        },
    },
)
if not result.get("result"):
    raise SystemExit(
        json.dumps(
            {k: result.get(k) for k in ("error", "error_description", "http_status")},
            ensure_ascii=False,
        )
    )
print(json.dumps({"ok": True, "deal_id": deal_id, "field": field_name}, ensure_ascii=False))
