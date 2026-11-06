<?php
// api/generate_report.php — CSV + PDF (professional, streamed)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$type   = $_GET['type']   ?? $_POST['report_type'] ?? '';
$format = strtolower($_GET['format'] ?? $_POST['format'] ?? 'csv');

$gender = gender_filter();
$gender_display = $gender ? "{$gender} Wing" : "All Wings";

$activeSid = active_semester_id();
$activeSem = active_semester();
$semLabel  = $activeSem ? $activeSem['name'] : 'No active semester';

$allowed = ['students','rooms','blocks','bookings','unplaced','staff','occupancy'];
if (!in_array($type, $allowed, true))        { http_response_code(400); exit('Invalid report type'); }
if ($type === 'staff' && !is_admin())        { http_response_code(403); exit('Forbidden'); }
if (!in_array($format, ['csv','pdf'], true)) { http_response_code(400); exit('Invalid format'); }

// ==========================================================
// FETCH DATA
// ==========================================================
$headers  = [];
$rows     = [];
$title    = '';
$subtitle = '';

$placedExpr = $activeSid
    ? "EXISTS (SELECT 1 FROM bookings b WHERE b.student_id = s.id AND b.status='confirmed' AND b.semester_id = " . (int)$activeSid . ")"
    : "s.placed = 1";

