<?php
/**
 * report_pdf.php
 * ─────────────────────────────────────────────────────────
 * Generador de Reportes PDF profesionales.
 *
 * Recibe los mismos parámetros GET que history.php:
 *   - start, end, view, creator, cat
 *
 * Genera un PDF con:
 *   1. Encabezado corporativo (logo + título + fecha)
 *   2. Resumen ejecutivo (totales, promedios)
 *   3. Productividad por agente
 *   4. Top categorías/tipos más reportados
 *   5. Tabla detallada de tickets
 *
 * ACL: Solo roles 1 (Superadmin) y 2 (Admin).
 * ─────────────────────────────────────────────────────────
 */

$active = 'report_pdf';
require __DIR__ . '/partials/auth.php';
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/partials/helpers.php';
require __DIR__ . '/lib/fpdf/fpdf.php';

// =====================================================
// Parámetros (mismos que history.php)
// =====================================================
$start = trim($_GET['start'] ?? '');
$end   = trim($_GET['end'] ?? '');
$today = (new DateTime('today'))->format('Y-m-d');
if ($start === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = $today;
if ($end === ''   || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = $start;
if ($start > $end) { $tmp = $start; $start = $end; $end = $tmp; }

$dtStart   = new DateTime($start);
$dtEnd     = new DateTime($end);
$dtEndNext = (clone $dtEnd)->modify('+1 day');

$sqlRange = "t.created_at >= :start AND t.created_at < :endNext";
$paramsRange = [
    ':start'   => $dtStart->format('Y-m-d 00:00:00'),
    ':endNext' => $dtEndNext->format('Y-m-d 00:00:00'),
];

// =====================================================
// Campos estáticos (misma estructura que history.php GitHub)
// =====================================================
$creatorIdField   = 'id_user';
$creatorJoinSQL   = "LEFT JOIN users cu ON cu.id_user = t.id_user";
$creatorNameExpr  = "COALESCE(cu.full_name, '')";
$closedField      = 'closed_at';

// =====================================================
// Filtros
// =====================================================
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

// =====================================================
// Métricas
// =====================================================
function countWhere(PDO $pdo, string $where, array $p): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM tickets t WHERE $where");
    $s->execute($p);
    return (int)($s->fetchColumn() ?: 0);
}

$total      = countWhere($pdo, $whereBase, $params);
$assigned   = countWhere($pdo, "$whereBase AND t.assigned_user_id IS NOT NULL", $params);
$unassigned = countWhere($pdo, "$whereBase AND t.assigned_user_id IS NULL", $params);
$inprogress = countWhere($pdo, "$whereBase AND t.status = 'En Proceso'", $params);
$done       = countWhere($pdo, "$whereBase AND t.status = 'Resuelto'", $params);
$closed     = countWhere($pdo, "$whereBase AND (t.status = 'Cerrado' OR t.status = 'Closed')", $params);

// =====================================================
// Tiempo promedio de resolución
// =====================================================
$avgResolutionTime = 'N/A';
try {
    $avgStmt = $pdo->prepare("
        SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_at, t.$closedField)) AS avg_min
        FROM tickets t
        WHERE $whereBase $creatorFilterSQL
          AND t.$closedField IS NOT NULL
          AND t.created_at IS NOT NULL
    ");
    $avgStmt->execute($params);
    $avgMin = $avgStmt->fetchColumn();
    if ($avgMin !== null && $avgMin !== false) {
        $avgMin = (float)$avgMin;
        $h = floor($avgMin / 60);
        $m = round($avgMin % 60);
        $avgResolutionTime = ($h > 0 ? "{$h}h " : '') . "{$m}min";
    }
} catch (Throwable $e) {}

// =====================================================
// Productividad por agente
// =====================================================
$agentStats = [];
try {
    $agentStmt = $pdo->prepare("
        SELECT
            u.full_name AS agent_name,
            COUNT(*) AS tickets_resolved,
            AVG(TIMESTAMPDIFF(MINUTE, t.created_at, t.$closedField)) AS avg_min
        FROM tickets t
        LEFT JOIN users u ON u.id_user = t.assigned_user_id
        WHERE $whereBase
          AND t.assigned_user_id IS NOT NULL
          AND (t.status = 'Resuelto' OR t.status = 'Cerrado' OR t.status = 'Closed')
        GROUP BY t.assigned_user_id, u.full_name
        ORDER BY tickets_resolved DESC
    ");
    $agentStmt->execute($params);
    $agentStats = $agentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $agentStats = []; }

$totalResolved = 0;
foreach ($agentStats as $a) $totalResolved += (int)$a['tickets_resolved'];

// =====================================================
// Top categorías/tipos más reportados
// =====================================================
$topCategories = [];
try {
    $catStmt = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(t.category), ''), 'Uncategorized') AS category,
            COALESCE(NULLIF(TRIM(t.type), ''), 'No type') AS type,
            COUNT(*) AS total
        FROM tickets t
        WHERE $whereBase
        GROUP BY
            COALESCE(NULLIF(TRIM(t.category), ''), 'Uncategorized'),
            COALESCE(NULLIF(TRIM(t.type), ''), 'No type')
        ORDER BY total DESC
        LIMIT 10
    ");
    $catStmt->execute($params);
    $topCategories = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $topCategories = []; }

