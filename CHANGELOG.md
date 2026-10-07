# Changelog

## 0.1.0

- Unified search provider for the text recognised in images.
- Automatic indexing of new and changed images through the background job.
- `occ ocr_search:index`, `ocr_search:process`, `ocr_search:status` and
  `ocr_search:retry-failed` for bulk indexing.
- Sidebar tab with the recognised text and a copy button.
- OCR service (`server/`) based on PP-OCRv5 mobile and ONNX Runtime.
