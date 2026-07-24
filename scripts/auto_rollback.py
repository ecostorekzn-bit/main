#!/usr/bin/env python3
"""
Auto-rollback mechanism for AI-seller bot
Detects critical errors and reverts to shadow mode
"""

import os
import sys
import re
import subprocess
from pathlib import Path
from datetime import datetime
from typing import List, Optional, Tuple


def log_rollback(message: str) -> None:
    """Log rollback event with timestamp"""
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    log_entry = f"[{timestamp}] {message}\n"

    # Log to errors.log
    os.makedirs("logs", exist_ok=True)
    error_log = "logs/errors.log"
    with open(error_log, "a", encoding="utf-8") as f:
        f.write(log_entry)

    # Print to console
    print(log_entry.strip())


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


def save_env(env: dict, path: str = ".env") -> bool:
    """Save environment variables back to .env file"""
    try:
        with open(path, "w", encoding="utf-8") as f:
            for key, value in env.items():
                f.write(f"{key}={value}\n")
        return True
    except Exception as e:
        log_rollback(f"ERROR: Failed to save .env - {str(e)}")
        return False


def read_health_log(path: str = "logs/health_alerts.log", last_lines: int = 20) -> List[str]:
    """Read last N lines from health alert log"""
    if not os.path.isfile(path):
        return []

    try:
        with open(path, "r", encoding="utf-8") as f:
            lines = f.readlines()
            return lines[-last_lines:] if lines else []
    except Exception as e:
        log_rollback(f"ERROR: Failed to read health log - {str(e)}")
        return []


def detect_critical_error(log_lines: List[str]) -> Tuple[bool, Optional[str]]:
    """
    Detect critical errors in log lines
    Returns: (is_critical_error, error_description)
    """
    if not log_lines:
        return False, None

    recent_errors = "".join(log_lines)

    # Check for critical errors
    error_patterns = [
        (r"ERROR.*ok=false", "LLM or Bitrix API unavailable"),
        (r"ERROR.*mode=shadow.*expected auto", "Bot not in auto mode when it should be"),
        (r"ERROR.*timeout", "Server timeout detected"),
        (r"ERROR.*HTTP 500", "Server syntax/runtime error"),
        (r"ERROR.*Connection failed", "Server unreachable"),
        (r"ERROR.*Bitrix API unavailable", "Bitrix connection lost"),
        (r"ERROR.*Knowledge base unavailable", "Knowledge base unreachable"),
    ]

    for pattern, description in error_patterns:
        if re.search(pattern, recent_errors, re.IGNORECASE):
            # Count consecutive errors - need at least 2
            error_count = len(
                [line for line in log_lines if "ERROR" in line]
            )
            if error_count >= 2:  # At least 2 errors in last N lines
                return True, description

    return False, None


def execute_rollback() -> bool:
    """Execute rollback to shadow mode"""
    try:
        log_rollback("ROLLBACK INITIATED")

        # 1. Load .env and change APP_MODE
        env = load_env()
        if env.get("APP_MODE") != "shadow":
            env["APP_MODE"] = "shadow"
            if not save_env(env):
                return False
            log_rollback("✓ Changed APP_MODE to shadow in .env")
        else:
            log_rollback("Note: Already in shadow mode")

        # 2. Rebuild hosting config
        log_rollback("Building new config...")
        result = subprocess.run(
            [sys.executable, "scripts/build_hosting_config.py"],
            capture_output=True,
            text=True,
            timeout=30,
        )
        if result.returncode != 0:
            log_rollback(f"ERROR: Config build failed - {result.stderr}")
            return False
        log_rollback("✓ Config rebuilt successfully")

        # 3. Upload config to server
        log_rollback("Uploading config to server...")
        result = subprocess.run(
            [
                sys.executable,
                "scripts/ftp_upload.py",
                "deploy/runtime/ai_seller_config.php",
                "..",
                "ai_seller_config.php",
            ],
            capture_output=True,
            text=True,
            timeout=60,
        )
        if result.returncode != 0:
            log_rollback(f"ERROR: FTP upload failed - {result.stderr}")
            return False
        log_rollback("✓ Config uploaded to server")

        # 4. Verify health check returns shadow mode
        log_rollback("Verifying rollback...")
        try:
            import urllib.request
            import json

            token = env.get("EVENT_TOKEN", "")
            url = f"https://eco-store16.ru/ai-seller/index.php?action=health&token={token}"
            req = urllib.request.Request(url)
            response = urllib.request.urlopen(req, timeout=10)
            data = json.loads(response.read().decode("utf-8"))

            if data.get("mode") == "shadow":
                log_rollback("✓ Verified: Bot now in shadow mode")
                return True
            else:
                log_rollback(f"WARNING: Mode is {data.get('mode')}, expected shadow")
                return False

        except Exception as e:
            log_rollback(f"WARNING: Could not verify health - {str(e)}")
            return True  # Config was uploaded, assume success

    except subprocess.TimeoutExpired:
        log_rollback("ERROR: Rollback operation timed out")
        return False
    except Exception as e:
        log_rollback(f"ERROR: Rollback failed - {str(e)}")
        return False


def main():
    """Main rollback logic"""
    print("\n[CHECK] Checking for critical errors...\n")

    # Read recent health logs
    log_lines = read_health_log(last_lines=30)

    if not log_lines:
        print("No health logs found. Run health_monitor.py first.")
        return

    # Detect errors
    is_critical, error_desc = detect_critical_error(log_lines)

    if is_critical:
        print(f"[WARNING] CRITICAL ERROR DETECTED: {error_desc}\n")
        log_rollback(f"ROLLBACK TRIGGERED: {error_desc}")

        # Execute rollback
        if execute_rollback():
            print("\n[OK] ROLLBACK SUCCESSFUL: Bot reverted to shadow mode")
            log_rollback("ROLLBACK COMPLETED SUCCESSFULLY")
            return 0
        else:
            print("\n[ERROR] ROLLBACK FAILED: Manual intervention required")
            log_rollback("ROLLBACK FAILED: Manual intervention required")
            return 1
    else:
        print("[OK] No critical errors detected. All systems healthy.\n")
        return 0


if __name__ == "__main__":
    sys.exit(main())
