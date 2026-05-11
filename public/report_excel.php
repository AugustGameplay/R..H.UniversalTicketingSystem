<?php
/**
 * report_excel.php — Professional Excel (.xlsx) Report
 * Uses PhpSpreadsheet to generate a styled workbook with:
 *   Sheet 1: Executive Summary (metrics, agent productivity, top issues)
 *   Sheet 2: Ticket Details (full formatted table)
 */

$active = 'report_excel';
require __DIR__ . '/partials/auth.php';
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/partials/helpers.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Chart\Layout;

// ── Brand colors ───────────────────────────────────────
$NAVY   = '083B5C';
$ORANGE = 'F47A21';
$WHITE  = 'FFFFFF';
$LIGHT  = 'F8FAFD';
$GRAY   = '6C757D';
$GREEN  = '22C55E';
$RED    = 'EF4444';
$YELLOW = 'FBBF24';

// ── Parameters (same as history.php) ───────────────────
$start = trim($_GET['start'] ?? '');
$end   = trim($_GET['end'] ?? '');
$today = (new DateTime('today'))->format('Y-m-d');
if ($start === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = $today;
if ($end === ''   || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = $start;
if ($start > $end) { $tmp = $start; $start = $end; $end = $tmp; }

$dtStart   = new DateTime($start);
$dtEnd     = new DateTime($end);
$dtEndNext = (clone $dtEnd)->modify('+1 day');

$sqlRange    = "t.created_at >= :start AND t.created_at < :endNext";
$paramsRange = [':start' => $dtStart->format('Y-m-d 00:00:00'), ':endNext' => $dtEndNext->format('Y-m-d 00:00:00')];

$creatorIdField  = 'id_user';
$creatorJoinSQL  = "LEFT JOIN users cu ON cu.id_user = t.id_user";
$creatorNameExpr = "COALESCE(cu.full_name, '')";
$closedField     = 'closed_at';

$view      = strtolower(trim($_GET['view'] ?? 'total'));
$creatorId = trim($_GET['creator'] ?? '');
$creatorId = ($creatorId !== '' && ctype_digit($creatorId)) ? (int)$creatorId : 0;
$cat       = trim($_GET['cat'] ?? '');

$params = $paramsRange;
$creatorFilterSQL = "";
if ($creatorId > 0) {
    $creatorFilterSQL = " AND t.$creatorIdField = :creatorId";
    $params[':creatorId'] = $creatorId;
}

$extraWhere = "";
if ($view === 'assigned')   $extraWhere = " AND t.assigned_user_id IS NOT NULL";
if ($view === 'unassigned') $extraWhere = " AND t.assigned_user_id IS NULL";
if ($view === 'inprogress') $extraWhere = " AND t.status = 'En Proceso'";
if ($view === 'done')       $extraWhere = " AND t.status = 'Resuelto'";
if ($view === 'closed')     $extraWhere = " AND (t.status = 'Cerrado' OR t.status = 'Closed')";
if ($cat !== '') {
    $extraWhere .= " AND COALESCE(NULLIF(TRIM(t.category), ''), 'Uncategorized') = :cat";
    $params[':cat'] = $cat;
}

$whereBase = "$sqlRange $creatorFilterSQL";

// ── Metrics ────────────────────────────────────────────
function cw(PDO $pdo, string $w, array $p): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM tickets t WHERE $w");
    $s->execute($p);
    return (int)($s->fetchColumn() ?: 0);
}

$total      = cw($pdo, $whereBase, $params);
$assigned   = cw($pdo, "$whereBase AND t.assigned_user_id IS NOT NULL", $params);
$unassigned = cw($pdo, "$whereBase AND t.assigned_user_id IS NULL", $params);
$inprogress = cw($pdo, "$whereBase AND t.status = 'En Proceso'", $params);
$done       = cw($pdo, "$whereBase AND t.status = 'Resuelto'", $params);
$closed     = cw($pdo, "$whereBase AND (t.status = 'Cerrado' OR t.status = 'Closed')", $params);

// Avg resolution
$avgResolutionTime = 'N/A';
try {
    $avgStmt = $pdo->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_at, t.$closedField)) AS avg_min FROM tickets t WHERE $whereBase AND t.$closedField IS NOT NULL");
    $avgStmt->execute($params);
    $avgMin = $avgStmt->fetchColumn();
    if ($avgMin !== null && $avgMin !== false) {
        $h = floor((float)$avgMin / 60); $m = round((float)$avgMin % 60);
        $avgResolutionTime = ($h > 0 ? "{$h}h " : '') . "{$m}min";
    }
} catch (Throwable $e) {}

