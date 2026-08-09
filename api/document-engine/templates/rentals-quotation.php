<?php
declare(strict_types=1);

require_once __DIR__ . '/../pdf-png.php';
require_once __DIR__ . '/../../rentals-pricing.php';

const ATLAS_RENTALS_PDF_PRESENTATION_VERSION = 'rentals-quotation-v7-standard-rental-service';
const ATLAS_RENTALS_PDF_LOGO_PATH = __DIR__ . '/../../../assets/dyplus-logo.png';

function atlasRentalsPdfText(mixed $value): string
{
    $text = str_replace(['₦', '×', '–', '—', '’'], ['NGN ', 'x', '-', '-', "'"], (string)$value);
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : $text;
    return preg_replace('/[^\x20-\x7E]/', '', $ascii === false ? $text : $ascii) ?? '';
}

function atlasRentalsPdfLiteral(mixed $value): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], atlasRentalsPdfText($value));
}

function atlasRentalsPdfWrap(mixed $value, int $characters): array
{
    $text = trim(atlasRentalsPdfText($value));
    if ($text === '') return ['-'];
    return explode("\n", wordwrap($text, max(12, $characters), "\n", true));
}

function atlasRentalsPdfMoney(mixed $value): string
{
    return 'NGN ' . number_format((float)$value, 2);
}

