<?php
// includes/ai/docs.php — real, designed PDF and Word (.docx) files in plain PHP (no libraries).
// Design: UEAB navy + gold, crest logo (assets/logo/ueab.png), stat tiles, zebra tables with repeating header rows, numbers right-aligned,
// page "x of y", and LANDSCAPE chosen automatically when a table is wide (6+ columns or too wide for A4 portrait).
// $sections = [['heading'=>'', 'text'=>'', 'bullets'=>[], 'stats'=>[[label,value],...], 'columns'=>[], 'rows'=>[[...]]], ...]

function ai_doc_txt(string $s): string {          // UTF-8 -> Windows-1252 bytes the PDF fonts understand
    $s = strtr($s, ['—' => '-', '–' => '-', '…' => '...', "\u{2192}" => '->', "\u{2713}" => 'v', "\u{00A0}" => ' ', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '•' => '*']);
    $r = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
    return $r === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $r;
}
function ai_doc_logo_path(): string { return dirname(__DIR__, 2) . '/assets/logo/ueab.png'; }
function ai_doc_is_num(string $s): bool { return (bool)preg_match('/^[\-\+]?(?:KES\s*)?\d[\d,\.\s]*%?$|^\d+\s*(?:of|\/)\s*\d+$/i', trim($s)); }
function ai_doc_tone(string $s): string {
    $t = strtolower(trim($s));
    if (in_array($t, ['success', 'successful', 'paid', 'confirmed', 'approved', 'ready', 'done'], true)) return 'good';
    if (in_array($t, ['failed', 'rejected', 'cancelled', 'canceled', 'invalid', 'error', 'expired'], true)) return 'bad';
    return '';
}
/** Approximate Helvetica text width in points. */
function ai_doc_w(string $t, float $size, bool $bold = false): float {
    static $w = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    $s = 0; for ($i = 0, $l = strlen($t); $i < $l; $i++) { $o = ord($t[$i]); $s += ($o >= 32 && $o <= 126) ? $w[$o - 32] : 556; }
    return $s * $size / 1000 * ($bold ? 1.06 : 1);
}
/** Portrait or landscape? Explicit choice wins; otherwise any table with 6+ columns, or too wide for A4 portrait, goes landscape. */
function ai_doc_is_landscape(array $sections, string $orient = 'auto'): bool {
    if ($orient === 'landscape') return true; if ($orient === 'portrait') return false;
    foreach ($sections as $s) {
        $cols = array_values((array)($s['columns'] ?? [])); $rows = (array)($s['rows'] ?? []); if (!$cols || !$rows) continue;
        if (count($cols) >= 6) return true; $need = 0;
        foreach ($cols as $i => $c) { $m = ai_doc_w((string)$c, 9, true) + 16; foreach (array_slice($rows, 0, 30) as $r) $m = max($m, min(170, ai_doc_w((string)($r[$i] ?? ''), 9) + 16)); $need += $m; }
        if ($need > 520) return true;
    }
    return false;
}
/** The crest for the PDF: a JPEG on white via GD, else a plain RGB/palette PNG passed through. Null when there is no logo file. */
function ai_doc_logo_pdf(): ?array {
    static $c = false; if ($c !== false) return $c; $f = ai_doc_logo_path(); $c = null; if (!is_file($f)) return $c;
    if (function_exists('imagecreatefrompng') && ($im = @imagecreatefrompng($f))) {
        $w = imagesx($im); $h = imagesy($im); $k = min(1, 240 / max($w, $h)); $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
        $o = imagecreatetruecolor($nw, $nh); imagefill($o, 0, 0, imagecolorallocate($o, 255, 255, 255)); imagecopyresampled($o, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start(); imagejpeg($o, null, 90); $j = ob_get_clean(); if ($j) return $c = ['data' => $j, 'w' => $nw, 'h' => $nh, 'filter' => '/DCTDecode', 'cs' => '/DeviceRGB', 'parms' => ''];
    }
    $b = @file_get_contents($f); if (!$b || substr($b, 0, 8) !== "\x89PNG\r\n\x1a\n") return $c;
    $p = 8; $idat = ''; $plte = ''; $w = $h = $ct = $bd = $il = 0;
    while ($p + 8 <= strlen($b)) { $len = unpack('N', substr($b, $p, 4))[1]; $type = substr($b, $p + 4, 4); $d = substr($b, $p + 8, $len);
        if ($type === 'IHDR') { $w = unpack('N', substr($d, 0, 4))[1]; $h = unpack('N', substr($d, 4, 4))[1]; $bd = ord($d[8]); $ct = ord($d[9]); $il = ord($d[12]); } elseif ($type === 'PLTE') $plte = $d; elseif ($type === 'IDAT') $idat .= $d; elseif ($type === 'IEND') break; $p += 12 + $len; }
    if ($bd !== 8 || $il !== 0 || !in_array($ct, [2, 3], true) || $idat === '') return $c;
    return $c = ['data' => $idat, 'w' => $w, 'h' => $h, 'filter' => '/FlateDecode', 'cs' => $ct === 2 ? '/DeviceRGB' : '[/Indexed /DeviceRGB ' . (intdiv(strlen($plte), 3) - 1) . ' <' . bin2hex($plte) . '>]', 'parms' => ' /DecodeParms << /Predictor 15 /Colors ' . ($ct === 2 ? 3 : 1) . ' /BitsPerComponent 8 /Columns ' . $w . ' >>'];
}

class AiPdf {
    private array $pages = []; private string $c = ''; private float $y = 0; private int $n = 0; private float $W, $H; private bool $land;
    private const M = 42.0, NAVY = '0 0.2 0.4', GOLD = '1 0.757 0.027', INK = '0.04 0.1 0.18', GRAY = '0.35 0.42 0.5', ZEBRA = '0.95 0.97 0.99', LINE = '0.86 0.89 0.94';
    private ?array $logo;
    public function __construct(private string $title, private string $sub = '', bool $landscape = false, private string $author = '') {
        $this->land = $landscape; $this->W = $landscape ? 842.0 : 595.0; $this->H = $landscape ? 595.0 : 842.0; $this->logo = ai_doc_logo_pdf(); $this->newPage(true);
    }
    private function tw(string $t, float $size, bool $bold = false): float { return ai_doc_w($t, $size, $bold); }
    private function esc(string $t): string { return strtr($t, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '']); }
    private function text(float $x, float $y, string $t, float $size, bool $bold = false, string $rgb = self::INK): void {
        $this->c .= "BT /" . ($bold ? 'F2' : 'F1') . " $size Tf $rgb rg " . round($x, 2) . ' ' . round($this->H - $y, 2) . " Td (" . $this->esc($t) . ") Tj ET\n";
    }
    private function rect(float $x, float $y, float $w, float $h, string $rgb): void { $this->c .= "$rgb rg " . round($x, 2) . ' ' . round($this->H - $y - $h, 2) . ' ' . round($w, 2) . ' ' . round($h, 2) . " re f\n"; }
    private function line(float $x1, float $y, float $x2, string $rgb = self::LINE, float $w = 0.6): void { $this->c .= "$rgb RG $w w " . round($x1, 2) . ' ' . round($this->H - $y, 2) . ' m ' . round($x2, 2) . ' ' . round($this->H - $y, 2) . " l S\n"; }
    private function circlePath(float $cx, float $cy, float $r): string {
        $k = 0.5523 * $r; $y = $this->H - $cy; $f = fn($v) => round($v, 2);
        return $f($cx - $r) . ' ' . $f($y) . ' m ' . $f($cx - $r) . ' ' . $f($y + $k) . ' ' . $f($cx - $k) . ' ' . $f($y + $r) . ' ' . $f($cx) . ' ' . $f($y + $r) . ' c ' . $f($cx + $k) . ' ' . $f($y + $r) . ' ' . $f($cx + $r) . ' ' . $f($y + $k) . ' ' . $f($cx + $r) . ' ' . $f($y) . ' c '
            . $f($cx + $r) . ' ' . $f($y - $k) . ' ' . $f($cx + $k) . ' ' . $f($y - $r) . ' ' . $f($cx) . ' ' . $f($y - $r) . ' c ' . $f($cx - $k) . ' ' . $f($y - $r) . ' ' . $f($cx - $r) . ' ' . $f($y - $k) . ' ' . $f($cx - $r) . ' ' . $f($y) . ' c h ';
    }
    /** The crest in a white disc with a gold ring (a UEAB monogram when there is no logo file). */
    private function crest(float $cx, float $cy, float $r): void {
        $this->c .= self::GOLD . ' rg ' . $this->circlePath($cx, $cy, $r + 2.2) . "f\n" . '1 1 1 rg ' . $this->circlePath($cx, $cy, $r) . "f\n";
        if ($this->logo) { $d = $r * 2 - 5; $this->c .= 'q ' . $this->circlePath($cx, $cy, $r - 1) . 'W n ' . round($d, 2) . ' 0 0 ' . round($d, 2) . ' ' . round($cx - $d / 2, 2) . ' ' . round($this->H - $cy - $d / 2, 2) . " cm /Im1 Do Q\n"; }
        else { $t = 'UEAB'; $s = $r * 0.62; $this->text($cx - $this->tw($t, $s, true) / 2, $cy + $s * 0.35, $t, $s, true, self::NAVY); }
    }
    private function wrap(string $t, float $width, float $size, bool $bold = false): array {
        $out = [];
        foreach (preg_split('/\r?\n/', $t) as $para) {
            $line = '';
            foreach (preg_split('/\s+/', trim($para)) as $word) {
                if ($word === '') continue;
                while ($this->tw($word, $size, $bold) > $width && strlen($word) > 1) { $k = max(1, (int)(strlen($word) * $width / $this->tw($word, $size, $bold)) - 1); if ($line !== '') { $out[] = $line; $line = ''; } $out[] = substr($word, 0, $k); $word = substr($word, $k); }
                $try = $line === '' ? $word : "$line $word";
                if ($this->tw($try, $size, $bold) <= $width) $line = $try; else { $out[] = $line; $line = $word; }
            }
            $out[] = $line;
        }
        return $out ?: [''];
    }
    private function newPage(bool $first = false): void {
        if (!$first) { $this->footer(); $this->pages[] = $this->c; }
        $this->c = ''; $this->n++; $W = $this->W;
        if ($first) {
            $this->rect(0, 0, $W, 96, self::NAVY); $this->rect(0, 96, $W, 4, self::GOLD); $this->crest(self::M + 31, 48, 31);
            $tx = self::M + 78; $size = 21; while ($size > 13 && $this->tw($this->title, $size, true) > $W - $tx - self::M - 150) $size -= 1;
            $this->text($tx, 46, $this->title, $size, true, '1 1 1');
            $this->text($tx, 68, $this->sub !== '' ? $this->sub : 'University of Eastern Africa, Baraton - ComfortBook hostel system', 10.5, false, '1 0.88 0.54');
            $r = 'UEAB Hostel Office'; $this->text($W - self::M - $this->tw($r, 9, true), 34, $r, 9, true, '1 1 1'); $d = date('j F Y'); $this->text($W - self::M - $this->tw($d, 8.5), 48, $d, 8.5, false, '0.78 0.86 0.96');
            $this->y = 118;
            if ($this->author !== '') { $this->text(self::M, $this->y, 'Prepared by ' . $this->author . ' on ' . date('j F Y, H:i'), 8.5, false, self::GRAY); $this->y += 14; }
        } else {
            $this->rect(0, 0, $W, 34, self::NAVY); $this->rect(0, 34, $W, 2, self::GOLD); $this->crest(self::M + 10, 17, 10);
            $this->text(self::M + 28, 21, $this->title, 9.5, true, '1 1 1'); $r = 'ComfortBook'; $this->text($W - self::M - $this->tw($r, 8.5, true), 21, $r, 8.5, true, '1 0.88 0.54'); $this->y = 58;
        }
    }
    private function footer(): void {
        $this->line(self::M, $this->H - 38, $this->W - self::M);
        $this->text(self::M, $this->H - 24, 'University of Eastern Africa, Baraton - ComfortBook hostel system - generated ' . date('j M Y, H:i'), 7.5, false, self::GRAY);
        $this->text($this->W - self::M - $this->tw('Page 00 of 00', 7.5, true), $this->H - 24, 'Page {{P}} of {{N}}', 7.5, true, self::GRAY);
    }
    private function need(float $h): void { if ($this->y + $h > $this->H - 52) $this->newPage(); }
    private function uw(): float { return $this->W - 2 * self::M; }

    public function heading(string $t): void {
        $this->need(52); $this->y += 12; $this->text(self::M, $this->y + 12, $t, 14, true, self::NAVY); $this->y += 20;
        $this->rect(self::M, $this->y, 34, 2.5, self::GOLD); $this->y += 12;
    }
    public function para(string $t): void { foreach ($this->wrap($t, $this->uw(), 10) as $l) { $this->need(15); $this->text(self::M, $this->y + 10, $l, 10); $this->y += 14.5; } $this->y += 5; }
    public function bullets(array $items): void {
        foreach ($items as $it) { $ls = $this->wrap((string)$it, $this->uw() - 16, 10);
            foreach ($ls as $i => $l) { $this->need(15); if ($i === 0) $this->rect(self::M + 3, $this->y + 4.5, 3.5, 3.5, self::GOLD); $this->text(self::M + 16, $this->y + 10, $l, 10); $this->y += 14.5; } }
        $this->y += 5;
    }
    /** Big-number tiles: [[label, value], ...] */
    public function stats(array $items): void {
        $items = array_values($items); if (!$items) return; $per = max(1, min(count($items), $this->land ? 5 : 3)); $gap = 10; $tw = ($this->uw() - $gap * ($per - 1)) / $per; $th = 52;
        foreach (array_chunk($items, $per) as $row) {
            $this->need($th + 8);
            foreach ($row as $i => $it) {
                $x = self::M + $i * ($tw + $gap); $this->rect($x, $this->y, $tw, $th, self::ZEBRA); $this->rect($x, $this->y, 3.5, $th, self::GOLD);
                $lab = strtoupper((string)($it[0] ?? '')); while (strlen($lab) > 3 && $this->tw($lab, 7.2, true) > $tw - 16) $lab = substr($lab, 0, -2);
                $this->text($x + 12, $this->y + 16, $lab, 7.2, true, self::GRAY);
                $val = (string)($it[1] ?? ''); $vs = 19; while ($vs > 10 && $this->tw($val, $vs, true) > $tw - 18) $vs -= 1; $this->text($x + 12, $this->y + 40, $val, $vs, true, self::NAVY);
            }
            $this->y += $th + 8;
        }
        $this->y += 4;
    }
    public function table(array $cols, array $rows): void {
        $n = max(1, count($cols)); $tot = $this->uw(); $size = $n >= 8 ? 7.4 : ($n >= 6 ? 8.2 : 9); $lh = $size + 3.4; $pad = 4.5;
        $num = []; foreach ($cols as $i => $c) { $vals = array_filter(array_map(fn($r) => trim((string)($r[$i] ?? '')), array_slice($rows, 0, 80)), 'strlen'); $k = 0; foreach ($vals as $v) if (ai_doc_is_num($v)) $k++; $num[$i] = $vals && $k / count($vals) >= 0.8; }
        $want = []; foreach ($cols as $i => $c) { $m = $this->tw((string)$c, $size, true) + 14; foreach (array_slice($rows, 0, 60) as $r) $m = max($m, min(230, $this->tw((string)($r[$i] ?? ''), $size) + 14)); $want[$i] = max(38, $m); }
        $sum = array_sum($want); $cw = array_map(fn($w) => $w / $sum * $tot, $want);
        $draw = function (array $cells, bool $head, bool $zebra) use ($cw, $size, $lh, $pad, $num) {
            $lines = []; $mx = 1; foreach ($cells as $i => $c) { $lines[$i] = $this->wrap((string)$c, $cw[$i] - 10, $size, $head); $mx = max($mx, count($lines[$i])); }
            $h = $mx * $lh + 2 * $pad - 1; $this->need($h + 2); $x = self::M;
            if ($head) $this->rect(self::M, $this->y, array_sum($cw), $h, self::NAVY); elseif ($zebra) $this->rect(self::M, $this->y, array_sum($cw), $h, self::ZEBRA);
            foreach ($cells as $i => $c) { foreach ($lines[$i] as $k => $l) {
                $tone = $head ? '' : ai_doc_tone($l); $rgb = $head ? '1 1 1' : ($tone === 'good' ? '0.06 0.5 0.28' : ($tone === 'bad' ? '0.75 0.15 0.15' : self::INK)); $bold = $head || $tone !== '';
                $tx = $num[$i] ? $x + $cw[$i] - 5 - $this->tw($l, $size, $bold) : $x + 5; $this->text($tx, $this->y + $pad + $size - 1 + $k * $lh, $l, $size, $bold, $rgb); }
                $x += $cw[$i]; }
            $this->y += $h; if (!$head) $this->line(self::M, $this->y, self::M + array_sum($cw));
        };
        $headRow = array_map('strval', $cols);
        if ($this->y + 90 > $this->H - 52) $this->newPage();
        $draw($headRow, true, false);
        foreach ($rows as $k => $r) { $r = array_pad(array_map('strval', array_slice((array)$r, 0, $n)), $n, ''); if ($this->y + 34 > $this->H - 52) { $this->newPage(); $draw($headRow, true, false); } $draw($r, false, $k % 2 === 1); }
        $this->y += 5; $cap = count($rows) . ' row' . (count($rows) === 1 ? '' : 's'); $this->need(14); $this->text(self::M + $tot - $this->tw($cap, 7.5), $this->y + 8, $cap, 7.5, false, self::GRAY); $this->y += 16;
    }
    public function output(): string {
        $this->footer(); $this->pages[] = $this->c; $np = count($this->pages); $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $base = 6; $kids = ''; for ($i = 0; $i < $np; $i++) $kids .= ($base + $i * 2) . ' 0 R ';
        $objs[2] = "<< /Type /Pages /Kids [ $kids] /Count $np >>";
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objs[5] = $this->logo ? '<< /Type /XObject /Subtype /Image /Width ' . $this->logo['w'] . ' /Height ' . $this->logo['h'] . ' /ColorSpace ' . $this->logo['cs'] . ' /BitsPerComponent 8 /Filter ' . $this->logo['filter'] . $this->logo['parms'] . ' /Length ' . strlen($this->logo['data']) . " >>\nstream\n" . $this->logo['data'] . "\nendstream" : '<< >>';
        $mb = '[0 0 ' . (int)$this->W . ' ' . (int)$this->H . ']'; $res = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ($this->logo ? ' /XObject << /Im1 5 0 R >>' : '') . ' >>';
        foreach ($this->pages as $i => $c) {
            $c = strtr($c, ['{{P}}' => (string)($i + 1), '{{N}}' => (string)$np]); $p = $base + $i * 2;
            $objs[$p] = "<< /Type /Page /Parent 2 0 R /MediaBox $mb /Resources $res /Contents " . ($p + 1) . ' 0 R >>';
            $objs[$p + 1] = '<< /Length ' . strlen($c) . " >>\nstream\n$c\nendstream";
        }
        ksort($objs); $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = [];
        foreach ($objs as $id => $body) { $off[$id] = strlen($out); $out .= "$id 0 obj\n$body\nendobj\n"; }
        $x = strlen($out); $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($objs as $id => $_) $out .= sprintf("%010d 00000 n \n", $off[$id]);
        return $out . 'trailer << /Size ' . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF";
    }
}

