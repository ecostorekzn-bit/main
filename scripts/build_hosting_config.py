from pathlib import Path
import os
import secrets
from urllib.parse import quote


def load_env(path=Path('.env')):
    for line in path.read_text(encoding='utf-8-sig').splitlines():
        if line.strip() and not line.lstrip().startswith('#') and '=' in line:
            key, value = line.split('=', 1)
            os.environ[key.strip()] = value.strip()


load_env()
token = os.environ.get('EVENT_TOKEN') or secrets.token_urlsafe(32)
llm_api_key = os.environ.get('LLM_API_KEY', '')
llm_api_key_file = os.environ.get('LLM_API_KEY_FILE', '')
if not llm_api_key and llm_api_key_file:
    secret_path = Path(llm_api_key_file)
    if secret_path.is_file():
        llm_api_key = secret_path.read_text(encoding='utf-8').strip()
path = Path('.env')
content = path.read_text(encoding='utf-8-sig')
if 'EVENT_TOKEN=' not in content:
    path.write_text(content.rstrip() + f'\nEVENT_TOKEN={token}\n', encoding='utf-8')

def php(value):
    return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"

config = f"""<?php
return [
    'mode' => {php(os.environ.get('APP_MODE', 'shadow'))},
    'event_token' => {php(token)},
    'bitrix_webhook_base' => {php(os.environ['BITRIX_WEBHOOK_BASE'].rstrip('/'))},
    'llm_base_url' => {php(os.environ.get('LLM_BASE_URL', 'https://api.deepseek.com').rstrip('/'))},
    'llm_api_key' => {php(llm_api_key)},
    'llm_project_id' => {php(os.environ.get('LLM_PROJECT_ID', ''))},
    'llm_model' => {php(os.environ.get('LLM_MODEL', 'deepseek-v4-flash'))},
    'bot_id' => {int(os.environ.get('BITRIX_BOT_ID', '0') or 0)},
    'bot_token' => {php(os.environ.get('BOT_TOKEN', ''))},
    'data_dir' => dirname(__FILE__) . '/ai_seller_data',
    'stage_new' => 'NEW',
    'stage_needs' => 'UC_1DYGNY',
    'stage_visual' => 'UC_E9DIIK',
    'stage_calc' => 'UC_SIEUN3',
    'field_request' => 'UF_CRM_1776768562835',
    'field_object_photo' => 'UF_CRM_1776874310138',
];
"""
out = Path('deploy/runtime/ai_seller_config.php')
out.parent.mkdir(parents=True, exist_ok=True)
out.write_text(config, encoding='utf-8')
robot_url = 'https://eco-store16.ru/ai-seller/?action=prepare_deal&token=' + quote(token, safe='')
Path('.robot-webhook-url.txt').write_text(robot_url, encoding='utf-8')
print('Runtime config created (secrets hidden)')
