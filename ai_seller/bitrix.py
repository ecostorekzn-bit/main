import json
import urllib.request
import urllib.error
from .config import settings


class BitrixClient:
    def __init__(self, base: str | None = None):
        self.base = (base or settings.bitrix_webhook_base).rstrip('/')

    @property
    def enabled(self):
        return bool(self.base)

    def call(self, method: str, params: dict) -> dict:
        if not self.enabled:
            return {'mock': True, 'method': method, 'params': params}
        data = json.dumps(params, ensure_ascii=False).encode('utf-8')
        req = urllib.request.Request(f'{self.base}/{method}.json', data=data,
                                     headers={'Content-Type': 'application/json'})
        try:
            with urllib.request.urlopen(req, timeout=20) as response:
                return json.loads(response.read().decode('utf-8'))
        except urllib.error.HTTPError as exc:
            raw = exc.read().decode('utf-8', errors='replace')
            try:
                payload = json.loads(raw)
            except json.JSONDecodeError:
                payload = {'error': f'HTTP_{exc.code}', 'error_description': raw[:500]}
            payload.setdefault('http_status', exc.code)
            return payload

    def update_deal(self, deal_id: int, fields: dict) -> dict:
        return self.call('crm.deal.update', {'id': deal_id, 'fields': fields})

    def send_bot_message(self, bot_id: int, dialog_id: str, text: str) -> dict:
        return self.call('imbot.message.add', {'BOT_ID': bot_id, 'DIALOG_ID': dialog_id, 'MESSAGE': text})
