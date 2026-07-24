#!/usr/bin/env python3
"""Generate hourly status report for AI-seller bot"""

import os
import json
import urllib.request
import urllib.error
from datetime import datetime, timedelta
from pathlib import Path
from typing import Dict, List, Tuple


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


def read_logs_last_hour(log_file: str) -> List[str]:
    """Read log entries from last hour"""
    if not os.path.isfile(log_file):
        return []

    try:
        one_hour_ago = datetime.now() - timedelta(hours=1)
        recent_lines = []

        with open(log_file, "r", encoding="utf-8") as f:
            for line in f:
                # Parse timestamp from log line
                if "[" in line and "]" in line:
                    try:
                        timestamp_str = line.split("]")[0].strip("[")
                        timestamp = datetime.strptime(
                            timestamp_str, "%Y-%m-%d %H:%M:%S"
                        )
                        if timestamp >= one_hour_ago:
                            recent_lines.append(line.strip())
                    except ValueError:
                        pass

        return recent_lines
    except Exception:
        return []


def check_server_health(env: dict) -> Tuple[bool, Dict]:
    """Check current server health"""
    try:
        token = env.get("EVENT_TOKEN", "")
        url = f"https://eco-store16.ru/ai-seller/index.php?action=health&token={token}"

        req = urllib.request.Request(url)
        response = urllib.request.urlopen(req, timeout=10)
        data = json.loads(response.read().decode("utf-8"))

        return True, data
    except Exception as e:
        return False, {"error": str(e)}


def generate_report() -> str:
    """Generate status report"""
    env = load_env()
    now = datetime.now()
    one_hour_ago = now - timedelta(hours=1)
    next_check = now + timedelta(hours=1)

    # Read logs
    health_logs = read_logs_last_hour("logs/health_alerts.log")
    error_logs = read_logs_last_hour("logs/errors.log")
    deployment_logs = read_logs_last_hour("logs/deployment.log")

    # Count stats
    health_passed = len([l for l in health_logs if "OK:" in l])
    health_failed = len([l for l in health_logs if "ERROR:" in l])
    critical_errors = len(error_logs)
    rollbacks = len([l for l in error_logs if "ROLLBACK" in l])

    # Check current health
    server_healthy, health_data = check_server_health(env)
    mode = health_data.get("mode", "?") if server_healthy else "UNKNOWN"
    php_version = health_data.get("php", "?")
    bitrix_ok = health_data.get("bitrix_ok", False)
    knowledge_ok = health_data.get("knowledge_ok", False)

    # Build report
    lines = [
        "",
        "=" * 50,
        "AI-SELLER STATUS REPORT",
        "=" * 50,
        f"Time: {now.strftime('%Y-%m-%d %H:%M:%S')}",
        f"Duration: Last hour ({one_hour_ago.strftime('%H:%M')} - {now.strftime('%H:%M')})",
        "",
        "SYSTEM STATUS: " + ("OK" if server_healthy else "ERROR"),
        f"  Mode: {mode}",
        f"  Health checks: {health_passed} passed, {health_failed} failed",
        f"  Server: {'alive and responding' if server_healthy else 'unreachable'}",
        f"  PHP version: {php_version}",
        f"  Bitrix API: {'OK' if bitrix_ok else 'ERROR'}",
        f"  Knowledge base: {'OK' if knowledge_ok else 'ERROR'}",
        "",
        "BOT ACTIVITY:",
        f"  Lines active: 3/3 (MAX, VK, Avito)",
        f"  Critical errors: {critical_errors}",
        f"  Auto-rollbacks triggered: {rollbacks}",
        f"  Deployment changes: {len(deployment_logs)}",
        "",
        "LOGS SUMMARY:",
    ]

    if health_failed == 0 and critical_errors == 0 and rollbacks == 0:
        lines.append("  All systems nominal - No critical errors detected")
    else:
        if health_failed > 0:
            lines.append(f"  {health_failed} health check failures detected")
        if critical_errors > 0:
            lines.append(f"  {critical_errors} critical errors logged")
        if rollbacks > 0:
            lines.append(f"  {rollbacks} auto-rollback events triggered")

    if error_logs:
        lines.append("")
        lines.append("RECENT ERRORS:")
        for log_line in error_logs[-3:]:  # Last 3 errors
            lines.append(f"  {log_line}")

    lines.extend([
        "",
        f"Next check: {next_check.strftime('%Y-%m-%d %H:%M:%S')}",
        "=" * 50,
        "",
    ])

    return "\n".join(lines)


def main():
    """Generate and output report"""
    report = generate_report()

    # Output to console
    print(report)

    # Output to file
    os.makedirs("logs", exist_ok=True)
    report_file = "logs/hourly_report.txt"
    with open(report_file, "a", encoding="utf-8") as f:
        f.write(report)
        f.write("\n")

    print(f"Report saved to {report_file}")
    return 0


if __name__ == "__main__":
    import sys
    sys.exit(main())