switch ($type) {

    case 'students':
        $title    = 'Student Roster';
        $subtitle = $activeSid ? "Placement for {$semLabel}" : 'All active students';
        $headers  = ['Reg #','Full Name','Email','Gender','Year','Room','Building','Phase 1','Placed this Sem'];
        $sql = "SELECT s.reg_number, s.full_name, s.email, s.gender, s.year_of_study,
                       r.room_number, b.name AS block_name, s.phase1_action,
                       ($placedExpr) AS placed_now
                FROM students s
                LEFT JOIN rooms r ON r.id = s.current_room_id
                LEFT JOIN blocks b ON b.id = r.block_id
                WHERE s.is_active = 1";
        $params = [];
        if ($gender) { $sql .= " AND s.gender = ?"; $params[] = $gender; }
        $sql .= " ORDER BY s.gender, s.full_name";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        foreach ($stmt as $r) {
            $rows[] = [
                $r['reg_number'], $r['full_name'], $r['email'], $r['gender'],
                $r['year_of_study'], $r['room_number'] ?? '—', $r['block_name'] ?? '—',
                ucfirst($r['phase1_action']),
                $r['placed_now'] ? 'Yes' : 'No',
            ];
        }
        break;

    case 'rooms':
        $title    = 'Rooms Inventory';
        $subtitle = 'All rooms across active blocks';
        $headers  = ['Room #','Building','Wing','Capacity','Status'];
        $sql = "SELECT r.room_number, b.name AS block_name, r.gender, r.capacity, r.status
                FROM rooms r JOIN blocks b ON b.id = r.block_id
                WHERE 1=1";
        $params = [];
        if ($gender) { $sql .= " AND r.gender = ?"; $params[] = $gender; }
        $sql .= " ORDER BY b.name, r.room_number";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        foreach ($stmt as $r) {
            $rows[] = [
                $r['room_number'], $r['block_name'], $r['gender'],
                (int)$r['capacity'], ucfirst(str_replace('_',' ', $r['status'])),
            ];
        }
        break;

    case 'blocks':
        $title    = 'Buildings';
        $subtitle = 'All hostel blocks with occupancy breakdown';
        $headers  = ['Building','Wing','Rooms','Occupied','Vacant','Released','Reserved'];
        $sql = "SELECT b.name, b.gender,
                       COUNT(r.id) AS rooms,
                       SUM(CASE WHEN r.status='occupied'     THEN 1 ELSE 0 END) AS occupied,
                       SUM(CASE WHEN r.status='vacant'       THEN 1 ELSE 0 END) AS vacant,
                       SUM(CASE WHEN r.status='available_p2' THEN 1 ELSE 0 END) AS released,
                       SUM(CASE WHEN r.status='reserved'     THEN 1 ELSE 0 END) AS reserved
                FROM blocks b LEFT JOIN rooms r ON r.block_id = b.id
                WHERE b.is_active = 1";
        $params = [];
        if ($gender) { $sql .= " AND b.gender = ?"; $params[] = $gender; }
        $sql .= " GROUP BY b.id ORDER BY b.gender, b.name";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        foreach ($stmt as $r) {
            $rows[] = [
                $r['name'], $r['gender'], (int)$r['rooms'], (int)$r['occupied'],
                (int)$r['vacant'], (int)$r['released'], (int)$r['reserved'],
            ];
        }
        break;

    case 'bookings':
        $title    = 'Confirmed Bookings';
        $subtitle = $activeSem ? "Semester: {$semLabel}" : 'All confirmed bookings';
        $headers  = ['Reg #','Full Name','Wing','Room','Building','Phase','M-Pesa','Penalty','Approved','By'];
        $sql = "SELECT s.reg_number, s.full_name, s.gender,
                       r.room_number, bl.name AS block_name,
                       b.phase, b.mpesa_ref, b.penalty_amount, b.approved_at, u.full_name AS approver
                FROM bookings b
                JOIN students s ON s.id = b.student_id
                JOIN rooms r    ON r.id = b.room_id
                JOIN blocks bl  ON bl.id = r.block_id
                LEFT JOIN users u ON u.id = b.approved_by
                WHERE b.status = 'confirmed'";
        $params = [];
        if ($activeSid) { $sql .= " AND b.semester_id = ?"; $params[] = $activeSid; }
        if ($gender)    { $sql .= " AND s.gender = ?";      $params[] = $gender; }
        $sql .= " ORDER BY b.approved_at DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        foreach ($stmt as $r) {
            $rows[] = [
                $r['reg_number'], $r['full_name'], $r['gender'],
                $r['room_number'], $r['block_name'], 'P'.$r['phase'],
                $r['mpesa_ref'] ?? '—',
                (int)$r['penalty_amount'] > 0 ? 'KES '.number_format($r['penalty_amount']) : '—',
                $r['approved_at'] ? date('M j, Y', strtotime($r['approved_at'])) : '—',
                $r['approver'] ?? '—',
            ];
        }
        break;

    case 'unplaced':
        $title    = 'Unplaced Students';
        $subtitle = $activeSem ? "No confirmed room for {$semLabel}" : 'Students without a confirmed room';
        $headers  = ['Reg #','Full Name','Email','Wing','Year','Old Room','Phase 1'];
        $sql = "SELECT s.reg_number, s.full_name, s.email, s.gender, s.year_of_study,
                       r.room_number AS old_room, s.phase1_action
                FROM students s LEFT JOIN rooms r ON r.id = s.current_room_id
                WHERE s.is_active = 1 AND NOT ($placedExpr)";
        $params = [];
        if ($gender) { $sql .= " AND s.gender = ?"; $params[] = $gender; }
        $sql .= " ORDER BY s.gender, s.full_name";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        foreach ($stmt as $r) {
            $rows[] = [
                $r['reg_number'], $r['full_name'], $r['email'], $r['gender'],
                $r['year_of_study'], $r['old_room'] ?? '—', ucfirst($r['phase1_action']),
            ];
        }
        break;

    case 'staff':
        $title    = 'Staff Directory';
        $subtitle = 'All ComfortBook staff accounts';
        $headers  = ['Username','Full Name','Email','Role','Wing','Status','Last Login'];
        $stmt = $pdo->query("
            SELECT username, full_name, email, role, gender_scope, is_active, last_login
            FROM users ORDER BY role, full_name
        ");
        foreach ($stmt as $r) {
            $rows[] = [
                $r['username'], $r['full_name'], $r['email'],
                ucwords(str_replace('_',' ', $r['role'])),
                $r['gender_scope'] ?? 'Both',
                $r['is_active'] ? 'Active' : 'Inactive',
                $r['last_login'] ? date('M j, Y', strtotime($r['last_login'])) : '—',
            ];
        }
        break;

    case 'occupancy':
        $title    = 'Occupancy Report';
        $subtitle = 'Per-building room usage';
        $headers  = ['Building','Wing','Rooms','Occupied','Vacant','Released','Reserved','Occupancy %'];
        $sql = "SELECT b.name, b.gender,
                       COUNT(r.id) AS total_rooms,
                       SUM(CASE WHEN r.status='occupied'     THEN 1 ELSE 0 END) AS occupied,
                       SUM(CASE WHEN r.status='vacant'       THEN 1 ELSE 0 END) AS vacant,
                       SUM(CASE WHEN r.status='available_p2' THEN 1 ELSE 0 END) AS released,
                       SUM(CASE WHEN r.status='reserved'     THEN 1 ELSE 0 END) AS reserved
                FROM blocks b LEFT JOIN rooms r ON r.block_id = b.id
                WHERE b.is_active = 1";
        $params = [];
        if ($gender) { $sql .= " AND b.gender = ?"; $params[] = $gender; }
        $sql .= " GROUP BY b.id ORDER BY b.gender, b.name";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        foreach ($stmt as $r) {
            $pct = (int)$r['total_rooms'] > 0
                ? round(((int)$r['occupied'] / (int)$r['total_rooms']) * 100, 1)
                : 0;
            $rows[] = [
                $r['name'], $r['gender'], (int)$r['total_rooms'], (int)$r['occupied'],
                (int)$r['vacant'], (int)$r['released'], (int)$r['reserved'], $pct.'%',
            ];
        }
        break;
}

audit_log($_SESSION['user_id'], null, "{$format}_download", "Downloaded $format: $type");

$stamp = date('Ymd_His');
$wing  = $gender ? strtolower($gender) . '_' : '';
$base  = "comfortbook_{$wing}{$type}_{$stamp}";

// ==========================================================
// CSV
// ==========================================================
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $base . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $r) fputcsv($out, array_map(fn($v) => (string)($v ?? ''), $r));
    fclose($out);
    exit;
}

