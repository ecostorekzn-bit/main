import json
import re
import urllib.request
from pathlib import Path
from .config import settings


SYSTEM_RULES = """Ты продавец-консультант Eco-Store по имени Алина.
Пиши естественно, тепло и уверенно. Обычно 1-3 коротких абзаца и один вопрос за раз.
Не используй длинные тире, канцелярит, заголовки и списки без необходимости.
Не повторяй уже известные клиентские данные. Не придумывай цены, скидки, сроки и характеристики.
Цены берутся только из калькулятора. Гарантия на ягель 5 лет.
Старайся привести клиента к бесплатной визуализации, но если он просит сначала цену, собери параметры для расчёта.
Не дави, не унижай конкурентов и не обещай скидку без разрешённого правила.
Если информации недостаточно или ситуация рискованная, поставь needs_human=true.
Верни только JSON: {"reply":"...","needs_human":false,"reason":""}."""


def extract_facts(text: str, state: dict) -> dict:
    low = text.lower()
    result = dict(state)
    result.setdefault('facts', {})
    facts = result['facts']
    facts.setdefault('request', text[:500])
    cities = ['казань','москва','самара','уфа','санкт-петербург','екатеринбург','новосибирск']
    for city in cities:
        if city in low:
            facts['city'] = city.title()
    size = re.search(r'(\d+(?:[.,]\d+)?)\s*(?:м|см)?\s*(?:x|х|×|на)\s*(\d+(?:[.,]\d+)?)\s*(м|см)?', low)
    if size:
        first, second, unit = size.groups()
        facts['size'] = f'{first} × {second}' + (f' {unit}' if unit else '')
    budget = re.search(r'(?:бюджет|рассчитывал[аи]?|до)\D{0,20}(\d[\d\s]{3,})', low)
    if budget:
        facts['budget'] = re.sub(r'\s+', '', budget.group(1))
    if 'без рам' in low: facts['frame'] = 'без рамы'
    elif 'рам' in low: facts['frame'] = 'нужна/обсуждается'
    if 'без подсвет' in low: facts['lighting'] = 'без подсветки'
    elif 'подсвет' in low: facts['lighting'] = 'нужна/обсуждается'
    if any(x in low for x in ['логотип','лого']): facts['product'] = 'панно с логотипом'
    elif 'панно' in low: facts['product'] = 'панно'
    if any(x in low for x in ['фото стены','фото объекта']): facts['expects_object_photo'] = True
    if any(x in low for x in ['скажите цену','сколько стоит','сначала цену','примерный бюджет']):
        result['route'] = 'price_first'
    return result


def classify_attachment(filename: str, message_text: str, state: dict) -> str:
    low = (filename + ' ' + message_text).lower()
    if any(x in low for x in ['логотип','logo','лого']): return 'logo'
    if any(x in low for x in ['пример','референс','нравится']): return 'reference'
    if state.get('facts', {}).get('expects_object_photo') or any(x in low for x in ['стена','объект','помещение']):
        return 'object_photo'
    return 'unknown'


def decide_stage(state: dict, attachments: list[dict]) -> str:
    kinds = {a.get('kind') for a in attachments}
    if 'object_photo' in kinds:
        return 'visual'
    if state.get('route') == 'price_first':
        facts = state.get('facts', {})
        if facts.get('size') and facts.get('product'):
            return 'calc'
    if state.get('facts', {}).get('request'):
        return 'needs'
    return 'new'


def _fallback_reply(state: dict, attachments: list[dict]) -> dict:
    kinds = {a.get('kind') for a in attachments}
    if 'object_photo' in kinds:
        return {'reply': 'Спасибо, фото получила) Передаю информацию дизайнеру. В течение суток подготовим визуал и пришлём вам на согласование💚', 'needs_human': False, 'reason': 'object photo received'}
    if 'unknown' in kinds:
        return {'reply': 'Спасибо) Подскажите, пожалуйста, это фото стены, на которой планируете разместить панно, или пример, который вам нравится?', 'needs_human': False, 'reason': 'attachment clarification'}
    facts = state.get('facts', {})
    if not facts.get('product'):
        return {'reply': 'Добрый день) Меня зовут Алина, студия премиум озеленения Eco-Store🌿 Какое изделие вы рассматриваете?', 'needs_human': False, 'reason': 'need product'}
    if not facts.get('size'):
        return {'reply': 'Подскажите, пожалуйста, примерный размер изделия? Тогда я смогу сориентировать по вариантам)', 'needs_human': False, 'reason': 'need size'}
    return {'reply': 'А рама и подсветка нужны к панно? Я подготовлю подходящие варианты)', 'needs_human': False, 'reason': 'need configuration'}


def generate_reply(state: dict, history: list[dict], knowledge: list[str], attachments: list[dict]) -> dict:
    if not settings.llm_api_key:
        return _fallback_reply(state, attachments)
    payload = {
        'model': settings.llm_model,
        'temperature': 0.35,
        'response_format': {'type': 'json_object'},
        'messages': [
            {'role': 'system', 'content': SYSTEM_RULES},
            {'role': 'system', 'content': 'Известные данные: ' + json.dumps(state, ensure_ascii=False)},
            {'role': 'system', 'content': 'Подходящие фрагменты базы: ' + '\n\n'.join(knowledge[:8])},
            *[{'role': m['role'], 'content': m['text']} for m in history[-12:]],
        ]
    }
    req = urllib.request.Request(settings.llm_base_url + '/chat/completions',
        data=json.dumps(payload).encode('utf-8'),
        headers={
            'Authorization': 'Bearer ' + settings.llm_api_key,
            'Content-Type': 'application/json',
            **({'OpenAI-Project': settings.llm_project_id} if settings.llm_project_id else {}),
        })
    with urllib.request.urlopen(req, timeout=30) as response:
        data = json.loads(response.read().decode('utf-8'))
    return json.loads(data['choices'][0]['message']['content'])


def search_knowledge(query: str, limit: int = 8) -> list[str]:
    path = Path('data/knowledge.json')
    if not path.exists(): return []
    items = json.loads(path.read_text(encoding='utf-8'))
    words = {w for w in re.findall(r'[а-яёa-z]{4,}', query.lower())}
    scored = []
    for item in items:
        text = item['text']
        score = sum(1 for w in words if w in text.lower())
        if score: scored.append((score, text))
    return [text for _, text in sorted(scored, reverse=True)[:limit]]