function ai_pdf(string $title, string $sub, array $sections, array $opts = []): string {
    $land = ai_doc_is_landscape($sections, (string)($opts['orientation'] ?? 'auto'));
    $p = new AiPdf(ai_doc_txt($title), ai_doc_txt($sub), $land, ai_doc_txt((string)($opts['author'] ?? '')));
    foreach ($sections as $s) {
        if (!empty($s['heading'])) $p->heading(ai_doc_txt((string)$s['heading']));
        if (!empty($s['text'])) $p->para(ai_doc_txt((string)$s['text']));
        if (!empty($s['stats'])) $p->stats(array_map(fn($x) => [ai_doc_txt((string)($x[0] ?? '')), ai_doc_txt((string)($x[1] ?? ''))], (array)$s['stats']));
        if (!empty($s['bullets'])) $p->bullets(array_map('ai_doc_txt', array_map('strval', (array)$s['bullets'])));
        if (!empty($s['columns']) && !empty($s['rows'])) $p->table(array_map('ai_doc_txt', array_map('strval', (array)$s['columns'])), array_map(fn($r) => array_map('ai_doc_txt', array_map('strval', (array)$r)), (array)$s['rows']));
    }
    return $p->output();
}

/* ====================================================================== Word */
/** Minimal zip (stored, no compression) so .docx works on any PHP without the zip extension. */
function ai_zip(array $files): string {
    $d = ''; $cd = ''; $n = 0; $t = (int)date('G') << 11 | (int)date('i') << 5 | (int)date('s') >> 1; $dt = ((int)date('Y') - 1980) << 9 | (int)date('n') << 5 | (int)date('j');
    foreach ($files as $name => $data) {
        $crc = crc32($data); $len = strlen($data); $o = strlen($d);
        $d .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $t, $dt, $crc, $len, $len, strlen($name), 0) . $name . $data;
        $cd .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $t, $dt, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $o) . $name; $n++;
    }
    return $d . $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($cd), strlen($d), 0);
}