// Agent stats
$agentStats = [];
try {
    $agentStmt = $pdo->prepare("SELECT u.full_name AS agent_name, COUNT(*) AS tickets_resolved, AVG(TIMESTAMPDIFF(MINUTE, t.created_at, t.$closedField)) AS avg_min FROM tickets t LEFT JOIN users u ON u.id_user = t.assigned_user_id WHERE $whereBase AND t.assigned_user_id IS NOT NULL AND (t.status='Resuelto' OR t.status='Cerrado' OR t.status='Closed') GROUP BY t.assigned_user_id, u.full_name ORDER BY tickets_resolved DESC");
    $agentStmt->execute($params);
    $agentStats = $agentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// Top categories
$topCategories = [];
try {
    $catStmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(t.category),''),'Uncategorized') AS category, COALESCE(NULLIF(TRIM(t.type),''),'No type') AS type, COUNT(*) AS total FROM tickets t WHERE $whereBase GROUP BY COALESCE(NULLIF(TRIM(t.category),''),'Uncategorized'), COALESCE(NULLIF(TRIM(t.type),''),'No type') ORDER BY total DESC LIMIT 10");
    $catStmt->execute($params);
    $topCategories = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// Ticket rows
$rows = [];
try {
    $detailStmt = $pdo->prepare("SELECT t.id_ticket, t.created_at, t.$closedField AS closed_at, t.area, COALESCE(NULLIF(TRIM(t.category),''),'N/A') AS category, COALESCE(NULLIF(TRIM(t.type),''),'-') AS type, t.priority, t.status, COALESCE(u.full_name,'-') AS assigned_name, $creatorNameExpr AS created_by_name FROM tickets t LEFT JOIN users u ON u.id_user = t.assigned_user_id $creatorJoinSQL WHERE $sqlRange $creatorFilterSQL $extraWhere ORDER BY t.id_ticket DESC LIMIT 1000");
    $detailStmt->execute($params);
    $rows = $detailStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$startText  = $dtStart->format('m/d/Y');
$endText    = $dtEnd->format('m/d/Y');
$reportDate = (new DateTime('now'))->format('m/d/Y H:i');

// ── Helper: style a range ──────────────────────────────
function styleRange($sheet, string $range, array $style): void {
    $sheet->getStyle($range)->applyFromArray($style);
}

// ═══════════════════════════════════════════════════════
// BUILD SPREADSHEET
// ═══════════════════════════════════════════════════════
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('RH&R Universal Ticketing System')
    ->setTitle("Ticket Report $startText - $endText")
    ->setDescription('Professional ticket report generated automatically');

// ── SHEET 1: SUMMARY ───────────────────────────────────
$summary = $spreadsheet->getActiveSheet();
$summary->setTitle('Executive Summary');

// Column widths
foreach (['A'=>4,'B'=>22,'C'=>18,'D'=>18,'E'=>18,'F'=>18,'G'=>18,'H'=>18] as $col=>$w) {
    $summary->getColumnDimension($col)->setWidth($w);
}

// Header bar
$summary->mergeCells('B2:G2');
$summary->setCellValue('B2', 'RH&R UNIVERSAL TICKETING SYSTEM');
styleRange($summary, 'B2:G2', [
    'font' => ['bold'=>true, 'size'=>16, 'color'=>['rgb'=>$WHITE]],
    'fill' => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['rgb'=>$NAVY]],
    'alignment' => ['vertical'=>Alignment::VERTICAL_CENTER],
]);
$summary->getRowDimension(2)->setRowHeight(40);

// Subtitle
$summary->mergeCells('B3:G3');
$summary->setCellValue('B3', "Ticket Report  |  $startText to $endText  |  Generated: $reportDate");
styleRange($summary, 'B3:G3', [
    'font' => ['size'=>10, 'color'=>['rgb'=>$WHITE], 'italic'=>true],
    'fill' => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['rgb'=>$NAVY]],
]);

// Orange accent
$summary->mergeCells('B4:G4');
$summary->getRowDimension(4)->setRowHeight(5);
styleRange($summary, 'B4:G4', [
    'fill' => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['rgb'=>$ORANGE]],
]);

