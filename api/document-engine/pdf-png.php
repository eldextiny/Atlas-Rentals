<?php
declare(strict_types=1);

function atlasRentalsPdfLoadRgbaPng(string $path): array
{
    $bytes = is_readable($path) ? file_get_contents($path) : false;
    if (!is_string($bytes) || !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) throw new RuntimeException('Approved PDF logo is unavailable.');
    $offset = 8; $idat = ''; $width = 0; $height = 0; $bitDepth = 0; $colorType = 0;
    while ($offset + 12 <= strlen($bytes)) {
        $length = unpack('N', substr($bytes, $offset, 4))[1];
        $type = substr($bytes, $offset + 4, 4); $data = substr($bytes, $offset + 8, $length); $offset += 12 + $length;
        if ($type === 'IHDR') {
            [$width, $height] = array_values(unpack('Nwidth/Nheight', substr($data, 0, 8)));
            $bitDepth = ord($data[8]); $colorType = ord($data[9]);
            if (ord($data[10]) !== 0 || ord($data[11]) !== 0 || ord($data[12]) !== 0) throw new RuntimeException('Approved PDF logo uses unsupported PNG encoding.');
        } elseif ($type === 'IDAT') $idat .= $data;
        elseif ($type === 'IEND') break;
    }
    if ($width < 1 || $height < 1 || $bitDepth !== 8 || $colorType !== 6 || $idat === '') throw new RuntimeException('Approved PDF logo must be an 8-bit RGBA PNG.');
    $inflated = gzuncompress($idat);
    if (!is_string($inflated)) throw new RuntimeException('Approved PDF logo could not be decoded.');
    $bytesPerPixel = 4; $stride = $width * $bytesPerPixel; $position = 0; $previous = array_fill(0, $stride, 0); $rgb = ''; $alpha = '';
    for ($row = 0; $row < $height; $row++) {
        if ($position + 1 + $stride > strlen($inflated)) throw new RuntimeException('Approved PDF logo data is incomplete.');
        $filter = ord($inflated[$position++]); $scanline = array_values(unpack('C*', substr($inflated, $position, $stride))); $position += $stride;
        for ($index = 0; $index < $stride; $index++) {
            $left = $index >= $bytesPerPixel ? $scanline[$index - $bytesPerPixel] : 0;
            $up = $previous[$index]; $upperLeft = $index >= $bytesPerPixel ? $previous[$index - $bytesPerPixel] : 0;
            $predictor = match ($filter) {
                0 => 0, 1 => $left, 2 => $up, 3 => intdiv($left + $up, 2), 4 => atlasRentalsPdfPngPaeth($left, $up, $upperLeft),
                default => throw new RuntimeException('Approved PDF logo uses an unsupported PNG filter.'),
            };
            $scanline[$index] = ($scanline[$index] + $predictor) & 255;
        }
        for ($column = 0; $column < $width; $column++) {
            $base = $column * 4; $rgb .= chr($scanline[$base]) . chr($scanline[$base + 1]) . chr($scanline[$base + 2]); $alpha .= chr($scanline[$base + 3]);
        }
        $previous = $scanline;
    }
    return ['width' => $width, 'height' => $height, 'rgb' => gzcompress($rgb, 9), 'alpha' => gzcompress($alpha, 9)];
}

function atlasRentalsPdfPngPaeth(int $left, int $up, int $upperLeft): int
{
    $estimate = $left + $up - $upperLeft; $leftDistance = abs($estimate - $left); $upDistance = abs($estimate - $up); $upperLeftDistance = abs($estimate - $upperLeft);
    return $leftDistance <= $upDistance && $leftDistance <= $upperLeftDistance ? $left : ($upDistance <= $upperLeftDistance ? $up : $upperLeft);
}
