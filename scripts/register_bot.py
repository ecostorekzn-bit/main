from pathlib import Path
import os
import sys
import secrets
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from ai_seller.bitrix import BitrixClient


for line in Path('.env').read_text(encoding='utf-8-sig').splitlines():
    if line.strip() and not line.lstrip().startswith('#') and '=' in line:
        key, value = line.split('=', 1)
        os.environ[key.strip()] = value.strip()

client = BitrixClient(os.environ['BITRIX_WEBHOOK_BASE'])
token = os.environ.get('BOT_TOKEN') or secrets.token_urlsafe(24)[:40]
if 'BOT_TOKEN=' not in Path('.env').read_text(encoding='utf-8-sig'):
    with Path('.env').open('a', encoding='utf-8') as handle:
        handle.write(f'BOT_TOKEN={token}\n')

handler = 'https://eco-store16.ru/ai-seller/?action=bitrix&token=' + os.environ['EVENT_TOKEN']
result = client.call('imbot.v2.Bot.register', {
    'fields': {
        'code': 'ecostore_ai_seller',
        'botToken': token,
        'type': 'openline',
        'eventMode': 'webhook',
        'webhookUrl': handler,
        'isSupportOpenline': True,
        'properties': {
            'name': 'Алина Eco-Store',
            'workPosition': 'Консультант',
            'color': 'mint',
            'gender': 'F',
        },
    }
})
if 'result' not in result:
    raise SystemExit('Registration failed: ' + str({k: result.get(k) for k in ('error', 'error_description', 'http_status')}))
print('Bot registered:', result['result']['bot']['id'])
