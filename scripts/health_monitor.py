#!/usr/bin/env python3
"""
Health Monitor for AI-seller bot
Checks every 5 minutes if the bot is healthy and logs issues
"""

import os
import time
import json
import urllib.request
import urllib.error
from pathlib import Path
from datetime import datetime
from typing import Optional, Dict, Tuple


def load_env(path: str = ".env") -> Dict[str, str]:
    """Load environment variables from .env file"""
    env = {}
    if os.path.isfile(path):
        with open(path, "r", encoding="utf-8") as f:
            for line in f:
                if line.strip() and not line.lstrip().startswith("#") and "=" in line:
                    key, value = line.split("=", 1)
                    env[key.strip()] = value.strip().strip('"').strip("'")
    return env


def ensure_logs_dir() -> str:
    """Create logs directory if it doesn't exist, return path"""
    logs_dir = "logs"
    os.makedirs(logs_dir, exist_ok=True)
    return logs_dir


def log_message(logs_dir: str, message: str, is_error: bool = False) -> None:
    """Log message with timestamp"""
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    log_entry = f"[{timestamp}] {message}\n"

    # Log to health_alerts.log
    alert_log = os.path.join(logs_dir, "health_alerts.log")
    with open(alert_log, "a", encoding="utf-8") as f:
        f.write(log_entry)

    # Also log errors to errors.log
    if is_error:
        error_log = os.path.join(logs_dir, "errors.log")
        with open(error_log, "a", encoding="utf-8") as f:
            f.write(log_entry)

    # Print to console
    print(log_entry.strip())


def check_health(
    url: str, token: str, logs_dir: str, timeout: int = 10
) -> Tuple[bool, Optional[str]]:
    """
    Check bot health endpoint
    Returns: (is_healthy, error_message)
    """
    try:
        full_url = f"{url}?action=health&token={token}"

        # Make request with urllib
        req = urllib.request.Request(full_url)
        response = urllib.request.urlopen(req, timeout=timeout)
        response_data = response.read().decode('utf-8')

        # Parse JSON response
        try:
            data = json.loads(response_data)
        except json.JSONDecodeError:
            error = "ERROR: Server returned invalid JSON"
            log_message(logs_dir, error, is_error=True)
            return False, error

        # Check critical fields
        ok = data.get("ok", False)
        mode = data.get("mode", "")
        php = data.get("php", "?")
        bitrix_ok = data.get("bitrix_ok", False)
        knowledge_ok = data.get("knowledge_ok", False)

        if not ok:
            error = f"ERROR: ok=false (PHP {php})"
            log_message(logs_dir, error, is_error=True)
            return False, error

        if mode != "auto":
            error = f"ERROR: mode={mode} (expected auto)"
            log_message(logs_dir, error, is_error=True)
            return False, error

        if not bitrix_ok:
            error = "ERROR: Bitrix API unavailable"
            log_message(logs_dir, error, is_error=True)
            return False, error

        if not knowledge_ok:
            error = "ERROR: Knowledge base unavailable"
            log_message(logs_dir, error, is_error=True)
            return False, error

        # All checks passed
        msg = f"OK: Health check passed (PHP {php}, Bitrix OK, Knowledge OK)"
        log_message(logs_dir, msg, is_error=False)
        return True, None

    except urllib.error.HTTPError as e:
        error = f"ERROR: Server returned HTTP {e.code}"
        log_message(logs_dir, error, is_error=True)
        return False, error

    except urllib.error.URLError as e:
        if "timed out" in str(e).lower():
            error = "ERROR: Health check timeout (>10s)"
        else:
            error = f"ERROR: Connection failed - {str(e)}"
        log_message(logs_dir, error, is_error=True)
        return False, error

    except Exception as e:
        error = f"ERROR: Unexpected error - {str(e)}"
        log_message(logs_dir, error, is_error=True)
        return False, error


def main():
    """Main monitoring loop"""
    # Load configuration
    env = load_env()
    token = env.get("EVENT_TOKEN", "")
    base_url = "https://eco-store16.ru/ai-seller/index.php"

    if not token:
        print("ERROR: EVENT_TOKEN not found in .env")
        return

    logs_dir = ensure_logs_dir()
    log_message(logs_dir, "Health monitor started")

    check_interval = 5 * 60  # 5 minutes in seconds
    consecutive_failures = 0
    max_failures_before_alert = 2  # Alert after 2 consecutive failures

    try:
        while True:
            is_healthy, error_msg = check_health(base_url, token, logs_dir)

            if is_healthy:
                consecutive_failures = 0
            else:
                consecutive_failures += 1
                if consecutive_failures >= max_failures_before_alert:
                    # Send alert (in real implementation, would send email/SMS)
                    alert = f"ALERT: Bot unhealthy for {consecutive_failures} checks: {error_msg}"
                    log_message(logs_dir, alert, is_error=True)

            # Wait before next check
            time.sleep(check_interval)

    except KeyboardInterrupt:
        log_message(logs_dir, "Health monitor stopped by user")
    except Exception as e:
        log_message(logs_dir, f"ERROR: Monitor crashed - {str(e)}", is_error=True)
        raise


if __name__ == "__main__":
    main()
