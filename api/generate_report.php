<?php
// api/generate_report.php — CSV + PDF export endpoint
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$type   = $_GET['type']   ?? $_POST['report_type'] ?? '';
$format = strtolower($_GET['format'] ?? $_POST['format'] ?? 'csv');

$gender = gender_filter();       // null = admin (all wings)
$gender_display = $gender ?: 'All Wings';

$allowed = ['students','rooms','blocks','bookings','unplaced','staff','occupancy'];
if (!in_array($type, $allowed, true)) {
    http_response_code(400); exit('Invalid report type');
}
if ($type === 'staff' && !is_admin()) {
    http_response_code(403); exit('Forbidden');
}
if (!in_array($format, ['csv','pdf'], true)) {
    http_response_code(400); exit('Invalid format');
}

// ==========================================================
// DATA — fetch rows once, reuse for CSV + PDF
// ==========================================================
$headers = [];
$rows    = [];
$title   = '';

switch ($type) {

    case 'students':
        $title = 'STUDENT ROSTER';
        $headers = ['Reg #','Full Name','Email','Phone','Gender','Year','Course','Current Room','Building','Phase 1','Placed','Late Bookers','Registered'];
        $sql = "SELECT s.reg_number, s.full_name, s.email, s.phone, s.gender, s.year_of_study, s.course,
                       r.room_number, b.name AS block_name, s.phase1_action, s.placed, s.late_booker, s.created_at
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
                $r['reg_number'], $r['full_name'], $r['email'], $r['phone'] ?? '', $r['gender'],
                $r['year_of_study'], $r['course'] ?? '', $r['room_number'] ?? '',
                $r['block_name'] ?? '', ucfirst($r['phase1_action']),
                $r['placed'] ? 'Yes' : 'No', $r['late_booker'] ? 'Yes' : 'No',
                $r['created_at'],
            ];
        }
        break;

    case 'rooms':
        $title = 'ROOMS INVENTORY';
        $headers = ['Room #','Building','Wing','Capacity','Status','Reserved By'];
        $sql = "SELECT r.room_number, b.name AS block_name, r.gender, r.capacity, r.status,
                       rs.full_name AS reserved_by
                FROM rooms r JOIN blocks b ON b.id = r.block_id
                LEFT JOIN students rs ON rs.id = r.reserved_by_student_id
                WHERE 1=1";
        $params = [];
        if ($gender) { $sql .= " AND r.gender = ?"; $params[] = $gender; }
        $sql .= " ORDER BY b.name, r.room_number";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        foreach ($stmt as $r) {
            $rows[] = [
                $r['room_number'], $r['block_name'], $r['gender'], $r['capacity'],
                ucfirst(str_replace('_',' ', $r['status'])), $r['reserved_by'] ?? '',
            ];
        }
        break;

    case 'blocks':
        $title = 'BUILDINGS';
        $headers = ['Building','Wing','Description','Active','Rooms','Occupied','Vacant','Released','Reserved'];
        $sql = "SELECT b.name, b.gender, b.description, b.is_active,
                       COUNT(r.id) AS rooms,
                       SUM(CASE WHEN r.status='occupied'     THEN 1 ELSE 0 END) AS occupied,
                       SUM(CASE WHEN r.status='vacant'       THEN 1 ELSE 0 END) AS vacant,
                       SUM(CASE WHEN r.status='available_p2' THEN 1 ELSE 0 END) AS released,
                       SUM(CASE WHEN r.status='reserved'     THEN 1 ELSE 0 END) AS reserved
                FROM blocks b LEFT JOIN rooms r ON r.block_id = b.id
                WHERE 1=1";
        $params = [];
        if ($gender) { $sql .= " AND b.gender = ?"; $params[] = $gender; }
        $sql .= " GROUP BY b.id ORDER BY b.gender, b.name";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        foreach ($stmt as $r) {
            $rows[] = [
                $r['name'], $r['gender'], $r['description'] ?? '', $r['is_active'] ? 'Yes' : 'No',
                $r['rooms'], $r['occupied'], $r['vacant'], $r['released'], $r['reserved'],
            ];
        }
        break;

    case 'bookings':
        $title = 'CONFIRMED BOOKINGS';
        $headers = ['Reg #','Full Name','Gender','Room','Building','Phase','Action','M-Pesa Ref','Penalty (KES)','Approved At','Approved By'];
        $sql = "SELECT s.reg_number, s.full_name, s.gender, r.room_number, bl.name AS block_name,
                       b.phase, b.action, b.mpesa_ref, b.penalty_amount, b.approved_at, u.full_name AS approver
                FROM bookings b
                JOIN students s ON s.id = b.student_id
                JOIN rooms r    ON r.id = b.room_id
                JOIN blocks bl  ON bl.id = r.block_id
                LEFT JOIN users u ON u.id = b.approved_by
                WHERE b.status = 'confirmed'";
        $params = [];
        if ($gender) { $sql .= " AND s.gender = ?"; $params[] = $gender; }
        $sql .= " ORDER BY b.approved_at DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        foreach ($stmt as $r) {
            $rows[] = [
                $r['reg_number'], $r['full_name'], $r['gender'], $r['room_number'], $r['block_name'],
                'P'.$r['phase'], $r['action'], $r['mpesa_ref'] ?? '', $r['penalty_amount'],
                $r['approved_at'] ?? '', $r['approver'] ?? '',
            ];
        }
        break;

    case 'unplaced':
        $title = 'UNPLACED STUDENTS';
        $headers = ['Reg #','Full Name','Email','Phone','Gender','Year','Old Room','Phase 1 Action'];
        $sql = "SELECT s.reg_number, s.full_name, s.email, s.phone, s.gender,
                       s.year_of_study, r.room_number AS old_room, s.phase1_action
                FROM students s LEFT JOIN rooms r ON r.id = s.current_room_id
                WHERE s.is_active = 1 AND s.placed = 0";
        $params = [];
        if ($gender) { $sql .= " AND s.gender = ?"; $params[] = $gender; }
        $sql .= " ORDER BY s.gender, s.full_name";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        foreach ($stmt as $r) {
            $rows[] = [
                $r['reg_number'], $r['full_name'], $r['email'], $r['phone'] ?? '', $r['gender'],
                $r['year_of_study'], $r['old_room'] ?? '', ucfirst($r['phase1_action']),
            ];
        }
        break;

    case 'staff':
        $title = 'STAFF DIRECTORY';
        $headers = ['Username','Full Name','Email','Phone','Role','Gender Scope','Active','Last Login','Created'];
        $stmt = $pdo->query("SELECT username, full_name, email, phone, role, gender_scope, is_active, last_login, created_at FROM users ORDER BY role, full_name");
        foreach ($stmt as $r) {
            $rows[] = [
                $r['username'], $r['full_name'], $r['email'], $r['phone'] ?? '',
                ucwords(str_replace('_',' ', $r['role'])), $r['gender_scope'] ?? 'Both',
                $r['is_active'] ? 'Yes' : 'No', $r['last_login'] ?? '', $r['created_at'],
            ];
        }
        break;

    case 'occupancy':
        $title = 'OCCUPANCY REPORT';
        $headers = ['Building','Wing','Rooms','Occupied','Vacant','Released','Reserved','Occupancy %'];
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
            $pct = (int)$r['total_rooms'] > 0 ? round(((int)$r['occupied'] / (int)$r['total_rooms']) * 100, 1) : 0;
            $rows[] = [
                $r['name'], $r['gender'], $r['total_rooms'], $r['occupied'],
                $r['vacant'], $r['released'], $r['reserved'], $pct . '%',
            ];
        }
        break;
}

