#!/usr/bin/env python3
"""Stateless OCR service for the ocr_search Nextcloud app.

POST one image to /v1/ocr with a bearer token and get recognised text lines
with boxes back. Engine: PP-OCRv5 mobile through RapidOCR + ONNX Runtime.
The model is loaded on the first request and released after an idle period.

Environment:
  OCR_TOKEN / OCR_TOKEN_FILE  shared secret (the file wins; required)
  OCR_BIND            default 0.0.0.0
  OCR_PORT            default 8080
  OCR_MAX_SIDE        default long side after downscaling, default 1024
  OCR_THREADS         ONNX Runtime intra-op threads, default 2
  OCR_IDLE_SECONDS    unload the model after this idle time, default 300
                      (0 = never unload)
  OCR_MAX_BYTES       request body limit, default 50 MiB
  OCR_MAX_PIXELS      decoded image limit, default 100000000
"""
import ctypes
import gc
import hmac
import io
import json
import os
import signal
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

import numpy as np
from PIL import Image, ImageOps

ENGINE_NAME = 'ppocrv5-mobile'
MIN_SIDE, MAX_SIDE = 256, 4096
ORIENTATION_TAG = 274
RETRY_AFTER = '30'

try:  # HEIC/HEIF support is optional at import time.
    import pillow_heif
    pillow_heif.register_heif_opener()
except ImportError:
    pass


class ImageError(Exception):
    """The request image is unusable (maps to 422)."""


class EngineError(Exception):
    """The engine is unavailable for reasons unrelated to the image (503)."""


def create_engine(threads):
    """Build the real RapidOCR engine. Tests replace this factory."""
    from rapidocr import LangDet, LangRec, ModelType, OCRVersion, RapidOCR
    return RapidOCR(params={
        'Global.log_level': 'error',
        'Det.ocr_version': OCRVersion.PPOCRV5, 'Det.lang_type': LangDet.CH,
        'Det.model_type': ModelType.MOBILE,
        'Rec.ocr_version': OCRVersion.PPOCRV5, 'Rec.lang_type': LangRec.CH,
        'Rec.model_type': ModelType.MOBILE,
        'EngineConfig.onnxruntime.intra_op_num_threads': threads,
        'EngineConfig.onnxruntime.inter_op_num_threads': 1,
    })


def release_memory():
    gc.collect()
    try:  # glibc only; a no-op on musl, macOS and Windows
        ctypes.CDLL('libc.so.6').malloc_trim(0)
    except (OSError, AttributeError):
        pass


def clamp_side(value, default):
    try:
        number = int(value)
    except (TypeError, ValueError):
        return default
    return max(MIN_SIDE, min(MAX_SIDE, number))


def load_image(data, max_side, max_pixels):
    """Decode to RGB. Returns (image, width, height); width/height are the
    EXIF-oriented dimensions before downscaling."""
    Image.MAX_IMAGE_PIXELS = max_pixels
    try:
        image = Image.open(io.BytesIO(data))
        width, height = image.size
        if width * height > max_pixels:
            raise ImageError('image has too many pixels')
        if image.getexif().get(ORIENTATION_TAG) in (5, 6, 7, 8):
            width, height = height, width
        # Downscale before orientation so JPEG draft mode saves memory.
        if max(image.size) > max_side:
            image.thumbnail((max_side, max_side), Image.LANCZOS)
        image = ImageOps.exif_transpose(image).convert('RGB')
    except (ImageError, MemoryError):
        raise
    except Exception as error:  # PIL raises many types for bad data
        raise ImageError('not a decodable image') from error
    return image, width, height


def scale_box(box, sx, sy, width, height):
    return [[max(0, min(width, round(float(x) * sx))),
             max(0, min(height, round(float(y) * sy)))] for x, y in box]


class OcrService:
    """Lazy engine holder: one recognition at a time, unload when idle."""

    def __init__(self, factory, threads, max_side, idle_seconds,
                 max_pixels):
        self.factory = factory
        self.threads = threads
        self.max_side = max_side
        self.idle_seconds = idle_seconds
        self.max_pixels = max_pixels
        self.engine = None
        self.last_used = time.monotonic()
        self.lock = threading.Lock()
        self.stop = threading.Event()
        if idle_seconds > 0:
            threading.Thread(target=self._reap, daemon=True).start()

    def _reap(self):
        interval = max(0.05, min(self.idle_seconds / 4, 5))
        while not self.stop.wait(interval):
            with self.lock:
                idle = time.monotonic() - self.last_used
                if self.engine is not None and idle >= self.idle_seconds:
                    self._unload()

    def _unload(self):
        self.engine = None
        release_memory()

    def close(self):
        self.stop.set()

    def recognise(self, data, max_side=None):
        side = max_side or self.max_side
        with self.lock:
            started = time.monotonic()
            try:
                image, width, height = load_image(data, side, self.max_pixels)
                if self.engine is None:
                    self.engine = self.factory(self.threads)
                result = self.engine(np.asarray(image))
            except ImageError:
                raise
            except Exception as error:
                self._unload()
                raise EngineError(type(error).__name__) from error
            finally:
                self.last_used = time.monotonic()
            sx, sy = width / image.width, height / image.height
            lines = []
            texts = getattr(result, 'txts', None)
            if texts is not None and len(texts):
                boxes, scores = result.boxes, result.scores
                for text, box, score in zip(texts, boxes, scores):
                    lines.append({
                        'text': text,
                        'box': scale_box(box, sx, sy, width, height),
                        'score': round(float(score), 4)})
            return {'width': width, 'height': height, 'lines': lines,
                    'engine': ENGINE_NAME,
                    'seconds': round(time.monotonic() - started, 3)}


