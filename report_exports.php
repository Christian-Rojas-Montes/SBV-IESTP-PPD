<?php

function reportXmlEscape(string $value): string
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function reportColumnName(int $column): string
{
    $name = '';
    while ($column > 0) {
        $remainder = ($column - 1) % 26;
        $name = chr(65 + $remainder) . $name;
        $column = intdiv($column - 1, 26);
    }
    return $name;
}

function exportReportXlsx(string $title, array $filters, array $columns, array $rows): never
{
    $sheetRows = [
        [$title],
        ['Periodo', $filters['from'] . ' a ' . $filters['to']],
        ['Sede', $filters['sede'] === 'all' ? 'Todas' : $filters['sede']],
        [],
        $columns,
    ];
    foreach ($rows as $row) {
        $sheetRows[] = array_values($row);
    }
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($sheetRows as $rowIndex => $values) {
        $excelRow = $rowIndex + 1;
        $sheetXml .= '<row r="' . $excelRow . '">';
        foreach ($values as $columnIndex => $value) {
            $cell = reportColumnName($columnIndex + 1) . $excelRow;
            if (is_int($value) || is_float($value)) {
                $sheetXml .= '<c r="' . $cell . '"><v>' . $value . '</v></c>';
            } else {
                $text = reportXmlEscape((string) $value);
                $sheetXml .= '<c r="' . $cell . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
            }
        }
        $sheetXml .= '</row>';
    }
    $sheetXml .= '</sheetData></worksheet>';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Reporte" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => $sheetXml,
    ];
    $zipData = '';
    $centralDirectory = '';
    foreach ($files as $name => $contents) {
        $nameLength = strlen($name);
        $size = strlen($contents);
        $crc = crc32($contents);
        $localOffset = strlen($zipData);
        $zipData .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 33, $crc, $size, $size, $nameLength, 0) . $name . $contents;
        $centralDirectory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 33, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $localOffset) . $name;
    }
    $centralOffset = strlen($zipData);
    $zipData .= $centralDirectory;
    $zipData .= pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($centralDirectory), $centralOffset, 0);
    $tempFile = tempnam(sys_get_temp_dir(), 'biblioteca-xlsx-');
    if ($tempFile === false || file_put_contents($tempFile, $zipData) === false) {
        if (is_string($tempFile)) @unlink($tempFile);
        http_response_code(500);
        exit('No se pudo preparar el archivo Excel.');
    }

    $filename = 'reporte-biblioteca-' . date('Ymd-His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tempFile));
    header('Cache-Control: no-store');
    readfile($tempFile);
    @unlink($tempFile);
    exit;
}

function reportPdfText(string $text): string
{
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }
    $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    return preg_replace_callback('/[^\x20-\x7E]/', static fn (array $match): string => sprintf('\\%03o', ord($match[0])), $text) ?? '';
}

function exportReportPdf(string $title, array $filters, array $columns, array $rows): never
{
    $lines = [
        $title,
        'Periodo: ' . $filters['from'] . ' a ' . $filters['to'] . ' | Sede: ' . ($filters['sede'] === 'all' ? 'Todas' : $filters['sede']),
        implode(' | ', $columns),
    ];
    foreach ($rows as $row) {
        $values = array_map(static fn ($value): string => (string) $value, array_values($row));
        $line = implode(' | ', $values);
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT', $line);
            if ($converted !== false) {
                $line = $converted;
            }
        }
        $lines[] = substr($line, 0, 115);
    }
    if (count($lines) === 3) {
        $lines[] = 'Sin registros para los filtros seleccionados.';
    }
    $pages = array_chunk($lines, 48);
    $pageCount = count($pages);
    $fontId = 3 + $pageCount * 2;
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn (int $index): string => (3 + $index * 2) . ' 0 R', array_keys($pages))) . '] /Count ' . $pageCount . ' >>',
    ];
    foreach ($pages as $index => $pageLines) {
        $pageId = 3 + $index * 2;
        $contentId = $pageId + 1;
        $commands = "BT\n/F1 10 Tf\n40 752 Td\n14 TL\n";
        foreach ($pageLines as $line) {
            $commands .= '(' . reportPdfText($line) . ") Tj\nT*\n";
        }
        $commands .= 'ET';
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 ' . $fontId . ' 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
        $objects[$contentId] = '<< /Length ' . strlen($commands) . ">>\nstream\n" . $commands . "\nendstream";
    }
    $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    ksort($objects);
    $pdf = "%PDF-1.4\n%âãÏÓ\n";
    $offsets = [0];
    foreach ($objects as $objectId => $body) {
        $offsets[$objectId] = strlen($pdf);
        $pdf .= $objectId . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $maxObject = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxObject + 1) . "\n0000000000 65535 f \n";
    for ($objectId = 1; $objectId <= $maxObject; $objectId++) {
        $pdf .= sprintf('%010d 00000 n ', $offsets[$objectId]) . "\n";
    }
    $pdf .= 'trailer << /Size ' . ($maxObject + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="reporte-biblioteca-' . date('Ymd-His') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store');
    echo $pdf;
    exit;
}
