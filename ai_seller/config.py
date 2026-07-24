from dataclasses import dataclass
from pathlib import Path
import os


def _load_dotenv(path: Path = Path('.env')) -> None:
    if not path.exists():
        return
    for line in path.read_text(encoding='utf-8').splitlines():
        line = line.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        os.environ.setdefault(key.strip(), value.strip())


_load_dotenv()


def _load_secret(value: str, file_path: str) -> str:
    if value:
        return value
    if not file_path:
        return ''
    path = Path(file_path)
    return path.read_text(encoding='utf-8').strip() if path.is_file() else ''


@dataclass(frozen=True)
class Settings:
    host: str = os.getenv('APP_HOST', '127.0.0.1')
    port: int = int(os.getenv('APP_PORT', '8080'))
    mode: str = os.getenv('APP_MODE', 'shadow')
    llm_base_url: str = os.getenv('LLM_BASE_URL', 'https://api.deepseek.com').rstrip('/')
    llm_api_key: str = _load_secret(os.getenv('LLM_API_KEY', ''), os.getenv('LLM_API_KEY_FILE', ''))
    llm_project_id: str = os.getenv('LLM_PROJECT_ID', '')
    llm_model: str = os.getenv('LLM_MODEL', 'deepseek-v4-flash')
    bitrix_webhook_base: str = os.getenv('BITRIX_WEBHOOK_BASE', '').rstrip('/')
    stage_new: str = os.getenv('BITRIX_STAGE_NEW', '')
    stage_needs: str = os.getenv('BITRIX_STAGE_NEEDS', '')
    stage_visual: str = os.getenv('BITRIX_STAGE_VISUAL', '')
    stage_calc: str = os.getenv('BITRIX_STAGE_CALC', '')
    field_request: str = os.getenv('BITRIX_FIELD_REQUEST', '')
    field_object_photo: str = os.getenv('BITRIX_FIELD_OBJECT_PHOTO', '')
    field_reference: str = os.getenv('BITRIX_FIELD_REFERENCE', '')
    field_logo: str = os.getenv('BITRIX_FIELD_LOGO', '')


settings = Settings()
