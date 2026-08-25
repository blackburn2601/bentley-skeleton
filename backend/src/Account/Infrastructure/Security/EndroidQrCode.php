<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Security;

use App\Account\Application\QrCode;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * endroid/qr-code implementation of {@see QrCode} (ADR-0026).
 *
 * Renders the provisioning URI to an SVG and returns it as a data URL, so the enrollment
 * screen embeds it directly in an `<img src="...">` with no second round trip and no client
 * QR library.
 *
 * SVG rather than PNG on purpose: the writer builds the matrix with `SimpleXMLElement` and
 * needs no image extension (GD/Imagick), where the PNG writer requires GD and the container
 * image does not ship it. The QR is resolution-independent too, so it stays crisp at any size.
 */
final readonly class EndroidQrCode implements QrCode
{
    public function dataUrlFor(string $text): string
    {
        $dataUri = (new Builder(writer: new SvgWriter(), data: $text))->build()->getDataUri();

        // The port promises a non-empty data URL; the builder always produces one, so the
        // assert only narrows the type to satisfy the contract.
        \assert('' !== $dataUri);

        return $dataUri;
    }
}
