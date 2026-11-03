FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
	PYTHONDONTWRITEBYTECODE=1 \
	PIP_NO_CACHE_DIR=1

WORKDIR /app
COPY requirements.txt /app/requirements.txt

RUN pip install -r requirements.txt \
	&& pip uninstall -y opencv-python \
	&& pip install --force-reinstall --no-deps \
		"$(grep '^opencv-python-headless' requirements.txt)"

COPY ocr_service.py /app/ocr_service.py
RUN python -c "import ocr_service; ocr_service.create_engine(1)" \
	&& chmod -R a+rX /usr/local/lib/python3.12/site-packages/rapidocr \
	&& useradd --system --no-create-home --uid 10001 ocr

USER ocr

# Valeurs par défaut modifiables au runtime via `-e`
ENV OCR_BIND=0.0.0.0 \
	OCR_PORT=8080 \
	HOME=/tmp

# Le healthcheck lit dynamiquement OCR_PORT via os.environ
# Note: On passe par sh -c (forme shell) pour que la commande évalue la variable
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
	CMD python -c "import os, urllib.request as u; port=os.environ.get('OCR_PORT', '8080'); u.urlopen(f'http://127.0.0.1:{port}/healthz', timeout=3)"

ENTRYPOINT ["python", "/app/ocr_service.py"]