// ── Section: Executive Summary metrics ──────────────────
$r = 6;
$summary->setCellValue("B$r", '■ EXECUTIVE SUMMARY');
styleRange($summary, "B$r", ['font'=>['bold'=>true,'size'=>13,'color'=>['rgb'=>$NAVY]]]);
$r += 2;

$metrics = [
    ['Total Tickets', $total],
    ['Assigned', $assigned],
    ['Unassigned', $unassigned],
    ['In Progress', $inprogress],
    ['Resolved', $done],
    ['Closed', $closed],
    ['Avg. Resolution', $avgResolutionTime],
    ['Resolution Rate', $total > 0 ? round(($done+$closed)/$total*100).'%' : '0%'],
];

$metricHeaderStyle = [
    'font' => ['bold'=>true, 'size'=>9, 'color'=>['rgb'=>$WHITE]],
    'fill' => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['rgb'=>$NAVY]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN, 'color'=>['rgb'=>$NAVY]]],
];
$metricValueStyle = [
    'font' => ['bold'=>true, 'size'=>14, 'color'=>['rgb'=>$NAVY]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER, 'vertical'=>Alignment::VERTICAL_CENTER],
    'fill' => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['rgb'=>$LIGHT]],
    'borders' => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN, 'color'=>['rgb'=>'DEE2E6']]],
];

// Row of labels
$cols = ['B','C','D','E','F','G','H'];
foreach ($metrics as $i => $m) {
    if ($i >= 7) break;
    $col = $cols[$i] ?? 'H';
    $summary->setCellValue("$col$r", strtoupper($m[0]));
    styleRange($summary, "$col$r", $metricHeaderStyle);
}
$r++;
$summary->getRowDimension($r)->setRowHeight(35);
foreach ($metrics as $i => $m) {
    if ($i >= 7) break;
    $col = $cols[$i] ?? 'H';
    $summary->setCellValue("$col$r", $m[1]);
    styleRange($summary, "$col$r", $metricValueStyle);
}

// Second row: remaining metrics
$r += 2;
$remaining = array_slice($metrics, 7);
if ($remaining) {
    foreach ($remaining as $i => $m) {
        $col = $cols[$i] ?? 'C';
        $summary->setCellValue("$col$r", strtoupper($m[0]));
        styleRange($summary, "$col$r", $metricHeaderStyle);
    }
    $r++;
    $summary->getRowDimension($r)->setRowHeight(35);
    foreach ($remaining as $i => $m) {
        $col = $cols[$i] ?? 'C';
        $summary->setCellValue("$col$r", $m[1]);
        styleRange($summary, "$col$r", $metricValueStyle);
    }
}

// ── Section: Status Distribution (data for chart) ───────
$r += 3;
$summary->setCellValue("B$r", '■ STATUS DISTRIBUTION');
styleRange($summary, "B$r", ['font'=>['bold'=>true,'size'=>13,'color'=>['rgb'=>$NAVY]]]);
$r += 2;

$statusData = [
    ['Assigned', $assigned, $GREEN],
    ['Unassigned', $unassigned, $YELLOW],
    ['In Progress', $inprogress, $ORANGE],
    ['Resolved', $done, $RED],
    ['Closed', $closed, $GRAY],
];

$chartDataStartRow = $r;
$summary->setCellValue("B$r", 'STATUS'); $summary->setCellValue("C$r", 'COUNT');
styleRange($summary, "B$r:C$r", $metricHeaderStyle);
$r++;
foreach ($statusData as $sd) {
    $summary->setCellValue("B$r", $sd[0]);
    $summary->setCellValue("C$r", $sd[1]);
    styleRange($summary, "B$r:C$r", [
        'borders' => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN, 'color'=>['rgb'=>'DEE2E6']]],
        'fill' => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['rgb'=>$LIGHT]],
    ]);
    $r++;
}
$chartDataEndRow = $r - 1;