def read_token(env):
    path = env.get('OCR_TOKEN_FILE', '').strip()
    if path:
        try:
            with open(path, encoding='utf-8') as handle:
                token = handle.read().strip()
        except OSError:
            token = ''
        if token:
            return token
    return env.get('OCR_TOKEN', '').strip()


def load_config(env):
    token = read_token(env)
    if not token:
        raise SystemExit('OCR_TOKEN or OCR_TOKEN_FILE must provide a token')

    def number(name, default):
        try:
            return int(env.get(name, default))
        except ValueError:
            raise SystemExit(f'{name} must be an integer') from None

    return {
        'token': token,
        'bind': env.get('OCR_BIND', '0.0.0.0'),
        'port': number('OCR_PORT', 8080),
        'max_side': clamp_side(number('OCR_MAX_SIDE', 1024), 1024),
        'threads': max(1, number('OCR_THREADS', 2)),
        'idle_seconds': max(0, number('OCR_IDLE_SECONDS', 300)),
        'max_bytes': number('OCR_MAX_BYTES', 52428800),
        'max_pixels': number('OCR_MAX_PIXELS', 100000000),
    }


def make_handler(service, token, max_bytes):
    class Handler(BaseHTTPRequestHandler):
        server_version = 'ocr-service'
        protocol_version = 'HTTP/1.1'

        def log_message(self, *args):
            pass

        def _send(self, status, payload, lines=None, headers=None):
            body = json.dumps(payload, ensure_ascii=False).encode('utf-8')
            self.send_response(status)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(body)))
            if status >= 400 and self.command == 'POST':
                # The request body may be unread; do not reuse the socket.
                self.send_header('Connection', 'close')
                self.close_connection = True
            for key, value in (headers or {}).items():
                self.send_header(key, value)
            self.end_headers()
            self.wfile.write(body)
            count = '-' if lines is None else lines
            print(f'{self.command} {urlsplit(self.path).path} {status} '
                  f'{time.monotonic() - self.started:.3f}s lines={count}',
                  flush=True)

        def _error(self, status, message, headers=None):
            self._send(status, {'error': message}, headers=headers)

        def do_GET(self):
            self.started = time.monotonic()
            if urlsplit(self.path).path == '/healthz':
                self._send(200, {'status': 'ok'})
            else:
                self._error(404, 'not found')

        def do_POST(self):
            self.started = time.monotonic()
            url = urlsplit(self.path)
            if url.path != '/v1/ocr':
                return self._error(404, 'not found')
            if not hmac.compare_digest(
                    (self.headers.get('Authorization') or '').encode(),
                    ('Bearer ' + token).encode()):
                return self._error(401, 'unauthorized')
            length = self.headers.get('Content-Length')
            if length is None or not length.isdigit():
                return self._error(411, 'Content-Length required')
            if int(length) > max_bytes:
                return self._error(413, 'body too large')
            data = self.rfile.read(int(length))
            raw = parse_qs(url.query).get('max_side', [None])[0]
            side = clamp_side(raw, service.max_side) if raw else None
            try:
                result = service.recognise(data, side)
            except ImageError as error:
                return self._error(422, str(error))
            except EngineError as error:
                return self._error(503, f'engine unavailable: {error}',
                                   {'Retry-After': RETRY_AFTER})
            self._send(200, result, lines=len(result['lines']))

    return Handler


def make_server(config, factory=create_engine):
    service = OcrService(factory, config['threads'], config['max_side'],
                         config['idle_seconds'], config['max_pixels'])
    handler = make_handler(service, config['token'], config['max_bytes'])
    server = ThreadingHTTPServer((config['bind'], config['port']), handler)
    server.daemon_threads = True
    server.service = service
    return server


def main():
    server = make_server(load_config(os.environ))
    signal.signal(signal.SIGTERM, lambda *_: threading.Thread(
        target=server.shutdown, daemon=True).start())
    print(f'listening on {server.server_address[0]}:{server.server_address[1]}',
          flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.service.close()
        server.server_close()
    return 0


if __name__ == '__main__':
    sys.exit(main())
