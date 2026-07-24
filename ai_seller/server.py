from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from .config import settings
from .service import handle_message


class Handler(BaseHTTPRequestHandler):
    def _send(self, status: int, payload: dict):
        body = json.dumps(payload, ensure_ascii=False).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == '/health':
            self._send(200, {'ok': True, 'mode': settings.mode})
        else:
            self._send(404, {'error': 'not found'})

    def do_POST(self):
        try:
            length = int(self.headers.get('Content-Length', '0'))
            body = json.loads(self.rfile.read(length) or b'{}')
            if self.path == '/test/message':
                result = handle_message(body['conversation_id'], body.get('text', ''),
                    body.get('attachments', []), body.get('deal_id'), body.get('bot_id'), body.get('dialog_id'))
                self._send(200, result)
                return
            if self.path == '/bitrix/events':
                data = body.get('data', body)
                params = data.get('PARAMS', data.get('params', {}))
                result = handle_message(
                    str(params.get('DIALOG_ID') or params.get('CHAT_ID') or 'unknown'),
                    params.get('MESSAGE', ''),
                    params.get('FILES', []),
                    body.get('deal_id'), params.get('BOT_ID'), params.get('DIALOG_ID'))
                self._send(200, result)
                return
            self._send(404, {'error': 'not found'})
        except Exception as exc:
            self._send(500, {'error': type(exc).__name__, 'detail': str(exc)})

    def log_message(self, format, *args):
        return


def main():
    server = ThreadingHTTPServer((settings.host, settings.port), Handler)
    print(f'AI seller listening on http://{settings.host}:{settings.port} ({settings.mode})')
    server.serve_forever()


if __name__ == '__main__':
    main()

