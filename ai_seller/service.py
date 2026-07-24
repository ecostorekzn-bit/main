from . import store
from .bitrix import BitrixClient
from .config import settings
from .engine import classify_attachment, decide_stage, extract_facts, generate_reply, search_knowledge


def handle_message(conversation_id: str, text: str, attachments: list[dict] | None = None,
                   deal_id: int | None = None, bot_id: int | None = None, dialog_id: str | None = None) -> dict:
    attachments = attachments or []
    state = extract_facts(text, store.load_state(conversation_id))
    for item in attachments:
        item['kind'] = classify_attachment(item.get('name', ''), text, state)
    stage = decide_stage(state, attachments)
    state['stage'] = stage
    store.add_message(conversation_id, 'user', text, {'attachments': attachments})
    history = store.recent_messages(conversation_id)
    reply = generate_reply(state, history, search_knowledge(text), attachments)
    store.add_message(conversation_id, 'assistant', reply['reply'], {'shadow': settings.mode == 'shadow'})
    store.save_state(conversation_id, state)

    bitrix_actions = []
    client = BitrixClient()
    if deal_id:
        fields = {}
        stage_map = {'new': settings.stage_new, 'needs': settings.stage_needs,
                     'visual': settings.stage_visual, 'calc': settings.stage_calc}
        if stage_map.get(stage): fields['STAGE_ID'] = stage_map[stage]
        if settings.field_request and state.get('facts', {}).get('request'):
            fields[settings.field_request] = state['facts']['request']
        if fields: bitrix_actions.append(client.update_deal(deal_id, fields))
    if settings.mode == 'auto' and bot_id and dialog_id and not reply.get('needs_human'):
        bitrix_actions.append(client.send_bot_message(bot_id, dialog_id, reply['reply']))
    return {'mode': settings.mode, 'state': state, 'attachments': attachments,
            'reply': reply, 'bitrix_actions': bitrix_actions}