// ==========================================================
// PDF
// ==========================================================
require_once __DIR__ . '/../tcpdf/tcpdf.php';

class ComfortBook_PDF extends TCPDF {
    public $document_title = '';
    public $document_subtitle = '';
    public $wing_label = '';
    public $wing_color = '#003366';

    public function setDocHeader($title, $subtitle, $wing, $color) {
        $this->document_title    = $title;
        $this->document_subtitle = $subtitle;
        $this->wing_label        = $wing;
        $this->wing_color        = $color;
        $this->SetTitle($title);
    }

    public function Header() {
        // Top color strip
        $rgb = $this->hexToRgb($this->wing_color);
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $this->Rect(0, 0, $this->getPageWidth(), 4, 'F');

        // Logo
        $logo = ROOT_PATH . '/assets/logo/ueab.png';
        if (file_exists($logo)) {
            $this->Image($logo, 15, 8, 20, 20, 'PNG', '', 'T', false, 300);
        }

        // University name
        $this->SetTextColor(0, 51, 102);
        $this->SetFont('helvetica', 'B', 13);
        $this->SetXY(40, 8);
        $this->Cell(0, 6, 'UNIVERSITY OF EASTERN AFRICA, BARATON', 0, 0, 'L');

        // Sub-line
        $this->SetFont('helvetica', '', 8);
        $this->SetXY(40, 14);
        $this->Cell(0, 4, 'ComfortBook — Hostel Booking System', 0, 0, 'L');

        // Document title
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', 'B', 12);
        $this->SetXY(40, 19);
        $this->Cell(0, 5, strtoupper($this->document_title), 0, 0, 'L');

        // Subtitle
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(80, 80, 80);
        $this->SetXY(40, 24);
        $this->Cell(0, 4, $this->document_subtitle, 0, 0, 'L');

        // Wing + date (right-aligned)
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', 'B', 9);
        $this->SetXY(0, 8);
        $this->Cell($this->getPageWidth() - 15, 5, $this->wing_label, 0, 0, 'R');

        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(80, 80, 80);
        $this->SetXY(0, 14);
        $this->Cell($this->getPageWidth() - 15, 4, 'Generated: ' . date('M j, Y H:i'), 0, 0, 'R');

        // Divider line
        $this->SetDrawColor(200, 200, 200);
        $this->Line(15, 32, $this->getPageWidth() - 15, 32);
    }

    public function Footer() {
        $this->SetY(-12);
        $this->SetDrawColor(220, 220, 220);
        $this->Line(15, $this->GetY(), $this->getPageWidth() - 15, $this->GetY());
        $this->SetY(-10);
        $this->SetFont('helvetica', 'I', 7);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 4, 'ComfortBook · UEAB Hostel Booking · Confidential', 0, 0, 'L');
        $this->Cell(0, 4, 'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, 0, 'R');
    }

    public function hexToRgb($hex) {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
    }
}

// ==========================================================
// ORIENTATION — explicit per report
// ==========================================================
$landscapeReports = ['students', 'bookings', 'unplaced'];
$landscape = in_array($type, $landscapeReports, true);

$pdf = new ComfortBook_PDF($landscape ? 'L' : 'P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

$wingColor = $gender === 'Female' ? '#dc3545' : '#003366';
$pdf->setDocHeader($title, $subtitle, $gender_display, $wingColor);
$pdf->SetCreator('ComfortBook');
$pdf->SetAuthor($_SESSION['full_name'] ?? 'Staff');
$pdf->SetMargins(15, 38, 15);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(15);
$pdf->SetAutoPageBreak(TRUE, 20);
$pdf->AddPage();

// ---- Summary line
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(60, 60, 60);
$pdf->SetXY(15, 36);
$pdf->Cell(0, 5, 'Total records: ' . count($rows), 0, 1, 'L');
$pdf->Ln(2);

// ---- Column widths
$pageWidth = $pdf->getPageWidth() - 30;
$colWidth  = $pageWidth / count($headers);

// Portrait vs landscape sizing
$rowFont = $landscape ? 7.5 : 7;
$rowH    = $landscape ? 5.5 : 5;

// ---- Table header
$rgb = $pdf->hexToRgb($wingColor);
$pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetDrawColor(255, 255, 255);
foreach ($headers as $h) {
    $pdf->Cell($colWidth, 7, $h, 1, 0, 'C', true);
}
$pdf->Ln();

// ---- Rows
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', $rowFont);
$pdf->SetDrawColor(220, 220, 220);
$rowCount = 0;
foreach ($rows as $r) {
    $rowCount++;
    if ($rowCount % 2 === 0) $pdf->SetFillColor(245, 248, 252);
    else                     $pdf->SetFillColor(255, 255, 255);
    foreach ($r as $v) {
        $val   = (string)($v ?? '');
        $align = is_numeric(str_replace(['KES ', ',', '%'], '', $val)) ? 'R' : 'L';
        $pdf->Cell($colWidth, $rowH, $val, 1, 0, $align, true);
    }
    $pdf->Ln();
}

$pdf->Output($base . '.pdf', 'I');
exit;