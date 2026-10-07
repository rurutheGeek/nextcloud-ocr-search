"""Guard the OCR service with a fake engine (no rapidocr needed)."""
import importlib.util
import io
import json
import tempfile
import threading
import time
import unittest
import urllib.error
import urllib.request
from pathlib import Path
from types import SimpleNamespace

from PIL import Image

SOURCE = Path(__file__).resolve().parents[1] / 'ocr_service.py'
spec = importlib.util.spec_from_file_location('ocr_service', SOURCE)
svc = importlib.util.module_from_spec(spec)
spec.loader.exec_module(svc)

TOKEN = 's3cret'


def png(width, height, exif_orientation=None):
    buffer = io.BytesIO()
    image = Image.new('RGB', (width, height), 'white')
    if exif_orientation:
        exif = Image.Exif()
        exif[274] = exif_orientation
        image.save(buffer, 'JPEG', exif=exif)
    else:
        image.save(buffer, 'PNG')
    return buffer.getvalue()


class FakeEngine:
    """Reports one line covering the whole (possibly downscaled) image."""

    def __init__(self, tracker):
        self.tracker = tracker

    def __call__(self, array):
        self.tracker['calls'] += 1
        self.tracker['shapes'].append(array.shape)
        with self.tracker['guard']:
            self.tracker['active'] += 1
            self.tracker['peak'] = max(self.tracker['peak'],
                                       self.tracker['active'])
        time.sleep(self.tracker['delay'])
        with self.tracker['guard']:
            self.tracker['active'] -= 1
        if self.tracker['empty']:
            return SimpleNamespace(txts=None, boxes=None, scores=None)
        h, w = array.shape[:2]
        return SimpleNamespace(
            txts=('hello 日本語',),
            boxes=[[[0, 0], [w, 0], [w, h], [0, h]]],
            scores=(0.98,))


class ServiceCase(unittest.TestCase):
    idle = 0
    fail_factory = None

    def setUp(self):
        self.tracker = {'calls': 0, 'shapes': [], 'active': 0, 'peak': 0,
                        'delay': 0, 'empty': False, 'built': 0,
                        'guard': threading.Lock()}

        def factory(threads):
            self.tracker['built'] += 1
            if self.fail_factory:
                raise self.fail_factory
            return FakeEngine(self.tracker)

        config = {'token': TOKEN, 'bind': '127.0.0.1', 'port': 0,
                  'max_side': 1024, 'threads': 1, 'idle_seconds': self.idle,
                  'max_bytes': 1_000_000, 'max_pixels': 1_000_000}
        self.server = svc.make_server(config, factory)
        self.base = f'http://127.0.0.1:{self.server.server_address[1]}'
        threading.Thread(target=self.server.serve_forever, args=(0.02,),
                         daemon=True).start()
        self.addCleanup(self.stop)

    def stop(self):
        self.server.shutdown()
        self.server.service.close()
        self.server.server_close()

    def post(self, body, query='', token=TOKEN, headers=None):
        request = urllib.request.Request(
            f'{self.base}/v1/ocr{query}', data=body, method='POST')
        if token is not None:
            request.add_header('Authorization', 'Bearer ' + token)
        for key, value in (headers or {}).items():
            request.add_header(key, value)
        try:
            with urllib.request.urlopen(request) as response:
                return response.status, json.load(response), response.headers
        except urllib.error.HTTPError as error:
            return error.code, json.load(error), error.headers


