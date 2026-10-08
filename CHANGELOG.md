# Changelog

## 1.0.0

- First release in the Nextcloud App Store.
- The OCR service token is stored as a sensitive value.
- `occ ocr_search:clear --yes` deletes the index.
- The OCR service image is published on GHCR.
- Tested on Nextcloud 33 to 35 and on SQLite, MariaDB and PostgreSQL.

## 0.1.1

- Search ignores whitespace and punctuation, so a phrase copied from the
  recognised text matches even where a line ended after a comma, a full stop
  or a digit. Existing index entries are updated on upgrade; nothing has to be
  recognised again.

## 0.1.0

- Unified search provider for the text recognised in images.
- Automatic indexing of new and changed images through the background job.
- `occ ocr_search:index`, `ocr_search:process`, `ocr_search:status` and
  `ocr_search:retry-failed` for bulk indexing.
- Sidebar tab with the recognised text and a copy button.
- OCR service (`server/`) based on PP-OCRv5 mobile and ONNX Runtime.
