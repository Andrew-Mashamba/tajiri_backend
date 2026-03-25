#!/usr/bin/env python3
"""
Tajiri AI Assistant — task-capable agent that can read code, query the database,
run commands, and generate reports for the frontend.

Runs inside /var/www/html/tajiri so Claude has full project context.
Listens on port 8100 (configurable via AI_PORT env var).

Usage:
    python3 scripts/ai-assistant.py

Nginx proxies /api/ai/ask -> http://127.0.0.1:8100/ask
"""

import json
import os
import subprocess
import sys
from http.server import HTTPServer, BaseHTTPRequestHandler

PORT = int(os.environ.get("AI_PORT", 8100))
AGENT_BIN = os.environ.get("AGENT_CLI_PATH", "/usr/local/bin/agent")
PROJECT_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MAX_PROMPT_LEN = 4000
TIMEOUT = 600


class AiHandler(BaseHTTPRequestHandler):
    """Handle POST /ask requests."""

    def do_POST(self):
        if self.path != "/ask":
            self._json_response(404, {"success": False, "message": "Not found"})
            return

        # ── Read body ────────────────────────────────────────────
        content_length = int(self.headers.get("Content-Length", 0))
        if content_length == 0 or content_length > 65536:
            self._json_response(400, {"success": False, "message": "Invalid request body."})
            return

        try:
            body = json.loads(self.rfile.read(content_length))
        except (json.JSONDecodeError, UnicodeDecodeError):
            self._json_response(400, {"success": False, "message": "Invalid JSON."})
            return

        prompt = (body.get("prompt") or "").strip()
        if not prompt:
            self._json_response(400, {"success": False, "message": "prompt is required."})
            return
        if len(prompt) > MAX_PROMPT_LEN:
            self._json_response(400, {
                "success": False,
                "message": f"prompt exceeds {MAX_PROMPT_LEN} characters.",
            })
            return

        # ── Call Agent CLI ───────────────────────────────────────
        env = {**os.environ, "HOME": "/var/www"}

        cmd = [
            AGENT_BIN, "-p",
            "--model", "auto",
            "--force",
            "--trust",
            "--workspace", PROJECT_DIR,
            prompt,
        ]

        try:
            result = subprocess.run(
                cmd,
                capture_output=True,
                text=True,
                timeout=TIMEOUT,
                cwd=PROJECT_DIR,
                env=env,
            )
        except subprocess.TimeoutExpired:
            self._json_response(504, {
                "success": False,
                "message": "Request timed out. Please try a simpler question.",
            })
            return
        except Exception as e:
            print(f"[ERROR] subprocess failed: {e}", file=sys.stderr)
            self._json_response(503, {
                "success": False,
                "message": "AI assistant is temporarily unavailable.",
            })
            return

        if result.returncode != 0:
            print(f"[ERROR] agent exit {result.returncode}: {result.stderr}",
                  file=sys.stderr)
            self._json_response(503, {
                "success": False,
                "message": "AI assistant is temporarily unavailable.",
            })
            return

        answer = result.stdout.strip()
        if not answer:
            self._json_response(422, {
                "success": False,
                "message": "No response generated. Please rephrase your question.",
            })
            return

        self._json_response(200, {"success": True, "data": {"answer": answer}})

    def do_GET(self):
        """Health check."""
        if self.path == "/health":
            self._json_response(200, {"status": "ok"})
            return
        self._json_response(404, {"success": False, "message": "Not found"})

    def do_OPTIONS(self):
        """CORS preflight."""
        self.send_response(204)
        self._cors_headers()
        self.end_headers()

    def _json_response(self, status, data):
        body = json.dumps(data).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self._cors_headers()
        self.end_headers()
        self.wfile.write(body)

    def _cors_headers(self):
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "POST, GET, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")

    def log_message(self, fmt, *args):
        print(f"[AI] {self.client_address[0]} - {fmt % args}", file=sys.stderr)


def main():
    os.chdir(PROJECT_DIR)
    server = HTTPServer(("127.0.0.1", PORT), AiHandler)
    print(f"[AI] Tajiri AI Assistant listening on 127.0.0.1:{PORT}")
    print(f"[AI] Project dir: {PROJECT_DIR}")
    print(f"[AI] Agent bin:   {AGENT_BIN}")
    print(f"[AI] Model: auto")
    print(f"[AI] Timeout: {TIMEOUT}s | Max prompt: {MAX_PROMPT_LEN} chars")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n[AI] Shutting down.")
        server.server_close()


if __name__ == "__main__":
    main()
