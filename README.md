# OCR Search for Nextcloud

Find images by the text inside them — receipts, recipes, screenshots, photos
of signs and notes — from the Nextcloud unified search.

This repository contains two parts that belong together:

| Directory | Part |
| --- | --- |
| repository root | the **ocr_search** Nextcloud app (PHP + a bundled sidebar tab) |
| [`server/`](server/) | the **OCR service** (Python, PP-OCRv5 mobile on ONNX Runtime, Docker image) |

A short Japanese summary is at the end of this file.

## How it works

```
upload / edit ──▶ ocr_search queues the file id (one insert, nothing else)
                        │
background job (cron) ──┤ reads the image through Nextcloud
occ ocr_search:process ─┘        │  POST /v1/ocr  (bearer token, image bytes)
                                 ▼
                           OCR service ──▶ text lines + boxes
                                 │
                 index table in the Nextcloud database
                                 │
unified search ──▶ LIKE on the normalised text ──▶ access check per file
```

- The OCR service is stateless. It never sees the file system or the database.
- Search results are limited to the storages mounted for the searching user
  and every hit is then resolved through that user's own view of the file
  system, so a file that is not shared with you is never returned.
- When the OCR service is down, uploads and viewing are unaffected. Queued
  files wait, and no retry attempt is used up.
- Recognised text is normalised before it is stored and searched: width and
  case, lost voicing marks (ゲ/ケ), old and Chinese forms of kanji (內/内) and
  look-alikes (力/カ) are folded, and whitespace and punctuation are dropped,
  so a phrase copied from the recognised text matches however it was wrapped.

Measured on a 2-CPU, 1 GiB container with six real photos, longest side
1024 px: 0.3–1.1 s per image, 359 MiB peak, about 160 MiB after the model is
unloaded on idle.

## Quick start

### 1. Start the OCR service

```bash
cd server
cp .env.example .env    # set OCR_TOKEN
docker compose up -d --build
curl http://127.0.0.1:8080/healthz
```

See [`server/README.md`](server/README.md) for the HTTP contract and all
settings.

### 2. Install the app

From the [releases page](../../releases):

```bash
tar -xzf ocr_search.tar.gz -C /var/www/html/custom_apps/
occ app:enable ocr_search
```

### 3. Configure

As an administrator open *Administration settings → OCR Search* and set the
service URL and token, or:

```bash
occ config:app:set ocr_search ocr_url --value=http://ocr:8080
occ config:app:set ocr_search ocr_token --value=<same as OCR_TOKEN>
```

| Key | Default | Meaning |
| --- | --- | --- |
| `ocr_url` | – | base URL of the OCR service |
| `ocr_token` | – | shared bearer token |
| `max_side` | `1024` | longest side in pixels the service recognises on |
| `mime_types` | JPEG, PNG, WebP, GIF, BMP, TIFF, HEIC/HEIF | comma separated list |
| `min_size` | `4096` | smaller files are skipped (icons) |
| `max_size` | `31457280` | larger files are skipped |

### 4. Index

New and changed images are queued automatically and recognised by the
background job (Nextcloud must run background jobs with cron).

Existing images:

```bash
occ ocr_search:index                      # queue everything (or --user alice --path /Photos)
occ ocr_search:process --max-runtime 3600 # work for at most one hour
occ ocr_search:status
occ ocr_search:retry-failed
```

`ocr_search:index` only queues. The backlog it creates is processed by
`ocr_search:process` alone, never by the background job, so you choose when
the load happens — for example from a nightly timer. `ocr_search:process`
exits with status 2 when the OCR service cannot be reached; the queue is left
untouched.

A file is retried up to five times (after 5 min, 20 min, 80 min, 5 h 20 min)
when the service rejects it, then marked as failed.

### Excluding folders

Put an empty file named `.noocr` into a folder to keep that folder and
everything below it out of the index.

## Copying recognised text

Open the sidebar of an image in the Files app and choose the **Text** tab.

## Development

```bash
npm ci && npm run build                 # js/ocr_search.js and l10n/*.js
docker run --rm -v "$PWD":/app:ro nextcloud:33-apache sh /app/tests/run.sh
python3 -m unittest discover -s server/tests
```

## License

AGPL-3.0-or-later

## 日本語の概要

Nextcloudの統合検索から、画像の中の文字（レシート、レシピ、スクリーンショット
など）で画像を探せるようにするアプリです。認識は `server/` のOCRサービス
（PP-OCRv5 mobile／ONNX Runtime、CPUのみ、メモリ1GiB未満）が行います。

- 新規・更新画像はバックグラウンドジョブが自動で索引します。
- 既存画像は `occ ocr_search:index` でキューに積み、`occ ocr_search:process
  --max-runtime <秒>` で好きな時間帯に処理します。
- 検索結果に出るのは、検索した利用者がアクセスできるファイルだけです。
- OCRサービスが止まっていても、アップロードと閲覧には影響しません。
- フォルダに `.noocr` を置くと、そのフォルダ以下を索引から外します。
- 認識した文字は、Filesのサイドバーの「文字」タブからコピーできます。
