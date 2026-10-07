FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
	PYTHONDONTWRITEBYTECODE=1 \
	PIP_NO_CACHE_DIR=1

WORKDIR /app
COPY requirements.txt /app/requirements.txt
# rapidocr depends on the non-headless OpenCV; swap it for the headless build.
RUN pip install -r requirements.txt \
	&& pip uninstall -y opencv-python \
	&& pip install --force-reinstall --no-deps \
		"$(grep '^opencv-python-headless' requirements.txt)"

# Constructing the engine once downloads the models into the rapidocr package
# directory, so the running container needs no network.
COPY ocr_service.py /app/ocr_service.py
RUN python -c "import ocr_service; ocr_service.create_engine(1)" \
	&& chmod -R a+rX /usr/local/lib/python3.12/site-packages/rapidocr \
	&& useradd --system --no-create-home --uid 10001 ocr

USER ocr
EXPOSE 8080
ENV OCR_BIND=0.0.0.0 \
	OCR_PORT=8080 \
	HOME=/tmp

HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
	CMD ["python", "-c", "import urllib.request as u; u.urlopen('http://127.0.0.1:8080/healthz', timeout=3)"]

ENTRYPOINT ["python", "/app/ocr_service.py"]