class HttpTests(ServiceCase):
    def test_health_needs_no_auth_and_does_not_load_the_model(self):
        with urllib.request.urlopen(self.base + '/healthz') as response:
            self.assertEqual(json.load(response), {'status': 'ok'})
        self.assertEqual(self.tracker['built'], 0)

    def test_unknown_paths_are_404(self):
        with self.assertRaises(urllib.error.HTTPError) as caught:
            urllib.request.urlopen(self.base + '/nope')
        self.assertEqual(caught.exception.code, 404)
        self.assertEqual(self.post(b'x', token=None)[0], 401)
        request = urllib.request.Request(self.base + '/other', data=b'x',
                                         method='POST')
        with self.assertRaises(urllib.error.HTTPError) as caught:
            urllib.request.urlopen(request)
        self.assertEqual(caught.exception.code, 404)

    def test_missing_or_wrong_token_is_401(self):
        self.assertEqual(self.post(png(10, 10), token=None)[0], 401)
        self.assertEqual(self.post(png(10, 10), token='wrong')[0], 401)
        self.assertEqual(self.tracker['built'], 0)

    def test_happy_path_returns_lines(self):
        status, body, _ = self.post(png(300, 200))
        self.assertEqual(status, 200)
        self.assertEqual((body['width'], body['height']), (300, 200))
        self.assertEqual(body['engine'], 'ppocrv5-mobile')
        self.assertEqual(body['lines'][0]['text'], 'hello 日本語')
        self.assertEqual(body['lines'][0]['score'], 0.98)
        self.assertEqual(body['lines'][0]['box'],
                         [[0, 0], [300, 0], [300, 200], [0, 200]])
        self.assertIn('seconds', body)

    def test_boxes_are_scaled_back_to_the_original_size(self):
        status, body, _ = self.post(png(1500, 600))
        self.assertEqual(status, 200)
        self.assertEqual(self.tracker['shapes'][-1][:2], (410, 1024))
        self.assertEqual((body['width'], body['height']), (1500, 600))
        self.assertEqual(body['lines'][0]['box'],
                         [[0, 0], [1500, 0], [1500, 600], [0, 600]])

    def test_exif_orientation_is_applied_before_measuring(self):
        status, body, _ = self.post(png(300, 200, exif_orientation=6))
        self.assertEqual(status, 200)
        self.assertEqual((body['width'], body['height']), (200, 300))
        self.assertEqual(self.tracker['shapes'][-1][:2], (300, 200))

    def test_exif_orientation_with_downscaling(self):
        _, body, _ = self.post(png(1500, 600, exif_orientation=6))
        self.assertEqual((body['width'], body['height']), (600, 1500))
        self.assertEqual(body['lines'][0]['box'][2], [600, 1500])

    def test_no_text_is_an_empty_list(self):
        self.tracker['empty'] = True
        status, body, _ = self.post(png(50, 50))
        self.assertEqual((status, body['lines']), (200, []))

    def test_missing_content_length_is_411(self):
        import socket
        sock = socket.create_connection(self.server.server_address)
        sock.sendall(b'POST /v1/ocr HTTP/1.1\r\nHost: x\r\n'
                     b'Authorization: Bearer ' + TOKEN.encode() +
                     b'\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n')
        self.assertIn(b' 411 ', sock.recv(4096).split(b'\r\n')[0])
        sock.close()

    def test_oversized_body_is_413(self):
        self.assertEqual(self.post(b'0' * 1_000_001)[0], 413)

    def test_garbage_bytes_are_422(self):
        status, body, _ = self.post(b'definitely not an image')
        self.assertEqual(status, 422)
        self.assertIn('error', body)
        self.assertEqual(self.tracker['built'], 0)

    def test_too_many_pixels_is_422(self):
        self.assertEqual(self.post(png(1100, 1000))[0], 422)

    def test_max_side_is_clamped(self):
        self.post(png(5000, 100), '?max_side=100000')
        self.assertEqual(self.tracker['shapes'][-1][1], 4096)
        self.post(png(2000, 100), '?max_side=1')
        self.assertEqual(self.tracker['shapes'][-1][1], 256)
        self.post(png(2000, 100), '?max_side=junk')
        self.assertEqual(self.tracker['shapes'][-1][1], 1024)

    def test_calls_are_serialised(self):
        self.tracker['delay'] = 0.1
        results = []
        threads = [threading.Thread(
            target=lambda: results.append(self.post(png(20, 20))[0]))
            for _ in range(4)]
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join()
        self.assertEqual(results, [200] * 4)
        self.assertEqual(self.tracker['peak'], 1)
        self.assertEqual(self.tracker['built'], 1)


class EngineFailureTests(ServiceCase):
    fail_factory = MemoryError()

    def test_a_failing_engine_is_503_with_retry_after(self):
        status, body, headers = self.post(png(20, 20))
        self.assertEqual(status, 503)
        self.assertIn('error', body)
        self.assertTrue(headers['Retry-After'])


class IdleTests(ServiceCase):
    idle = 0.3

    def test_the_engine_is_unloaded_after_idle_and_reloaded(self):
        self.post(png(20, 20))
        self.assertIsNotNone(self.server.service.engine)
        deadline = time.monotonic() + 5
        while self.server.service.engine is not None:
            self.assertLess(time.monotonic(), deadline)
            time.sleep(0.05)
        self.assertEqual(self.post(png(20, 20))[0], 200)
        self.assertEqual(self.tracker['built'], 2)


class ConfigTests(unittest.TestCase):
    def test_token_file_wins_over_env(self):
        with tempfile.NamedTemporaryFile('w', suffix='.tok') as handle:
            handle.write('from-file\n')
            handle.flush()
            config = svc.load_config({'OCR_TOKEN': 'from-env',
                                      'OCR_TOKEN_FILE': handle.name})
        self.assertEqual(config['token'], 'from-file')

    def test_defaults(self):
        config = svc.load_config({'OCR_TOKEN': 't'})
        self.assertEqual((config['port'], config['max_side'],
                          config['idle_seconds'], config['threads']),
                         (8080, 1024, 300, 2))
        self.assertEqual(config['max_bytes'], 52428800)
        self.assertEqual(config['max_pixels'], 100000000)

    def test_refuses_to_start_without_a_token(self):
        with self.assertRaises(SystemExit):
            svc.load_config({})
        with tempfile.NamedTemporaryFile('w') as handle, \
                self.assertRaises(SystemExit):
            svc.load_config({'OCR_TOKEN_FILE': handle.name})


if __name__ == '__main__':
    unittest.main()