function ai_docx(string $title, string $sub, array $sections, array $opts = []): string {
    $land = ai_doc_is_landscape($sections, (string)($opts['orientation'] ?? 'auto')); $author = (string)($opts['author'] ?? '');
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $PW = $land ? 16838 : 11906; $PH = $land ? 11906 : 16838; $MG = 1000; $UW = $PW - 2 * $MG;
    $logoFile = ai_doc_logo_path(); $png = is_file($logoFile) ? @file_get_contents($logoFile) : false; $dim = $png ? @getimagesizefromstring($png) : false; if (!$dim) $png = false;
    $img = function (int $emu, string $rid, int $id) use ($dim) { $cx = $emu; $cy = (int)round($emu * ($dim[1] / max(1, $dim[0])));
        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="' . $id . '" name="UEAB logo"/><wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="' . $id . '" name="logo.png"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>'; };
    $run = fn($t, $rpr = '') => '<w:r>' . ($rpr ? "<w:rPr>$rpr</w:rPr>" : '') . '<w:t xml:space="preserve">' . $x($t) . '</w:t></w:r>';
    $p = fn($inner, $ppr = '') => '<w:p>' . ($ppr ? "<w:pPr>$ppr</w:pPr>" : '') . $inner . '</w:p>';
    $noB = '<w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>';

    $logoW = 1150; $lw = $png ? $logoW : 1;
    $b = '<w:tbl><w:tblPr><w:tblW w:w="' . $UW . '" w:type="dxa"/><w:tblLayout w:type="fixed"/>' . $noB . '<w:tblCellMar><w:left w:w="200" w:type="dxa"/><w:right w:w="140" w:type="dxa"/><w:top w:w="140" w:type="dxa"/><w:bottom w:w="140" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid><w:gridCol w:w="' . $lw . '"/><w:gridCol w:w="' . ($UW - $lw) . '"/></w:tblGrid><w:tr>'
        . '<w:tc><w:tcPr><w:tcW w:w="' . $lw . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="003366"/><w:vAlign w:val="center"/></w:tcPr>' . $p($png ? $img(640080, 'rIdLogoDoc', 11) : '', '<w:spacing w:before="0" w:after="0"/>') . '</w:tc>'
        . '<w:tc><w:tcPr><w:tcW w:w="' . ($UW - $lw) . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="003366"/><w:vAlign w:val="center"/></w:tcPr>'
        . $p($run($title, '<w:b/><w:color w:val="FFFFFF"/><w:sz w:val="44"/>'), '<w:spacing w:before="0" w:after="40"/>')
        . $p($run($sub !== '' ? $sub : 'University of Eastern Africa, Baraton - ComfortBook hostel system', '<w:color w:val="FFE08A"/><w:sz w:val="22"/>'), '<w:spacing w:before="0" w:after="0"/>') . '</w:tc></w:tr></w:tbl>'
        . $p('', '<w:pBdr><w:top w:val="single" w:sz="24" w:space="0" w:color="FFC107"/></w:pBdr><w:spacing w:before="0" w:after="60"/>');
    $b .= $p($run(($author !== '' ? "Prepared by $author on " : 'Generated on ') . date('j F Y, H:i'), '<w:color w:val="5B6B7E"/><w:sz w:val="17"/>'), '<w:spacing w:before="40" w:after="120"/>');

    foreach ($sections as $s) {
        if (!empty($s['heading'])) $b .= $p($run($s['heading']), '<w:pStyle w:val="Heading1"/>');
        if (!empty($s['text'])) foreach (preg_split('/\r?\n/', (string)$s['text']) as $l) if (trim($l) !== '') $b .= $p($run($l));
        if (!empty($s['stats'])) {
            $items = array_values((array)$s['stats']); $per = max(1, min(count($items), $land ? 5 : 3)); $gap = 120; $cw = (int)floor(($UW - $gap * ($per - 1)) / $per);
            foreach (array_chunk($items, $per) as $row) {
                $cells = ''; $grid = '';
                foreach ($row as $i => $it) {
                    $grid .= '<w:gridCol w:w="' . $cw . '"/>'; if ($i < count($row) - 1) $grid .= '<w:gridCol w:w="' . $gap . '"/>';
                    $cells .= '<w:tc><w:tcPr><w:tcW w:w="' . $cw . '" w:type="dxa"/><w:tcBorders><w:left w:val="single" w:sz="36" w:space="0" w:color="FFC107"/></w:tcBorders><w:shd w:val="clear" w:color="auto" w:fill="F1F5FB"/></w:tcPr>'
                        . $p($run(strtoupper((string)($it[0] ?? '')), '<w:b/><w:color w:val="5B6B7E"/><w:sz w:val="15"/>'), '<w:spacing w:before="60" w:after="0"/>')
                        . $p($run((string)($it[1] ?? ''), '<w:b/><w:color w:val="003366"/><w:sz w:val="38"/>'), '<w:spacing w:before="0" w:after="60"/>') . '</w:tc>';
                    if ($i < count($row) - 1) $cells .= '<w:tc><w:tcPr><w:tcW w:w="' . $gap . '" w:type="dxa"/></w:tcPr>' . $p('', '<w:spacing w:before="0" w:after="0"/>') . '</w:tc>';
                }
                $b .= '<w:tbl><w:tblPr><w:tblW w:w="' . ($per * $cw + ($per - 1) * $gap) . '" w:type="dxa"/><w:tblLayout w:type="fixed"/>' . $noB . '<w:tblCellMar><w:left w:w="140" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>' . $grid . '</w:tblGrid><w:tr><w:trPr><w:cantSplit/></w:trPr>' . $cells . '</w:tr></w:tbl>' . $p('', '<w:spacing w:before="0" w:after="80"/>');
            }
        }
        foreach ((array)($s['bullets'] ?? []) as $it) $b .= $p($run('•  ' . $it), '<w:ind w:left="360" w:hanging="260"/>');
        if (!empty($s['columns']) && !empty($s['rows'])) {
            $cols = array_values((array)$s['columns']); $n = count($cols); $rows = (array)$s['rows']; $fs = $n >= 8 ? 15 : ($n >= 6 ? 16 : 18);
            $num = []; foreach ($cols as $i => $c) { $vals = array_filter(array_map(fn($r) => trim((string)($r[$i] ?? '')), array_slice($rows, 0, 80)), 'strlen'); $k = 0; foreach ($vals as $v) if (ai_doc_is_num($v)) $k++; $num[$i] = $vals && $k / count($vals) >= 0.8; }
            $want = []; foreach ($cols as $i => $c) { $m = ai_doc_w((string)$c, $fs / 2, true) + 14; foreach (array_slice($rows, 0, 60) as $r) $m = max($m, min(230, ai_doc_w((string)($r[$i] ?? ''), $fs / 2) + 14)); $want[$i] = max(38, $m); }
            $sum = array_sum($want); $cw = array_map(fn($w) => (int)floor($w / $sum * $UW), $want); $cw[$n - 1] += $UW - array_sum($cw);
            $cell = function ($t, $head, $zebra, $i) use ($x, $fs, $cw, $num) {
                $tone = $head ? '' : ai_doc_tone((string)$t); $col = $head ? 'FFFFFF' : ($tone === 'good' ? '0F8048' : ($tone === 'bad' ? 'BF2626' : '0B1B2E'));
                $fill = $head ? '003366' : ($zebra ? 'F1F5FB' : 'FFFFFF');
                return '<w:tc><w:tcPr><w:tcW w:w="' . $cw[$i] . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/><w:vAlign w:val="center"/></w:tcPr><w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="240" w:lineRule="auto"/>' . ($num[$i] ? '<w:jc w:val="right"/>' : '') . '</w:pPr><w:r><w:rPr>' . ($head || $tone ? '<w:b/>' : '') . '<w:color w:val="' . $col . '"/><w:sz w:val="' . $fs . '"/></w:rPr><w:t xml:space="preserve">' . $x($t) . '</w:t></w:r></w:p></w:tc>'; };
            $tb = '<w:tbl><w:tblPr><w:tblW w:w="' . $UW . '" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:right w:val="nil"/><w:bottom w:val="single" w:sz="6" w:space="0" w:color="C9D4E3"/><w:insideH w:val="single" w:sz="4" w:space="0" w:color="DCE4F0"/><w:insideV w:val="nil"/></w:tblBorders><w:tblCellMar><w:top w:w="70" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="70" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>' . implode('', array_map(fn($w) => '<w:gridCol w:w="' . $w . '"/>', $cw)) . '</w:tblGrid>'
                . '<w:tr><w:trPr><w:cantSplit/><w:tblHeader/></w:trPr>' . implode('', array_map(fn($c, $i) => $cell($c, true, false, $i), $cols, array_keys($cols))) . '</w:tr>';
            foreach ($rows as $k => $r) { $r = array_values(array_pad(array_slice((array)$r, 0, $n), $n, '')); $tb .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>' . implode('', array_map(fn($c, $i) => $cell($c, false, $k % 2 === 1, $i), $r, array_keys($r))) . '</w:tr>'; }
            $b .= $tb . '</w:tbl>' . $p($run(count($rows) . ' row' . (count($rows) === 1 ? '' : 's'), '<w:color w:val="5B6B7E"/><w:sz w:val="15"/>'), '<w:jc w:val="right"/><w:spacing w:before="40" w:after="160"/>');
        }
    }
    $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
    $hdr = fn($withLogo) => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr ' . $ns . '>' . ($withLogo
        ? $p(($png ? $img(300000, 'rIdLogoHdr', 12) : '') . $run('   University of Eastern Africa, Baraton', '<w:b/><w:color w:val="003366"/><w:sz w:val="19"/>') . '<w:r><w:tab/></w:r>' . $run('ComfortBook hostel system', '<w:color w:val="5B6B7E"/><w:sz w:val="17"/>'),
            '<w:pBdr><w:bottom w:val="single" w:sz="12" w:space="4" w:color="FFC107"/></w:pBdr><w:tabs><w:tab w:val="right" w:pos="' . $UW . '"/></w:tabs><w:spacing w:before="0" w:after="0"/>')
        : $p('', '<w:spacing w:before="0" w:after="0"/>')) . '</w:hdr>';
    $f9 = '<w:b/><w:color w:val="5B6B7E"/><w:sz w:val="15"/>';
    $ftr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' . $ns . '>' . $p($run('ComfortBook - generated ' . date('j M Y, H:i'), '<w:color w:val="5B6B7E"/><w:sz w:val="15"/>') . '<w:r><w:tab/></w:r>'
        . $run('Page ', $f9) . '<w:fldSimple w:instr="PAGE"><w:r><w:rPr>' . $f9 . '</w:rPr><w:t>1</w:t></w:r></w:fldSimple>' . $run(' of ', $f9)
        . '<w:fldSimple w:instr="NUMPAGES"><w:r><w:rPr>' . $f9 . '</w:rPr><w:t>1</w:t></w:r></w:fldSimple>',
        '<w:pBdr><w:top w:val="single" w:sz="4" w:space="4" w:color="DCE4F0"/></w:pBdr><w:tabs><w:tab w:val="right" w:pos="' . $UW . '"/></w:tabs><w:spacing w:before="0" w:after="0"/>') . '</w:ftr>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="21"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="100" w:line="264" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:keepNext/><w:pBdr><w:bottom w:val="single" w:sz="12" w:space="2" w:color="FFC107"/></w:pBdr><w:spacing w:before="320" w:after="140"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:color w:val="003366"/><w:sz w:val="30"/></w:rPr></w:style></w:styles>';
    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'; $files = [];
    $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' . ($png ? '<Default Extension="png" ContentType="image/png"/>' : '')
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/><Override PartName="/word/header2.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
        . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/><Override PartName="/word/footer2.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>';
    $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="word/document.xml"/></Relationships>';
    $files['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $rel . '/styles" Target="styles.xml"/><Relationship Id="rId2" Type="' . $rel . '/header" Target="header1.xml"/><Relationship Id="rId3" Type="' . $rel . '/header" Target="header2.xml"/><Relationship Id="rId4" Type="' . $rel . '/footer" Target="footer1.xml"/><Relationship Id="rId5" Type="' . $rel . '/footer" Target="footer2.xml"/>' . ($png ? '<Relationship Id="rIdLogoDoc" Type="' . $rel . '/image" Target="media/logo.png"/>' : '') . '</Relationships>';
    $files['word/_rels/header1.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . ($png ? '<Relationship Id="rIdLogoHdr" Type="' . $rel . '/image" Target="media/logo.png"/>' : '') . '</Relationships>';
    if ($png) $files['word/media/logo.png'] = $png;
    $files['word/styles.xml'] = $styles; $files['word/header1.xml'] = $hdr(true); $files['word/header2.xml'] = $hdr(false); $files['word/footer1.xml'] = $ftr; $files['word/footer2.xml'] = $ftr;
    $files['word/document.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $b
        . '<w:sectPr><w:headerReference w:type="default" r:id="rId2"/><w:headerReference w:type="first" r:id="rId3"/><w:footerReference w:type="default" r:id="rId4"/><w:footerReference w:type="first" r:id="rId5"/>'
        . '<w:pgSz w:w="' . $PW . '" w:h="' . $PH . '"' . ($land ? ' w:orient="landscape"' : '') . '/><w:pgMar w:top="1100" w:right="' . $MG . '" w:bottom="1000" w:left="' . $MG . '" w:header="500" w:footer="450" w:gutter="0"/><w:titlePg/></w:sectPr></w:body></w:document>';
    return ai_zip($files);
}

function ai_files_ready(PDO $pdo): void {
    static $ok = false; if ($ok) return; $ok = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_files (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED DEFAULT NULL, student_id INT UNSIGNED DEFAULT NULL,
        name VARCHAR(160) NOT NULL, mime VARCHAR(80) NOT NULL, data LONGBLOB NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_u (user_id), KEY idx_s (student_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (random_int(1, 20) === 1) $pdo->exec("DELETE FROM ai_files WHERE created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)");
}