// Create Pie Chart
try {
    $labels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Executive Summary'!\$B\$" . ($chartDataStartRow+1) . ":\$B\$$chartDataEndRow", null, count($statusData))];
    $values = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Executive Summary'!\$C\$" . ($chartDataStartRow+1) . ":\$C\$$chartDataEndRow", null, count($statusData))];
    $series = new DataSeries(DataSeries::TYPE_PIECHART, null, range(0, 0), $labels, $labels, $values);
    $plotArea = new PlotArea(null, [$series]);
    $legend = new Legend(Legend::POSITION_RIGHT, null, false);
    $chartTitle = new Title('Tickets by Status');
    $chart = new Chart('statusChart', $chartTitle, $legend, $plotArea);
    $chart->setTopLeftPosition("E" . ($chartDataStartRow - 1));
    $chart->setBottomRightPosition("H" . ($chartDataEndRow + 2));
    $summary->addChart($chart);
} catch (Throwable $e) {}

// ── Section: Agent Productivity ────────────────────────
if ($agentStats) {
    $r += 2;
    $summary->setCellValue("B$r", '■ AGENT PRODUCTIVITY');
    styleRange($summary, "B$r", ['font'=>['bold'=>true,'size'=>13,'color'=>['rgb'=>$NAVY]]]);
    $r += 2;

    $agentHeaders = ['Agent', 'Tickets Resolved', 'Avg. Time', '% of Total'];
    $agentCols = ['B','C','D','E'];
    foreach ($agentHeaders as $i => $h) {
        $summary->setCellValue($agentCols[$i].$r, $h);
    }
    styleRange($summary, "B$r:E$r", $metricHeaderStyle);
    $r++;

    $totalRes = array_sum(array_column($agentStats, 'tickets_resolved'));
    foreach ($agentStats as $a) {
        $name = $a['agent_name'] ?: '(Unknown)';
        $count = (int)$a['tickets_resolved'];
        $pct = $totalRes > 0 ? round($count/$totalRes*100,1).'%' : '0%';
        $avgTime = 'N/A';
        if ($a['avg_min'] !== null) {
            $am = (float)$a['avg_min'];
            $avgTime = (floor($am/60) > 0 ? floor($am/60).'h ' : '') . round($am%60).'min';
        }
        $summary->setCellValue("B$r", $name);
        $summary->setCellValue("C$r", $count);
        $summary->setCellValue("D$r", $avgTime);
        $summary->setCellValue("E$r", $pct);
        styleRange($summary, "B$r:E$r", [
            'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'DEE2E6']]],
            'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>($r%2==0?$LIGHT:$WHITE)]],
        ]);
        $r++;
    }
}

// ── Section: Top Issues ───────────────────────────────
if ($topCategories) {
    $r += 2;
    $summary->setCellValue("B$r", '■ TOP REPORTED ISSUES');
    styleRange($summary, "B$r", ['font'=>['bold'=>true,'size'=>13,'color'=>['rgb'=>$NAVY]]]);
    $r += 2;

    $issueHeaders = ['#', 'Category', 'Issue Type', 'Incidents', '% of Total'];
    $issueCols = ['B','C','D','E','F'];
    foreach ($issueHeaders as $i => $h) {
        $summary->setCellValue($issueCols[$i].$r, $h);
    }
    styleRange($summary, "B$r:F$r", $metricHeaderStyle);
    $r++;

    foreach ($topCategories as $i => $c) {
        $pct = $total > 0 ? round((int)$c['total']/$total*100,1).'%' : '0%';
        $summary->setCellValue("B$r", $i+1);
        $summary->setCellValue("C$r", $c['category']);
        $summary->setCellValue("D$r", $c['type']);
        $summary->setCellValue("E$r", (int)$c['total']);
        $summary->setCellValue("F$r", $pct);
        styleRange($summary, "B$r:F$r", [
            'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'DEE2E6']]],
            'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>($r%2==0?$LIGHT:$WHITE)]],
        ]);
        $r++;
    }
}

// ── SHEET 2: TICKET DETAILS ────────────────────────────
$details = $spreadsheet->createSheet();
$details->setTitle('Ticket Details');

$detailHeaders = ['ID','Created','Closed','Area','Category','Type','Priority','Status','Assigned to','Created by','Resolution Time'];
$detailWidths  = [8, 18, 18, 20, 18, 20, 14, 14, 20, 20, 16];
$detailCols    = ['A','B','C','D','E','F','G','H','I','J','K'];

// Header bar
$details->mergeCells('A1:K1');
$details->setCellValue('A1', "RH&R Ticket Details  —  $startText to $endText");
$details->getRowDimension(1)->setRowHeight(35);
styleRange($details, 'A1:K1', [
    'font'=>['bold'=>true,'size'=>14,'color'=>['rgb'=>$WHITE]],
    'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$NAVY]],
    'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER],
]);

