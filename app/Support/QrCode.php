<?php

declare(strict_types=1);

namespace App\Support;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode as QRCodeGenerator;
use chillerlan\QRCode\QROptions;

/**
 * Renders a QR code as a PNG data URI, the form dompdf embeds reliably
 * (receipt PDFs render on the server, so the web app's qrcode.react can't
 * be used there).
 */
final class QrCode
{
    public static function pngDataUri(string $data, int $scale = 4): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'scale' => $scale,
            'quietzoneSize' => 1,
            'outputBase64' => true,
        ]);

        return (new QRCodeGenerator($options))->render($data);
    }
}
