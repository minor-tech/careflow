<?php

namespace App\Services;

use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

/**
 * The QR code on the registration screen: a patient points their phone camera
 * at it instead of a receptionist reading out a long address.
 */
class TrackingQrCode
{
    /**
     * The code as a PNG "data:" address, ready for an <img src>. Drawn larger
     * than it is shown so it stays sharp on a high-density screen, with the
     * medium error correction that lets a scuffed or glared screen still scan.
     */
    public function dataUri(string $url): string
    {
        $qrCode = new QrCode(
            data: $url,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 360,
            margin: 16,
        );

        return (new PngWriter)->write($qrCode)->getDataUri();
    }
}
