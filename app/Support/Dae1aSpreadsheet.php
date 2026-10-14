<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lays Dae1aReportService::build() out as the DAE-1A form: one row per day,
 * a column per room (guests in that room, highlighted on the day they
 * checked in), then (5) guests checked in, (6) guests staying overnight and
 * (7) rooms occupied, the month's totals and the three averages.
 *
 * Printing: ROOMS_PER_PAGE rooms per page across, with the Day columns and
 * the header rows repeated, so a hotel with more rooms continues on the
 * next page instead of shrinking the form.
 */
class Dae1aSpreadsheet
{
    public const ROOMS_PER_PAGE = 15;

    /** Up to this many rooms, the room columns and (5)-(7) print on the same page. */
    private const ROOMS_WITH_TOTALS = 11;

    private const HIGHLIGHT = 'FFFF00';

    public static function download(array $report): StreamedResponse
    {
        $spreadsheet = self::make($report);
        $name = sprintf('Velocity-Suites-DAE-1A_%04d-%02d.xlsx', $report['year'], $report['month']);

        return new StreamedResponse(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
        ]);
    }

    public static function make(array $report): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setTitle('DAE-1A ' . $report['monthName'] . ' ' . $report['year'])->setCreator('Velocity Suites');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('DAE-1A ' . substr($report['monthName'], 0, 3) . ' ' . $report['year']);
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(9);

        $rooms = $report['rooms'];
        $roomCount = max(1, count($rooms));
        $col = fn (int $i) => Coordinate::stringFromColumnIndex($i);
        $firstRoom = 3;
        $lastRoom = $firstRoom + $roomCount - 1;
        [$c5, $c6, $c7] = [$col($lastRoom + 1), $col($lastRoom + 2), $col($lastRoom + 3)];
        $lastRoomCol = $col($lastRoom);

        // ---- form header: title (row 1), establishment + Form box (2), month and year (3), (4) rooms (4).
        // The title starts in the first room column, not A: columns A-B repeat on every printed page.
        $notesEnd = $col($firstRoom + min($roomCount, self::ROOMS_PER_PAGE) - 1);
        $notesWidth = min($roomCount, self::ROOMS_PER_PAGE) * 6.5;
        $titleEnd = $roomCount <= self::ROOMS_WITH_TOTALS ? $c7 : $notesEnd;
        $sheet->mergeCells("C1:{$titleEnd}1");
        $sheet->setCellValue('C1', 'Monthly Recording Format for Accommodation Establishment');
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('C1')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_BOTTOM);
        $titleWidth = $notesWidth + ($titleEnd === $c7 ? 3 * 12.5 : 0);
        $sheet->getRowDimension(1)->setRowHeight(self::lines(56 * 1.35, $titleWidth) * 15);
        $sheet->setCellValue('C2', 'Velocity Suites');
        $sheet->mergeCells("{$c5}2:{$c7}2");
        $sheet->setCellValue("{$c5}2", 'Form: DAE-1A');
        $sheet->getStyle("{$c5}2")->getFont()->setBold(true);
        self::box($sheet, "{$c5}2:{$c7}2");
        $sheet->mergeCells("{$c5}3:{$c7}3");
        $sheet->setCellValue("{$c5}3", 'Month of ' . $report['monthName'] . '    Year ' . $report['year']);
        $sheet->mergeCells("{$c5}4:{$c6}4");
        $sheet->setCellValue("{$c5}4", '(4) Total Number of Rooms :');
        $sheet->getStyle("{$c5}4")->getFont()->setBold(true);
        $sheet->getStyle("{$c5}4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setShrinkToFit(true);
        $sheet->setCellValue("{$c7}4", $report['totalRooms']);
        self::box($sheet, "{$c7}4");
        $sheet->getStyle("{$c7}4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // ---- table header (rows 5-6)
        $sheet->mergeCells('A5:B5');
        $sheet->setCellValue('A5', 'Date');
        $sheet->setCellValue('A6', 'Day');
        $sheet->setCellValue('B6', 'Day of the week Sun-Sat');
        $sheet->getStyle('A6:B6')->getAlignment()->setTextRotation(90);
        $sheet->mergeCells("C5:{$lastRoomCol}5");
        $sheet->setCellValue('C5', 'Room Identification');
        foreach ($rooms as $i => $room) {
            $sheet->setCellValue($col($firstRoom + $i) . '6', "Room No.\n" . $room->room_number);
        }
        foreach ([[$c5, "(5)\nNumber of Guests Check IN\n(unit: visitors)"],
                  [$c6, "(6)\nNumber of Guests staying over-night\n(Guest Nights)"],
                  [$c7, "(7)\nNumber of Rooms Occupied by Guests\n(unit: rooms)"]] as [$c, $label]) {
            $sheet->mergeCells("{$c}5:{$c}6");
            $sheet->setCellValue("{$c}5", $label);
        }
        $header = $sheet->getStyle("A5:{$c7}6");
        $header->getFont()->setBold(true);
        $header->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension(6)->setRowHeight(80);

        // ---- one row per day
        $row = 7;
        foreach ($report['days'] as $day) {
            $sheet->setCellValue("A{$row}", $day['day']);
            $sheet->setCellValue("B{$row}", $day['weekday']);
            foreach ($rooms as $i => $room) {
                $cell = $day['rooms'][$room->id] ?? null;
                if (! $cell) {
                    continue;
                }
                $address = $col($firstRoom + $i) . $row;
                $sheet->setCellValue($address, $cell['guests']);
                if ($cell['checked_in']) {
                    $sheet->getStyle($address)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HIGHLIGHT);
                }
            }
            $sheet->setCellValue("{$c5}{$row}", $day['checked_in']);
            $sheet->setCellValue("{$c6}{$row}", $day['overnight']);
            $sheet->setCellValue("{$c7}{$row}", $day['rooms_occupied']);
            $row++;
        }
        $lastDayRow = $row - 1;

        // ---- totals (formulas, so they follow any correction typed into the sheet)
        $sheet->mergeCells("A{$row}:{$lastRoomCol}{$row}");
        $sheet->setCellValue("A{$row}", 'TOTAL OF THIS MONTH');
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        foreach ([$c5, $c6, $c7] as $c) {
            $sheet->setCellValue("{$c}{$row}", "=SUM({$c}7:{$c}{$lastDayRow})");
        }
        $sheet->getStyle("A{$row}:{$c7}{$row}")->getFont()->setBold(true);
        $totalRow = $row;

        $table = $sheet->getStyle("A5:{$c7}{$totalRow}");
        $table->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A7:{$c7}{$lastDayRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // ---- averages
        $available = $report['totalRooms'] * count($report['days']);
        // Short label + value under (5)-(7); the full definitions go below the
        // first page's rooms (text in the repeated Day columns would be cut
        // off on every continuation page).
        $averages = [
            ['Average Guest-Night', "=IFERROR(ROUND({$c6}{$totalRow}/{$c5}{$totalRow},2),0)", '0.00',
                'Average Guest-Night = Total Number of Guests Nights / Total Number of Guests Check IN'],
            ['Average Room Occupancy Rate', "=IFERROR({$c7}{$totalRow}/{$available},0)", '0.00%',
                'Average Room Occupancy Rate = Total No. of Rooms Occupied by the Guests during the month / Total No. of Rooms Available during the month'],
            ['Average Number of Guests per Room', "=IFERROR(ROUND({$c6}{$totalRow}/{$c7}{$totalRow},2),0)", '0.00',
                'Average Number of Guests per Room = Total Number of Guests Nights / Total Number of Rooms Occupied by the Guests'],
        ];
        $row = $totalRow + 2;
        foreach ($averages as [$label, $formula, $format, $definition]) {
            $sheet->mergeCells("{$c5}{$row}:{$c6}{$row}");
            $sheet->setCellValue("{$c5}{$row}", $label);
            $sheet->setCellValue("{$c7}{$row}", $formula);
            $sheet->getStyle("{$c7}{$row}")->getNumberFormat()->setFormatCode($format);
            $sheet->getStyle("{$c5}{$row}:{$c7}{$row}")->getFont()->setBold(true);
            $sheet->getStyle("{$c5}{$row}:{$c7}{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("{$c5}{$row}:{$c7}{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("{$c7}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $sheet->mergeCells("C{$row}:{$notesEnd}{$row}");
            $sheet->setCellValue("C{$row}", $definition);
            $sheet->getStyle("C{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($row)->setRowHeight(max(26, self::lines(strlen($definition), $notesWidth) * 12 + 2));
            $row++;
        }
        $row++;
        $legend = 'Highlighted: guest(s) who checked in on that day. Each cell shows the number of guests in the room that night.';
        $sheet->mergeCells("C{$row}:{$notesEnd}{$row}");
        $sheet->setCellValue("C{$row}", $legend);
        $sheet->getStyle("C{$row}")->getFont()->setItalic(true);
        $sheet->getStyle("C{$row}")->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($row)->setRowHeight(self::lines(strlen($legend), $notesWidth) * 12 + 2);

        // ---- widths, freeze, print layout
        $sheet->getColumnDimension('A')->setWidth(4.5);
        $sheet->getColumnDimension('B')->setWidth(5);
        for ($i = $firstRoom; $i <= $lastRoom; $i++) {
            $sheet->getColumnDimension($col($i))->setWidth(6.5);
        }
        foreach ([$c5, $c6, $c7] as $c) {
            $sheet->getColumnDimension($c)->setWidth(12.5);
        }
        $sheet->freezePane('C7');

        $setup = $sheet->getPageSetup();
        $setup->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setPaperSize(PageSetup::PAPERSIZE_LETTER)
            ->setScale(85)->setColumnsToRepeatAtLeftByStartAndEnd('A', 'B')->setRowsToRepeatAtTopByStartAndEnd(5, 6)
            ->setPrintArea("A1:{$c7}{$row}");
        $sheet->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.5);
        // a new printed page after every ROOMS_PER_PAGE rooms
        for ($i = self::ROOMS_PER_PAGE; $i < $roomCount; $i += self::ROOMS_PER_PAGE) {
            $sheet->setBreak($col($firstRoom + $i) . '1', Worksheet::BREAK_COLUMN);
        }

        return $spreadsheet;
    }

    /** Wrapped lines needed for $chars characters of 9pt Arial in $width column-width units. */
    private static function lines(float $chars, float $width): int
    {
        return max(1, (int) ceil($chars / max(1.0, $width * 1.05)));
    }

    private static function box(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
    }
}