// Orange line
$details->mergeCells('A2:K2');
$details->getRowDimension(2)->setRowHeight(4);
styleRange($details, 'A2:K2', ['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$ORANGE]]]);

// Column headers
$hr = 3;
foreach ($detailHeaders as $i => $h) {
    $details->setCellValue($detailCols[$i].$hr, $h);
    $details->getColumnDimension($detailCols[$i])->setWidth($detailWidths[$i]);
}
styleRange($details, "A$hr:K$hr", [
    'font'=>['bold'=>true,'size'=>9,'color'=>['rgb'=>$WHITE]],
    'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$NAVY]],
    'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER],
    'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>$NAVY]]],
]);
$details->freezePane('A4');

// Priority colors
$prioColors = ['Urgent'=>'FEE2E2','High'=>'FED7AA','Medium'=>'DBEAFE','Low'=>'D1FAE5'];
$statusColors = ['Closed'=>'F3F4F6','Resolved'=>'DCFCE7','In Progress'=>'FFF7ED','Pending'=>'FEF9C3'];

// Data rows
$dr = 4;
foreach ($rows as $row) {
    $createdTxt = '-'; $closedTxt = '-'; $resTxt = '-';
    try { $createdTxt = (new DateTime($row['created_at']))->format('m/d/Y H:i'); } catch (Throwable $e) {}
    if (!empty($row['closed_at'])) {
        try {
            $dtC = new DateTime($row['closed_at']);
            $closedTxt = $dtC->format('m/d/Y H:i');
            $dtS = new DateTime($row['created_at']);
            $diff = $dtS->diff($dtC);
            $parts = [];
            if ($diff->d > 0) $parts[] = $diff->d.'d';
            if ($diff->h > 0) $parts[] = $diff->h.'h';
            if ($diff->i > 0) $parts[] = $diff->i.'m';
            $resTxt = $parts ? implode(' ', array_slice($parts, 0, 2)) : '0m';
        } catch (Throwable $e) {}
    }

    $priLabel = getPriorityEn($row['priority'] ?? '-');
    $stLabel  = getStatusEn($row['status'] ?? '-');

    $details->setCellValue("A$dr", (int)$row['id_ticket']);
    $details->setCellValue("B$dr", $createdTxt);
    $details->setCellValue("C$dr", $closedTxt);
    $details->setCellValue("D$dr", $row['area'] ?? '-');
    $details->setCellValue("E$dr", $row['category'] ?? '-');
    $details->setCellValue("F$dr", $row['type'] ?? '-');
    $details->setCellValue("G$dr", $priLabel);
    $details->setCellValue("H$dr", $stLabel);
    $details->setCellValue("I$dr", $row['assigned_name'] ?? '-');
    $details->setCellValue("J$dr", $row['created_by_name'] ?: '-');
    $details->setCellValue("K$dr", $resTxt);

    $fillColor = $dr % 2 === 0 ? $LIGHT : $WHITE;
    styleRange($details, "A$dr:K$dr", [
        'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'E5E7EB']]],
        'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$fillColor]],
        'font'=>['size'=>9],
    ]);

    // Priority color
    if (isset($prioColors[$priLabel])) {
        styleRange($details, "G$dr", [
            'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$prioColors[$priLabel]]],
            'font'=>['bold'=>true,'size'=>9],
        ]);
    }
    // Status color
    if (isset($statusColors[$stLabel])) {
        styleRange($details, "H$dr", [
            'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$statusColors[$stLabel]]],
            'font'=>['bold'=>true,'size'=>9],
        ]);
    }

    $dr++;
}

// Footer row
$details->mergeCells("A$dr:K$dr");
$details->setCellValue("A$dr", "Total: ".count($rows)." tickets  |  Report generated: $reportDate  |  RH&R Universal Ticketing System");
styleRange($details, "A$dr:K$dr", [
    'font'=>['italic'=>true,'size'=>8,'color'=>['rgb'=>$GRAY]],
    'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER],
    'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$LIGHT]],
]);

// ── Output ─────────────────────────────────────────────
$spreadsheet->setActiveSheetIndex(0);
$filename = "Ticket_Report_{$start}_to_{$end}.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->setIncludeCharts(true);
$writer->save('php://output');
exit;