// =====================================================
// Tickets detallados
// =====================================================
$rows = [];
try {
    $detailStmt = $pdo->prepare("
        SELECT
            t.id_ticket,
            t.created_at,
            t.$closedField AS closed_at,
            t.area,
            COALESCE(NULLIF(TRIM(t.category), ''), 'N/A') AS category,
            COALESCE(NULLIF(TRIM(t.type), ''), '-') AS type,
            t.priority,
            t.status,
            COALESCE(u.full_name, '-') AS assigned_name,
            $creatorNameExpr AS created_by_name
        FROM tickets t
        LEFT JOIN users u ON u.id_user = t.assigned_user_id
        $creatorJoinSQL
        WHERE $sqlRange $creatorFilterSQL $extraWhere
        ORDER BY t.id_ticket DESC
        LIMIT 500
    ");
    $detailStmt->execute($params);
    $rows = $detailStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $rows = []; }

// =====================================================
// Nombre del creador seleccionado
// =====================================================
$selectedCreatorName = '';
if ($creatorId > 0) {
    try {
        $s = $pdo->prepare("SELECT full_name FROM users WHERE id_user = :id LIMIT 1");
        $s->execute([':id' => $creatorId]);
        $selectedCreatorName = (string)($s->fetchColumn() ?: '');
    } catch (Throwable $e) {}
}

// =====================================================
// Formateo
// =====================================================
$startText  = $dtStart->format('m/d/Y');
$endText    = $dtEnd->format('m/d/Y');
$now        = new DateTime('now');
$reportDate = $now->format('m/d/Y H:i');

// =====================================================
// GENERAR PDF
// =====================================================
class TicketReportPDF extends FPDF
{
    private string $reportTitle  = '';
    private string $reportDate   = '';
    private string $dateRange    = '';
    private string $logoPath     = '';

    // Brand colors
    private array $navy   = [8, 59, 92];
    private array $orange = [244, 122, 33];
    private array $white  = [255, 255, 255];
    private array $gray   = [108, 117, 125];
    private array $light  = [248, 250, 253];

    public function setup(string $title, string $date, string $range, string $logoPath): void
    {
        $this->reportTitle = $title;
        $this->reportDate  = $date;
        $this->dateRange   = $range;
        $this->logoPath    = $logoPath;
    }

    public function Header(): void
    {
        // Navy header bar
        $this->SetFillColor(...$this->navy);
        $this->Rect(0, 0, $this->GetPageWidth(), 38, 'F');

        // Logo
        if ($this->logoPath && file_exists($this->logoPath)) {
            $this->Image($this->logoPath, 10, 5, 28);
        }

        // Title
        $this->SetFont('Helvetica', 'B', 16);
        $this->SetTextColor(...$this->white);
        $this->SetXY(42, 8);
        $this->Cell(100, 8, $this->u('Ticket Report'), 0, 0);

        // Date range
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(200, 210, 220);
        $this->SetXY(42, 18);
        $this->Cell(100, 6, $this->u($this->dateRange), 0, 0);

        // Generation date (right)
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(180, 190, 200);
        $this->SetXY($this->GetPageWidth() - 80, 8);
        $this->Cell(70, 6, $this->u('Generated: ' . $this->reportDate), 0, 0, 'R');

        // Orange accent line
        $this->SetFillColor(...$this->orange);
        $this->Rect(0, 38, $this->GetPageWidth(), 2, 'F');

        $this->SetY(45);
    }

    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(...$this->gray);
        $this->Cell(0, 10, 'RH&R Universal Ticketing System - Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    public function SectionTitle(string $title): void
    {
        $this->Ln(4);
        $this->SetFillColor(...$this->orange);
        $y = $this->GetY();
        $this->Rect(10, $y, 3, 8, 'F');

        $this->SetX(16);
        $this->SetFont('Helvetica', 'B', 12);
        $this->SetTextColor(...$this->navy);
        $this->Cell(0, 8, $this->u($title), 0, 1);
        $this->Ln(2);
    }

    public function MetricRow(array $metrics): void
    {
        $w = ($this->GetPageWidth() - 20) / count($metrics);
        $x = 10;
        $yStart = $this->GetY();

        foreach ($metrics as $label => $value) {
            $this->SetFillColor(...$this->light);
            $this->Rect($x, $yStart, $w - 3, 18, 'F');

            $this->SetFont('Helvetica', 'B', 14);
            $this->SetTextColor(...$this->navy);
            $this->SetXY($x + 4, $yStart + 2);
            $this->Cell($w - 8, 8, $this->u((string)$value), 0, 0);

            $this->SetFont('Helvetica', '', 7);
            $this->SetTextColor(...$this->gray);
            $this->SetXY($x + 4, $yStart + 10);
            $this->Cell($w - 8, 5, $this->u(mb_strtoupper($label, 'UTF-8')), 0, 0);

            $x += $w;
        }
        $this->SetY($yStart + 22);
    }

    public function FancyTable(array $headers, array $widths, array $data, array $aligns = []): void
    {
        $this->SetFont('Helvetica', 'B', 7);
        $this->SetFillColor(...$this->navy);
        $this->SetTextColor(...$this->white);

        for ($i = 0; $i < count($headers); $i++) {
            $align = $aligns[$i] ?? 'L';
            $this->Cell($widths[$i], 7, $this->u(mb_strtoupper($headers[$i], 'UTF-8')), 0, 0, $align, true);
        }
        $this->Ln();

        $this->SetFont('Helvetica', '', 7);
        $fill = false;
        foreach ($data as $row) {
            if ($this->GetY() + 7 > $this->GetPageHeight() - 20) {
                $this->AddPage();
            }

            $this->SetFillColor(248, 250, 253);
            $this->SetTextColor(15, 23, 42);

            for ($i = 0; $i < count($row); $i++) {
                $align = $aligns[$i] ?? 'L';
                $this->Cell($widths[$i], 6, $this->u((string)$row[$i]), 0, 0, $align, $fill);
            }
            $this->Ln();
            $fill = !$fill;
        }
    }

    public function ProgressBar(float $x, float $y, float $w, float $h, float $pct): void
    {
        $this->SetFillColor(230, 233, 237);
        $this->Rect($x, $y, $w, $h, 'F');
        if ($pct > 0) {
            $this->SetFillColor(...$this->orange);
            $this->Rect($x, $y, $w * min($pct / 100, 1), $h, 'F');
        }
    }

    /** UTF-8 to Latin1 for FPDF */
    public function u(string $s): string
    {
        return @mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8') ?: $s;
    }
}

// ── Build the PDF ──────────────────────────────────────
$pdf = new TicketReportPDF('L', 'mm', 'Letter');
$pdf->AliasNbPages();
$pdf->setup(
    'Ticket Report',
    $reportDate,
    "From $startText to $endText" . ($selectedCreatorName ? " | Creator: $selectedCreatorName" : ''),
    __DIR__ . '/assets/img/RHR UNIVERSAL-01.png'
);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// ── 1. Executive Summary ────────────────────────────────
$pdf->SectionTitle('Executive Summary');
$pdf->MetricRow([
    'Total Tickets'  => $total,
    'Assigned'       => $assigned,
    'Unassigned'     => $unassigned,
    'In Progress'    => $inprogress,
    'Resolved'       => $done,
    'Closed'         => $closed,
]);

$pdf->MetricRow([
    'Avg. Resolution Time' => $avgResolutionTime,
    'Resolution Rate'      => $total > 0 ? round(($done + $closed) / $total * 100) . '%' : '0%',
    'Pending Tickets'      => max(0, $total - $done - $closed),
]);

// ── 2. Agent Productivity ──────────────────────────────
if ($agentStats) {
    $pdf->SectionTitle('Agent Productivity');

    $headers = ['Agent', 'Tickets Resolved', 'Avg. Time', '% of Total', ''];
    $widths  = [70, 40, 40, 30, 80];
    $aligns  = ['L', 'C', 'C', 'C', 'L'];

    $tableData = [];
    foreach ($agentStats as $a) {
        $name = $a['agent_name'] ?: '(Unknown)';
        $count = (int)$a['tickets_resolved'];
        $pct = $totalResolved > 0 ? round($count / $totalResolved * 100, 1) : 0;

        $avgTime = 'N/A';
        if ($a['avg_min'] !== null) {
            $am = (float)$a['avg_min'];
            $ah = floor($am / 60);
            $amn = round($am % 60);
            $avgTime = ($ah > 0 ? "{$ah}h " : '') . "{$amn}min";
        }

        $tableData[] = [$name, (string)$count, $avgTime, "{$pct}%", ''];
    }

    $pdf->FancyTable($headers, $widths, $tableData, $aligns);

    // Progress bars
    $barY = $pdf->GetY() + 2;
    $pdf->SetFont('Helvetica', '', 7);
    foreach ($agentStats as $i => $a) {
        $count = (int)$a['tickets_resolved'];
        $pct = $totalResolved > 0 ? round($count / $totalResolved * 100, 1) : 0;
        $name = $a['agent_name'] ?: '(Unknown)';

        if ($barY + 8 > $pdf->GetPageHeight() - 20) break;

        $pdf->SetXY(10, $barY);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(60, 5, $pdf->u($name), 0, 0);
        $pdf->ProgressBar(75, $barY + 1, 120, 3, (float)$pct);
        $pdf->SetXY(200, $barY);
        $pdf->SetTextColor(108, 117, 125);
        $pdf->Cell(30, 5, $pdf->u("$count tickets ({$pct}%)"), 0, 0, 'R');
        $barY += 7;
    }

    $pdf->SetY($barY + 4);
}

// ── 3. Top Reported Issues ───────────────────────────────
if ($topCategories) {
    $pdf->SectionTitle('Top Reported Issues');
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(108, 117, 125);
    $pdf->SetX(16);
    $pdf->Cell(0, 5, $pdf->u(
        'Justification: The following issue types are the most frequent in the selected period.'
    ), 0, 1);
    $pdf->Ln(2);

    $headers = ['#', 'Category', 'Issue Type', 'Incidents', '% of Total'];
    $widths  = [12, 50, 80, 30, 30];
    $aligns  = ['C', 'L', 'L', 'C', 'C'];

    $tableData = [];
    foreach ($topCategories as $i => $c) {
        $pct = $total > 0 ? round((int)$c['total'] / $total * 100, 1) : 0;
        $tableData[] = [
            ($i + 1),
            $c['category'],
            $c['type'],
            (string)$c['total'],
            "{$pct}%",
        ];
    }

    $pdf->FancyTable($headers, $widths, $tableData, $aligns);
    $pdf->Ln(4);
}

// ── 4. Ticket Details ────────────────────────────────────
$pdf->AddPage();
$pdf->SectionTitle('Ticket Details');

$viewLabels = [
    'total'=>'Total','assigned'=>'Assigned','unassigned'=>'Unassigned',
    'inprogress'=>'In Progress','done'=>'Resolved','closed'=>'Closed'
];
$filterLabel = $viewLabels[$view] ?? 'Total';
if ($cat !== '') $filterLabel .= " | Category: $cat";

$pdf->SetFont('Helvetica', '', 8);
$pdf->SetTextColor(108, 117, 125);
$pdf->SetX(16);
$pdf->Cell(0, 5, $pdf->u("Filter: $filterLabel | Total: " . count($rows) . " tickets"), 0, 1);
$pdf->Ln(2);

$headers = ['ID', 'Created', 'Closed', 'Area', 'Category', 'Type', 'Priority', 'Status', 'Assigned to', 'Created by', 'Res. Time'];
$widths  = [14, 26, 26, 28, 22, 28, 18, 20, 28, 28, 22];
$aligns  = ['C', 'C', 'C', 'L', 'L', 'L', 'C', 'C', 'L', 'L', 'C'];

$tableData = [];
foreach ($rows as $r) {
    $createdTxt = '-';
    $closedTxt  = '-';
    $resTxt     = '-';

    try { $createdTxt = (new DateTime($r['created_at']))->format('m/d/y H:i'); } catch (Throwable $e) {}
    if (!empty($r['closed_at'])) {
        try {
            $dtC = new DateTime($r['closed_at']);
            $closedTxt = $dtC->format('m/d/y H:i');

            $dtS = new DateTime($r['created_at']);
            $diff = $dtS->diff($dtC);
            $parts = [];
            if ($diff->d > 0) $parts[] = $diff->d . 'd';
            if ($diff->h > 0) $parts[] = $diff->h . 'h';
            if ($diff->i > 0) $parts[] = $diff->i . 'm';
            if (!$parts) $parts[] = '0m';
            $resTxt = implode(' ', array_slice($parts, 0, 2));
        } catch (Throwable $e) {}
    }

    $tableData[] = [
        (int)$r['id_ticket'],
        $createdTxt,
        $closedTxt,
        mb_substr($r['area'] ?? '-', 0, 14),
        mb_substr($r['category'] ?? '-', 0, 12),
        mb_substr($r['type'] ?? '-', 0, 14),
        getPriorityEn($r['priority'] ?? '-'),
        getStatusEn($r['status'] ?? '-'),
        mb_substr($r['assigned_name'] ?? '-', 0, 14),
        mb_substr($r['created_by_name'] ?: '-', 0, 14),
        $resTxt,
    ];
}

$pdf->FancyTable($headers, $widths, $tableData, $aligns);

// ── Output ─────────────────────────────────────────────
$filename = "Ticket_Report_{$start}_to_{$end}.pdf";
$pdf->Output('D', $filename);
exit;
