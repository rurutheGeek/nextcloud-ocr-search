# ocr-search OCR service

Stateless HTTP OCR service for the `ocr_search` Nextcloud app. One image per
request in, recognised text lines with boxes out. It never touches files or a
database. Engine: PP-OCRv5 mobile (Chinese/Japanese/English model) through
RapidOCR and ONNX Runtime, CPU only. Python standard library HTTP server.

## HTTP contract

| Request | Response |
| --- | --- |
| `GET /healthz` | 200 `{"status":"ok"}`. No auth, does not load the model. |
| `POST /v1/ocr` | Body: raw image bytes (any Content-Type, HEIC supported). Header `Authorization: Bearer <token>`. Optional query `max_side=<int>`, clamped to 256-4096. |
| anything else | 404 |

200 body:

```json
{"width": 1000, "height": 2000,
 "lines": [{"text": "...", "box": [[x,y],[x,y],[x,y],[x,y]], "score": 0.98}],
 "engine": "ppocrv5-mobile", "seconds": 0.31}
```

`width`/`height` are the dimensions after EXIF transpose and before
downscaling; `box` values are integers in that coordinate space. An image
without text gives `"lines": []`.

| Status | Meaning |
| --- | --- |
| 401 | Missing or wrong token |
| 411 | No `Content-Length` |
| 413 | Body larger than `OCR_MAX_BYTES` |
| 422 | Not a decodable image, or more than `OCR_MAX_PIXELS` pixels (this file is bad) |
| 503 | Engine failed to load or crashed (e.g. out of memory, model files missing); has `Retry-After` (service unavailable, not the file's fault) |

Error bodies: `{"error": "<message>"}`.

## Configuration

| Variable | Default | Meaning |
| --- | --- | --- |
| `OCR_TOKEN` | - | Shared secret |
| `OCR_TOKEN_FILE` | - | File containing the secret; wins over `OCR_TOKEN`. The service refuses to start without a token. |
| `OCR_BIND` | `0.0.0.0` | Listen address |
| `OCR_PORT` | `8080` | Listen port |
| `OCR_MAX_SIDE` | `1024` | Long side images are downscaled to |
| `OCR_THREADS` | `2` | ONNX Runtime intra-op threads |
| `OCR_IDLE_SECONDS` | `300` | Unload the model after this idle time; `0` never unloads |
| `OCR_MAX_BYTES` | `52428800` | Request body limit |
| `OCR_MAX_PIXELS` | `100000000` | Decoded image limit |

The model loads on the first OCR request. One recognition runs at a time;
other requests wait. Each request logs one line (method, path, status,
seconds, line count) to stdout, never the token or the text.

## Run

```
cp .env.example .env    # set OCR_TOKEN
docker compose up -d --build
```

The compose file limits the container to 1 GiB and 2 CPUs and publishes the
port on `127.0.0.1:8080` (override with `OCR_PUBLISH`). The models are
downloaded during the image build; the container needs no network.

## Memory

Measured with the pinned versions:

| Long side | Peak | Time per image |
| --- | --- | --- |
| 1024 | 392 MiB | 0.26 s |
| 1600 | 706 MiB | - |

Idle process after model load: about 141 MiB. After the idle unload the
process returns to about the same level (146 MiB measured; the imported
libraries stay resident).

## Tests

```
python -m unittest discover -s tests -v
```

Needs only Pillow and numpy; the engine is replaced by a fake.