// Log the download
audit_log($_SESSION['user_id'], null, "{$format}_download", "Downloaded $format: $type");

$stamp = date('Ymd_His');
$wing  = $gender ? strtolower($gender) . '_' : '';
$base  = "comfortbook_{$wing}{$type}_{$stamp}";

// ==========================================================
// CSV OUTPUT
// ==========================================================
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $base . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM for Excel
    fputcsv($out, $headers);
    foreach ($rows as $r) fputcsv($out, array_map(fn($v) => (string)($v ?? ''), $r));
    fclose($out);
    exit;
}

// ==========================================================
// PDF OUTPUT
// ==========================================================
require_once __DIR__ . '/../tcpdf/tcpdf.php';

class ComfortBook_PDF extends TCPDF {
    public $document_title = '';
    public $gender_display = '';

    public function setDocumentTitle($t) { $this->document_title = $t; $this->SetTitle($t); }
    public function setGenderDisplay($g) { $this->gender_display = $g; }

    public function Header() {
        if (file_exists(__DIR__ . '/../assets/logo/ueab.png')) {
            $this->Image(__DIR__ . '/../assets/logo/ueab.png', 15, 8, 22, 22, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }
        $this->SetFont('helvetica', 'B', 15);
        $this->SetY(9); $this->SetX(42);
        $this->Cell(0, 8, 'UNIVERSITY OF EASTERN AFRICA, BARATON', 0, false, 'L');

        $this->SetFont('helvetica', '', 9);
        $this->SetY(17); $this->SetX(42);
        $this->Cell(0, 6, 'ComfortBook — Hostel Booking System', 0, false, 'L');

        $this->SetFont('helvetica', 'B', 11);
        $this->SetY(24); $this->SetX(42);
        $this->Cell(0, 6, $this->document_title, 0, false, 'L');

        $this->SetY(9);
        $this->SetFont('helvetica', '', 8);
        $this->Cell(0, 5, 'Generated: ' . date('M j, Y H:i'), 0, false, 'R');
        $this->SetY(14);
        $this->SetFont('helvetica', 'B', 9);
        $this->Cell(0, 5, $this->gender_display, 0, false, 'R');

        $this->SetY(33);
        $this->SetLineStyle(['width'=>0.5, 'color'=>[0,0,0]]);
        $this->Line(15, 33, 195, 33);
    }

    public function Footer() {
        $this->SetY(-13);
        $this->SetFont('helvetica', 'I', 7);
        $this->SetLineStyle(['width'=>0.2, 'color'=>[150,150,150]]);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->SetY(-11);
        $this->Cell(0, 6, 'ComfortBook · UEAB Hostel Booking', 0, false, 'L');
        $this->Cell(0, 6, 'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'R');
    }
}

// Build HTML table
$bg = ($gender === 'Female') ? '#dc3545' : '#003366';
$html  = '<h3 style="color:#003366;margin:0 0 6px 0;">' . h($title) . '</h3>';
$html .= '<p style="font-size:9px;margin:0 0 10px 0;"><strong>Total Records:</strong> ' . count($rows) . '</p>';
$html .= '<table border="1" cellpadding="3" cellspacing="0" style="font-size:8px;">';
$html .= '<thead><tr style="background-color:' . $bg . '; color:white; font-weight:bold;">';
foreach ($headers as $c) $html .= '<th>' . h($c) . '</th>';
$html .= '</tr></thead><tbody>';

if (empty($rows)) {
    $html .= '<tr><td colspan="' . count($headers) . '" style="text-align:center;">No records</td></tr>';
} else {
    foreach ($rows as $r) {
        $html .= '<tr>';
        foreach ($r as $v) {
            $html .= '<td>' . h(mb_substr((string)($v ?? ''), 0, 60)) . '</td>';
        }
        $html .= '</tr>';
    }
}
$html .= '</tbody></table>';

$pdf = new ComfortBook_PDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->setDocumentTitle($title);
$pdf->setGenderDisplay($gender ? "{$gender} Wing" : "All Wings");
$pdf->SetCreator('ComfortBook');
$pdf->SetAuthor($_SESSION['full_name'] ?? 'Staff');
$pdf->SetMargins(15, 38, 15);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(15);
$pdf->SetAutoPageBreak(TRUE, 20);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 9);
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output($base . '.pdf', 'I');
exit;