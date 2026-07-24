#!/usr/bin/env python3
"""Enable bot on all 3 open lines"""

import os
import json
import urllib.request
import urllib.error
from datetime import datetime

def load_env(path: str = ".env") -> dict:
    """Load environment variables from .env file"""
    env = {}
    if os.path.isfile(path):
        with open(path, "r", encoding="utf-8") as f:
            for line in f:
                if line.strip() and not line.lstrip().startswith("#") and "=" in line:
                    key, value = line.split("=", 1)
                    env[key.strip()] = value.strip().strip('"').strip("'")
    return env

def log_deployment(message: str) -> None:
    """Log deployment event"""
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    log_entry = f"[{timestamp}] {message}\n"

    os.makedirs("logs", exist_ok=True)
    with open("logs/deployment.log", "a", encoding="utf-8") as f:
        f.write(log_entry)

    print(log_entry.strip())

def enable_bot_on_line(webhook_base: str, line_id: int, line_name: str) -> bool:
    """Enable bot on a specific line"""
    try:
        # Build URL with query parameters
        base_url = webhook_base.rstrip('/') + '/imopenlines.config.update.json'
        params = {
            "CONFIG_ID": str(line_id),
            "OPEN_LINE_ID": str(line_id),
            "WELCOME_BOT_ENABLE": "Y",
            "WELCOME_BOT_ID": "19354",
            "WELCOME_BOT_JOIN": "always",
        }

        # Build URL with encoded parameters
        from urllib.parse import urlencode
        url = base_url + "?" + urlencode(params)

        req = urllib.request.Request(url, method='POST')

        response = urllib.request.urlopen(req, timeout=30)
        result = json.loads(response.read().decode('utf-8'))

        if result.get('result') or (isinstance(result.get('result'), dict)):
            log_deployment(f"OK: Line {line_id} ({line_name}) enabled - bot assigned as welcome bot")
            print(f"  Response: {result}")
            return True
        else:
            error = result.get('error', 'Unknown error')
            log_deployment(f"ERROR: Line {line_id} ({line_name}) - {error}")
            print(f"  Response: {result}")
            return False

    except urllib.error.HTTPError as e:
        response_text = e.read().decode('utf-8')
        log_deployment(f"ERROR: Line {line_id} ({line_name}) - HTTP {e.code}: {response_text}")
        return False
    except Exception as e:
        log_deployment(f"ERROR: Line {line_id} ({line_name}) - {str(e)}")
        return False

def main():
    """Enable bot on all 3 lines"""
    env = load_env()
    webhook_base = env.get("BITRIX_WEBHOOK_BASE", "")

    if not webhook_base:
        print("ERROR: BITRIX_WEBHOOK_BASE not found in .env")
        return 1

    log_deployment("Starting bot deployment on all lines...")

    # Define lines: (line_id, line_name)
    lines = [
        (12, "MAX"),
        (4, "VK"),
        (10, "Avito"),
    ]

    success_count = 0
    for line_id, line_name in lines:
        if enable_bot_on_line(webhook_base, line_id, line_name):
            success_count += 1

    log_deployment(f"Deployment complete: {success_count}/3 lines enabled")

    if success_count == 3:
        print("\n[OK] All 3 lines enabled successfully!")
        return 0
    else:
        print(f"\n[WARNING] Only {success_count}/3 lines enabled")
        return 1

if __name__ == "__main__":
    import sys
    sys.exit(main())
