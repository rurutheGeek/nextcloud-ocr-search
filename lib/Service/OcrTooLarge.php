<?php

declare(strict_types=1);

namespace OCA\OcrSearch\Service;

/** The image is beyond the limits of the OCR service; trying again cannot help. */
class OcrTooLarge extends OcrRejected {
}
