from pathlib import Path
import os
import re
import secrets
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from ai_seller.bitrix import BitrixClient


path = Path('.env')
content = path.read_text(encoding='utf-8-sig')
values = {}
for line in content.splitlines():
    if line.strip() and not line.lstrip().startswith('#') and '=' in line:
        key, value = line.split('=', 1)
        values[key.strip()] = value.strip()

new_token = secrets.token_urlsafe(32)
if re.search(r'^EVENT_TOKEN=.*$', content, flags=re.MULTILINE):
    content = re.sub(r'^EVENT_TOKEN=.*$', 'EVENT_TOKEN=' + new_token, content, flags=re.MULTILINE)
else:
    content = content.rstrip() + '\nEVENT_TOKEN=' + new_token + '\n'
path.write_text(content, encoding='utf-8')

bot_id = int(values.get('BITRIX_BOT_ID', '0') or 0)
bot_token = values.get('BOT_TOKEN', '')
if bot_id and bot_token:
    handler = 'https://eco-store16.ru/ai-seller/?action=bitrix&token=' + new_token
    result = BitrixClient(values['BITRIX_WEBHOOK_BASE']).call('imbot.v2.Bot.update', {
        'botId': bot_id,
        'botToken': bot_token,
        'fields': {'eventMode': 'webhook', 'webhookUrl': handler},
    })
    if not result.get('result'):
        raise SystemExit('Bot webhook update failed: ' + str(result.get('error', 'unknown')))

print('Event token rotated and bot webhook updated')
