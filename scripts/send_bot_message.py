from pathlib import Path
import os
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from ai_seller.bitrix import BitrixClient


for line in Path('.env').read_text(encoding='utf-8-sig').splitlines():
    if line.strip() and not line.lstrip().startswith('#') and '=' in line:
        key, value = line.split('=', 1)
        os.environ[key.strip()] = value.strip()

dialog_id = sys.argv[1]
message = sys.argv[2]
client = BitrixClient(os.environ['BITRIX_WEBHOOK_BASE'])
result = client.call('imbot.v2.Chat.Message.send', {
    'botId': int(os.environ['BITRIX_BOT_ID']),
    'botToken': os.environ['BOT_TOKEN'],
    'dialogId': dialog_id,
    'fields': {'message': message},
})
if 'result' not in result:
    raise SystemExit(str({k: result.get(k) for k in ('error', 'error_description', 'http_status')}))
print('Message sent, id:', result.get('result'))