function atlasRentalsRenderQuotationPdf(array $record): string
{
    $data = json_decode((string)$record['normalized_payload'], true, 32, JSON_THROW_ON_ERROR);
    $created = new DateTimeImmutable((string)$record['created_at']);
    $reference = (string)$record['enquiry_reference'];
    $days = (int)$record['rental_days'];
    $pricing = atlasRentalsPricingFromRecord($record);
    $standard = $pricing['standard']; $performance = $pricing['performance'];
    $technicianAmount = $pricing['technicianAmount'];
    $logo = atlasRentalsPdfLoadRgbaPng(ATLAS_RENTALS_PDF_LOGO_PATH);
    $logoHeight = 46.0; $logoWidth = $logoHeight * ($logo['width'] / $logo['height']);
    $pages = [];
    $page = '';
    $y = 0.0;

    $text = static function (float $x, float $atY, mixed $value, float $size = 9.0, bool $bold = false, array $color = [0.12, 0.18, 0.27]) use (&$page): void {
        $font = $bold ? 'F2' : 'F1';
        $page .= sprintf("BT /%s %.1F Tf %.3F %.3F %.3F rg 1 0 0 1 %.1F %.1F Tm (%s) Tj ET\n", $font, $size, $color[0], $color[1], $color[2], $x, $atY, atlasRentalsPdfLiteral($value));
    };
    $rect = static function (float $x, float $atY, float $width, float $height, array $color) use (&$page): void {
        $page .= sprintf("%.3F %.3F %.3F rg %.1F %.1F %.1F %.1F re f\n", $color[0], $color[1], $color[2], $x, $atY, $width, $height);
    };
    $line = static function (float $x1, float $atY, float $x2, array $color = [0.88, 0.90, 0.93]) use (&$page): void {
        $page .= sprintf("%.3F %.3F %.3F RG %.1F %.1F m %.1F %.1F l S\n", $color[0], $color[1], $color[2], $x1, $atY, $x2, $atY);
    };
    $startPage = static function () use (&$page, &$y, $rect, $text, $logoWidth, $logoHeight): void {
        $page = '';
        $rect(0, 770, 595, 72, [0.071, 0.220, 0.357]);
        $rect(0, 765, 595, 5, [0.122, 0.616, 0.408]);
        $text(42, 809, 'DY-PLUS', 18, true, [1, 1, 1]);
        $text(42, 789, 'ATLAS Rentals', 10, true, [0.85, 0.96, 0.91]);
        $page .= sprintf("q %.3F 0 0 %.3F %.3F 783 cm /Im1 Do Q\n", $logoWidth, $logoHeight, 553 - $logoWidth);
        $y = 738;
    };
    $finishPage = static function () use (&$pages, &$page, $line, $text, $reference): void {
        $pageNumber = count($pages) + 1;
        $line(42, 43, 553);
        $text(42, 27, 'DY-PLUS NIG. LTD. | ATLAS Rentals', 7.5, true, [0.35, 0.42, 0.50]);
        $text(395, 27, 'Reference ' . $reference . ' | Page ' . $pageNumber, 7.5, false, [0.35, 0.42, 0.50]);
        $pages[] = $page;
    };
    $ensure = static function (float $height) use (&$y, &$page, $finishPage, $startPage): void {
        if ($y - $height < 62) { $finishPage(); $startPage(); }
    };
    $section = static function (string $title) use (&$y, $ensure, $text, $line): void {
        $ensure(32); $y -= 10; $text(42, $y, $title, 11, true, [0.071, 0.220, 0.357]); $y -= 8; $line(42, $y, 553, [0.122, 0.616, 0.408]); $y -= 16;
    };
    $row = static function (string $label, mixed $value) use (&$y, $ensure, $text, $line): void {
        $lines = atlasRentalsPdfWrap($value, 58); $ensure(24);
        $text(48, $y, $label, 8.5, true, [0.39, 0.45, 0.53]);
        foreach ($lines as $index => $wrapped) {
            $ensure(15);
            if ($index > 0 && $y > 720) $text(48, $y, $label . ' (continued)', 8.0, true, [0.39, 0.45, 0.53]);
            $text(202, $y, $wrapped, 9.0, false); $y -= 12;
        }
        $y -= 5; $line(48, $y + 2, 547); $y -= 7;
    };
    $item = static function (string $description, string $calculation, string $amount, bool $emphasis = false) use (&$y, $ensure, $text, $line, $rect): void {
        $descriptionLines = atlasRentalsPdfWrap($description, 34); $calculationLines = atlasRentalsPdfWrap($calculation, 29); $height = max(27, max(count($descriptionLines), count($calculationLines)) * 11 + 12); $ensure($height);
        if ($emphasis) $rect(42, $y - $height + 8, 511, $height, [0.94, 0.98, 0.96]);
        foreach ($descriptionLines as $index => $wrapped) $text(48, $y - ($index * 11), $wrapped, 8.8, $emphasis);
        foreach ($calculationLines as $index => $wrapped) $text(278, $y - ($index * 11), $wrapped, 7.7, false, [0.35, 0.42, 0.50]);
        $text(455, $y, $amount, 8.8, true, $emphasis ? [0.04, 0.43, 0.23] : [0.12, 0.18, 0.27]);
        $y -= $height; $line(48, $y + 8, 547); $y -= 2;
    };

    $startPage();
    $text(42, $y, 'Laptop Rental Quotation', 20, true, [0.071, 0.220, 0.357]);
    $y -= 25;
    $text(42, $y, 'Reference: ' . $reference, 9.5, true);
    $text(300, $y, 'Issue date: ' . $created->format('d F Y'), 9.5);
    $y -= 16;
    $text(300, $y, 'Valid until: ' . $created->modify('+30 days')->format('d F Y'), 9.5);
    $y -= 17;

    $section('Customer Details');
    $row('Customer', $data['fullName']); $row('Organisation', $data['organization']);
    $row('Email', $data['email']); $row('Phone', $data['phone']);
    $section('Rental Details');
    $row('Rental period', $data['startDate'] . ' to ' . $data['endDate']);
    $row('Rental days', $days . ' day' . ($days === 1 ? '' : 's') . ' (' . $pricing['durationLabel'] . ')'); $row('Location', $data['location']);
    $row('Rental rate plan', $pricing['ratePlanLabel']);
    $section('Itemised Quotation');
    if ((int)$record['standard_quantity'] > 0) $item('Standard Business Laptop - ' . $record['standard_quantity'] . ' units', atlasRentalsAppliedRatesLabel($standard, 'atlasRentalsPdfMoney') . ' | per unit ' . atlasRentalsPdfMoney($standard['perUnitRental']), atlasRentalsPdfMoney($standard['equipmentAmount']));
    if ((int)$record['performance_quantity'] > 0) $item('High Performance Laptop - ' . $record['performance_quantity'] . ' units', atlasRentalsAppliedRatesLabel($performance, 'atlasRentalsPdfMoney') . ' | per unit ' . atlasRentalsPdfMoney($performance['perUnitRental']), atlasRentalsPdfMoney($performance['equipmentAmount']));
    if ((int)$record['technician_required'] === 1) $item('Technician', $record['technician_days'] . ' days x ' . atlasRentalsPdfMoney($record['technician_daily_rate']), atlasRentalsPdfMoney($technicianAmount));
    $item('Delivery & retrieval', 'Standard rental service', atlasRentalsPdfMoney($record['delivery_fee']));
    $section('Commercial Summary');
    $item('Subtotal before VAT', '', atlasRentalsPdfMoney($record['subtotal']));
    $item('VAT (7.5%)', '', atlasRentalsPdfMoney($record['vat_amount']));
    $item('ESTIMATED TOTAL', '', atlasRentalsPdfMoney($record['estimated_total']), true);
    $section('Availability and Booking');
    foreach (atlasRentalsPdfWrap('This estimate is valid for 30 days and remains subject to equipment availability and DY-PLUS review. This enquiry does not confirm availability or create a booking.', 92) as $wrapped) {
        $ensure(13); $text(48, $y, $wrapped, 8.8, false, [0.25, 0.32, 0.40]); $y -= 13;
    }
    $finishPage();

    $objects = [
        1 => '', 2 => '',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        5 => '<< /Type /XObject /Subtype /Image /Width ' . $logo['width'] . ' /Height ' . $logo['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /SMask 6 0 R /Length ' . strlen($logo['rgb']) . ">>\nstream\n" . $logo['rgb'] . "\nendstream",
        6 => '<< /Type /XObject /Subtype /Image /Width ' . $logo['width'] . ' /Height ' . $logo['height'] . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ' . strlen($logo['alpha']) . ">>\nstream\n" . $logo['alpha'] . "\nendstream",
    ];
    $pageReferences = [];
    foreach ($pages as $index => $content) {
        $pageId = 7 + ($index * 2); $contentId = $pageId + 1; $pageReferences[] = $pageId . ' 0 R';
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << /Im1 5 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
        $objects[$contentId] = '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . "endstream";
    }
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageReferences) . '] /Count ' . count($pages) . ' >>';
    ksort($objects);
    $pdf = "%PDF-1.4\n%ATLAS-RENTALS\n"; $offsets = [0];
    foreach ($objects as $id => $object) { $offsets[$id] = strlen($pdf); $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n"; }
    $xref = strlen($pdf); $size = max(array_keys($objects)) + 1; $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
    for ($id = 1; $id < $size; $id++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
    return $pdf . "trailer << /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
}
