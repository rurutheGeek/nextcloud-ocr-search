<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Service;

/** The OCR service did not answer at all: it is down, or it died on this request. */
class OcrConnectionLost extends OcrUnavailable {
}
