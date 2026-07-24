import json
from ai_seller.bitrix import BitrixClient


client = BitrixClient()
if not client.enabled:
    raise SystemExit('Сначала заполните BITRIX_WEBHOOK_BASE в .env')

result = {
    'deal_fields': client.call('crm.deal.fields', {}),
    'deal_stages': client.call('crm.status.list', {'filter': {'ENTITY_ID': 'DEAL_STAGE'}}),
}
print(json.dumps(result, ensure_ascii=False, indent=2))
