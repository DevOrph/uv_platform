<?php
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Accès refusé');
}

$mode = trim($_GET['mode'] ?? '');

// ═══════════════════════════════════════════════════════════════════════
// MODE STUDENT : historique complet des paiements d'un étudiant
// ═══════════════════════════════════════════════════════════════════════
if ($mode === 'student') {
    $student_id = trim($_GET['student_id'] ?? '');
    $annee      = trim($_GET['annee'] ?? '');
    if (!preg_match('/^\d{4}-\d{4}$/', $annee)) {
        $annee = ANNEE_ACADEMIQUE_COURANTE;
    }
    if (empty($student_id)) {
        http_response_code(400);
        exit('Paramètre student_id manquant.');
    }

    // Infos étudiant
    $stu_stmt = $conn->prepare("
        SELECT u.id, u.name, c.name AS class_name
        FROM users u
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE u.id = ? AND u.role = 'student'
        LIMIT 1
    ");
    $stu_stmt->bind_param("s", $student_id);
    $stu_stmt->execute();
    $stu = $stu_stmt->get_result()->fetch_assoc();
    $stu_stmt->close();
    if (!$stu) {
        http_response_code(404);
        exit('Étudiant introuvable.');
    }

    // Ordre des échéances pour numérotation
    $dl_order_stmt = $conn->prepare("SELECT id FROM payment_deadlines WHERE student_id = ? ORDER BY due_date ASC, id ASC");
    $dl_order_stmt->bind_param("s", $student_id);
    $dl_order_stmt->execute();
    $dl_order_res = $dl_order_stmt->get_result();
    $dl_order = [];
    while ($r = $dl_order_res->fetch_assoc()) $dl_order[] = (int)$r['id'];
    $dl_order_stmt->close();

    // Paiements + allocations
    $pay_stmt = $conn->prepare("
        SELECT sp.id, sp.receipt_number, sp.payment_date, sp.payment_type,
               sp.payment_method, sp.reference_number, sp.amount_paid,
               sp.status, sp.cancel_reason,
               rec.name AS recorded_by_name
        FROM student_payments sp
        LEFT JOIN users rec ON sp.recorded_by = rec.id
        WHERE sp.student_id = ?
        ORDER BY sp.payment_date DESC, sp.id DESC
    ");
    $pay_stmt->bind_param("s", $student_id);
    $pay_stmt->execute();
    $payments_raw = $pay_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $pay_stmt->close();

    // Charger les allocations de tous les paiements en une seule requête
    $allocations_by_pay = [];
    if (!empty($payments_raw)) {
        $pay_ids = array_column($payments_raw, 'id');
        $placeholders = implode(',', array_fill(0, count($pay_ids), '?'));
        $types = str_repeat('i', count($pay_ids));
        $alloc_stmt = $conn->prepare("
            SELECT pa.payment_id, pa.allocation_type, pa.amount, pa.deadline_id
            FROM payment_allocations pa
            WHERE pa.payment_id IN ($placeholders)
            ORDER BY pa.id ASC
        ");
        $alloc_stmt->bind_param($types, ...$pay_ids);
        $alloc_stmt->execute();
        $alloc_res = $alloc_stmt->get_result();
        while ($ar = $alloc_res->fetch_assoc()) {
            $allocations_by_pay[(int)$ar['payment_id']][] = $ar;
        }
        $alloc_stmt->close();
    }

    $conn->close();

    // ── Labels ──
    $method_labels = [
        'cash'          => 'Espèces',
        'bank_transfer' => 'Virement',
        'mobile_money'  => 'Mobile Money',
        'check'         => 'Chèque',
        'other'         => 'Autre',
    ];
    $type_labels = [
        'registration' => 'Inscription',
        'tuition'      => 'Scolarité',
        'insurance'    => 'Assurance',
        'library'      => 'Bibliothèque',
        'practical'    => 'TP',
        'other'        => 'Autre',
    ];

}

// ═══════════════════════════════════════════════════════════════════════
// MODE CLASSE (comportement original)
// ═══════════════════════════════════════════════════════════════════════
if ($mode !== 'student') {

$class_id = intval($_GET['class_id'] ?? 0);
$annee    = trim($_GET['annee'] ?? '');
if (!preg_match('/^\d{4}-\d{4}$/', $annee)) {
    $annee = ANNEE_ACADEMIQUE_COURANTE;
}
if ($class_id <= 0) {
    http_response_code(400);
    exit('Paramètre class_id manquant ou invalide.');
}

$cls_stmt = $conn->prepare("SELECT name FROM classes WHERE id = ? LIMIT 1");
$cls_stmt->bind_param("i", $class_id);
$cls_stmt->execute();
$cls_row = $cls_stmt->get_result()->fetch_assoc();
$cls_stmt->close();
if (!$cls_row) {
    http_response_code(404);
    exit('Classe introuvable.');
}
$class_name = $cls_row['name'];

$sql = "
    SELECT
        u.id                                                                 AS matricule,
        u.name                                                               AS nom_complet,
        u.email,
        COALESCE(u.phone, '')                                               AS telephone,
        COALESCE(tf.total_amount, 0)                                        AS total_frais,
        COALESCE(sd.discount_amount, 0)                                     AS reduction,
        (COALESCE(tf.total_amount, 0) - COALESCE(sd.discount_amount, 0))   AS net_du,
        COALESCE(payments.total_paid, 0)                                    AS montant_paye,
        ((COALESCE(tf.total_amount, 0) - COALESCE(sd.discount_amount, 0))
            - COALESCE(payments.total_paid, 0))                             AS solde_restant,
        CASE
            WHEN (COALESCE(tf.total_amount, 0) - COALESCE(sd.discount_amount, 0)) > 0
            THEN ROUND((COALESCE(payments.total_paid, 0)
                 / (COALESCE(tf.total_amount, 0) - COALESCE(sd.discount_amount, 0))) * 100, 1)
            ELSE 0
        END AS pct_progression,
        CASE
            WHEN COALESCE(tf.total_amount, 0) = 0 THEN 'no_fees'
            WHEN (SELECT COUNT(*) FROM payment_deadlines pd2
                  WHERE pd2.student_id = u.id AND pd2.tuition_fee_id = tf.id) > 0
                 AND ((COALESCE(tf.total_amount, 0) - COALESCE(sd.discount_amount, 0))
                      - COALESCE(payments.total_paid, 0)) <= 0
                 THEN 'paid'
            WHEN (SELECT COUNT(*) FROM payment_deadlines pd2
                  WHERE pd2.student_id = u.id AND pd2.tuition_fee_id = tf.id) > 0
                 AND (SELECT COUNT(*) FROM payment_deadlines pd3
                      WHERE pd3.student_id = u.id AND pd3.tuition_fee_id = tf.id
                        AND (pd3.status = 'overdue'
                             OR (pd3.status = 'partial' AND pd3.due_date < CURDATE()))) > 0
                 THEN 'overdue'
            WHEN (SELECT COUNT(*) FROM payment_deadlines pd2
                  WHERE pd2.student_id = u.id AND pd2.tuition_fee_id = tf.id) > 0
                 AND COALESCE(payments.total_paid, 0) > 0
                 THEN 'partial'
            WHEN (SELECT COUNT(*) FROM payment_deadlines pd2
                  WHERE pd2.student_id = u.id AND pd2.tuition_fee_id = tf.id) > 0
                 THEN 'unpaid'
            WHEN ((COALESCE(tf.total_amount, 0) - COALESCE(sd.discount_amount, 0))
                   - COALESCE(payments.total_paid, 0)) <= 0
                 THEN 'paid'
            WHEN tf.due_date < CURDATE()
                 AND ((COALESCE(tf.total_amount, 0) - COALESCE(sd.discount_amount, 0))
                      - COALESCE(payments.total_paid, 0)) > 0
                 THEN 'overdue'
            WHEN COALESCE(payments.total_paid, 0) > 0 THEN 'partial'
            ELSE 'unpaid'
        END AS payment_status,
        (SELECT COUNT(*) FROM payment_deadlines pd
         WHERE pd.student_id = u.id AND pd.tuition_fee_id = tf.id) AS nb_echeances,
        (SELECT pd.due_date FROM payment_deadlines pd
         WHERE pd.student_id = u.id AND pd.tuition_fee_id = tf.id
           AND pd.status IN ('pending', 'partial', 'overdue')
         ORDER BY pd.due_date ASC LIMIT 1)                                  AS next_deadline_date,
        (SELECT pd.amount_due FROM payment_deadlines pd
         WHERE pd.student_id = u.id AND pd.tuition_fee_id = tf.id
           AND pd.status IN ('pending', 'partial', 'overdue')
         ORDER BY pd.due_date ASC LIMIT 1)                                  AS next_deadline_amount,
        payments.last_payment_date,
        (SELECT sp.amount_paid FROM student_payments sp
         WHERE sp.student_id = u.id AND sp.status = 'validated'
         ORDER BY sp.payment_date DESC, sp.id DESC LIMIT 1)                 AS last_payment_amount
    FROM users u
    LEFT JOIN classes c ON u.class_id = c.id
    LEFT JOIN (
        SELECT class_id, MAX(id) AS id
        FROM tuition_fees
        WHERE academic_year = ?
        GROUP BY class_id
    ) tf_dedup ON tf_dedup.class_id = c.id
    LEFT JOIN tuition_fees tf ON tf.id = tf_dedup.id
    LEFT JOIN student_discounts sd
        ON u.id COLLATE utf8mb4_general_ci = sd.student_id COLLATE utf8mb4_general_ci
        AND tf.id = sd.tuition_fee_id
        AND sd.academic_year = ?
    LEFT JOIN (
        SELECT student_id, tuition_fee_id,
               SUM(amount_paid) AS total_paid,
               MAX(payment_date) AS last_payment_date
        FROM student_payments
        WHERE status = 'validated'
        GROUP BY student_id, tuition_fee_id
    ) payments ON u.id = payments.student_id AND tf.id = payments.tuition_fee_id
    WHERE u.role = 'student' AND u.blocked = 0 AND u.status = 'active'
      AND c.id = ?
    ORDER BY u.name
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssi", $annee, $annee, $class_id);
$stmt->execute();
$result = $stmt->get_result();
$students = [];
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}
$stmt->close();
$conn->close();

if (empty($students)) {
    http_response_code(404);
    exit('Aucun étudiant trouvé pour cette classe.');
}

} // end if ($mode !== 'student')

// ═══════════════════════════════════════════════════════════════════════
// XlsxWriter — générateur .xlsx pur PHP (OOXML / ZipArchive)
// ═══════════════════════════════════════════════════════════════════════

function xlsxEscape($s) {
    return htmlspecialchars((string)$s, ENT_XML1, 'UTF-8');
}

function xlsxDate($dateStr) {
    if (empty($dateStr)) return '';
    $ts = strtotime($dateStr);
    if (!$ts) return $dateStr;
    return ($ts / 86400) + 25569;
}

class XlsxWriter {
    private $sheets = [];
    private $sharedStrings = [];
    private $sharedStringsMap = [];

    const S_DEFAULT    = 0;
    const S_HEADER     = 1;
    const S_HEADER2    = 2;
    const S_TOTAL      = 3;
    const S_MONEY      = 4;
    const S_MONEY_GRN  = 5;
    const S_MONEY_RED  = 6;
    const S_MONEY_GLD  = 7;
    const S_DATE       = 8;
    const S_PCT        = 9;
    const S_SECTION    = 10;
    const S_TOTAL_GRN  = 11;
    const S_TOTAL_RED  = 12;
    const S_BOLD       = 13;
    const S_MUTED      = 14;
    const S_WARN       = 15;
    const S_HEADER3    = 16;
    // Status text styles (centered, colored bg)
    const S_STATUS_GRN = 17;
    const S_STATUS_GLD = 18;
    const S_STATUS_RED = 19;
    const S_STATUS_GRY = 20;

    private $currentSheet = null;

    public function addSheet($name) {
        $this->currentSheet = ['name' => $name, 'rows' => [], 'cols' => []];
        $this->sheets[] = &$this->currentSheet;
        return count($this->sheets) - 1;
    }

    public function setColWidth($sheetIdx, $col, $width) {
        $this->sheets[$sheetIdx]['cols'][$col] = $width;
    }

    public function writeRow($sheetIdx, $rowData, $height = 18) {
        $this->sheets[$sheetIdx]['rows'][] = ['cells' => $rowData, 'height' => $height];
    }

    public function writeBlank($sheetIdx, $n = 1) {
        for ($i = 0; $i < $n; $i++) {
            $this->sheets[$sheetIdx]['rows'][] = ['cells' => [], 'height' => 8];
        }
    }

    private function addSharedString($s) {
        $s = (string)$s;
        if (!isset($this->sharedStringsMap[$s])) {
            $this->sharedStringsMap[$s] = count($this->sharedStrings);
            $this->sharedStrings[] = $s;
        }
        return $this->sharedStringsMap[$s];
    }

    private function cellXml($cell) {
        $type  = $cell['t'] ?? 's';
        $style = $cell['s'] ?? 0;
        $val   = $cell['v'] ?? '';
        $col   = $cell['col'];
        $row   = $cell['row'];
        $ref   = $this->colLetter($col) . $row;

        if ($type === 's') {
            $si = $this->addSharedString((string)$val);
            return "<c r=\"$ref\" s=\"$style\" t=\"s\"><v>$si</v></c>";
        } elseif ($type === 'n') {
            $v = is_numeric($val) ? $val : 0;
            return "<c r=\"$ref\" s=\"$style\"><v>$v</v></c>";
        } elseif ($type === 'd') {
            $v = is_numeric($val) ? $val : xlsxDate($val);
            return "<c r=\"$ref\" s=\"$style\"><v>$v</v></c>";
        }
        return '';
    }

    private function colLetter($n) {
        $letters = '';
        while ($n > 0) {
            $n--;
            $letters = chr(65 + ($n % 26)) . $letters;
            $n = intdiv($n, 26);
        }
        return $letters;
    }

    private function buildSheet($sheet, $sheetId) {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if (!empty($sheet['cols'])) {
            $xml .= '<cols>';
            foreach ($sheet['cols'] as $colIdx => $width) {
                $xml .= "<col min=\"$colIdx\" max=\"$colIdx\" width=\"$width\" customWidth=\"1\"/>";
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach ($sheet['rows'] as $rowIdx => $row) {
            $rowNum = $rowIdx + 1;
            $h = $row['height'];
            $xml .= "<row r=\"$rowNum\" ht=\"$h\" customHeight=\"1\">";
            foreach ($row['cells'] as $colIdx => $cell) {
                $cell['col'] = $colIdx + 1;
                $cell['row'] = $rowNum;
                $xml .= $this->cellXml($cell);
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        $xml .= '</worksheet>';
        return $xml;
    }

    private function buildStyles() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="1">
    <numFmt numFmtId="164" formatCode="#,##0\ &quot;FCFA&quot;"/>
  </numFmts>
  <fonts count="10">
    <font><sz val="9"/><name val="Arial"/><color rgb="FF1A1A2E"/></font>
    <font><sz val="9"/><b/><name val="Arial"/><color rgb="FFFFFFFF"/></font>
    <font><sz val="9"/><b/><name val="Arial"/><color rgb="FF1A1A2E"/></font>
    <font><sz val="9"/><b/><name val="Arial"/><color rgb="FF039BE5"/></font>
    <font><sz val="9"/><b/><name val="Arial"/><color rgb="FF27AE60"/></font>
    <font><sz val="9"/><b/><name val="Arial"/><color rgb="FFE74C3C"/></font>
    <font><sz val="9"/><b/><name val="Arial"/><color rgb="FFD4A843"/></font>
    <font><sz val="9"/><i/><name val="Arial"/><color rgb="FF7F8C8D"/></font>
    <font><sz val="14"/><b/><name val="Arial"/><color rgb="FF051E34"/></font>
    <font><sz val="9"/><b/><name val="Arial"/><color rgb="FFC0392B"/></font>
  </fonts>
  <fills count="12">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF051E34"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF0C2D48"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFD6EAF8"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFEBF5FB"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFD5F5E3"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFADBD8"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFEF9E7"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1E8449"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF922B21"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF4A235A"/></patternFill></fill>
  </fills>
  <borders count="3">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFBDC3C7"/></left>
      <right style="thin"><color rgb="FFBDC3C7"/></right>
      <top style="thin"><color rgb="FFBDC3C7"/></top>
      <bottom style="thin"><color rgb="FFBDC3C7"/></bottom>
      <diagonal/>
    </border>
    <border>
      <left style="medium"><color rgb="FF039BE5"/></left>
      <right style="medium"><color rgb="FF039BE5"/></right>
      <top style="medium"><color rgb="FF039BE5"/></top>
      <bottom style="medium"><color rgb="FF039BE5"/></bottom>
      <diagonal/>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="21">
    <xf numFmtId="0"   fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="0"   fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0"   fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0"   fontId="2" fillId="4" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <xf numFmtId="164" fontId="4" fillId="6" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <xf numFmtId="164" fontId="5" fillId="7" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <xf numFmtId="164" fontId="6" fillId="8" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <xf numFmtId="14"  fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="10"  fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0"   fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="164" fontId="4" fillId="6" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <xf numFmtId="164" fontId="5" fillId="7" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <xf numFmtId="0"   fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="0"   fontId="7" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="0"   fontId="5" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="0"   fontId="1" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0"   fontId="4" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0"   fontId="6" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0"   fontId="5" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0"   fontId="7" fillId="0" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
  </cellXfs>
  <cellStyles count="1">
    <cellStyle name="Normal" xfId="0" builtinId="0"/>
  </cellStyles>
</styleSheet>';
    }

    private function buildSharedStrings() {
        $count = count($this->sharedStrings);
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= "<sst xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" count=\"$count\" uniqueCount=\"$count\">";
        foreach ($this->sharedStrings as $s) {
            $xml .= '<si><t xml:space="preserve">' . xlsxEscape($s) . '</t></si>';
        }
        $xml .= '</sst>';
        return $xml;
    }

    public function save($filename) {
        $zip = new ZipArchive();
        $zip->open($filename, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>');

        $ct  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $ct .= "<Override PartName=\"/xl/worksheets/sheet$n.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }
        $ct .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $ct);

        $wbRels  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rIdSty" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '<Relationship Id="rIdSS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $wbRels .= "<Relationship Id=\"rId$n\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$n.xml\"/>";
        }
        $wbRels .= '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        $wb  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<fileVersion appName="xl" lastEdited="5" lowestEdited="5" rupBuild="9302"/>' .
            '<workbookPr defaultThemeVersion="124226"/>' .
            '<bookViews><workbookView xWindow="480" yWindow="60" windowWidth="18195" windowHeight="8505"/></bookViews>' .
            '<sheets>';
        foreach ($this->sheets as $i => $sheet) {
            $n    = $i + 1;
            $name = xlsxEscape($sheet['name']);
            $wb  .= "<sheet name=\"$name\" sheetId=\"$n\" r:id=\"rId$n\"/>";
        }
        $wb .= '</sheets><calcPr calcId="144525"/></workbook>';
        $zip->addFromString('xl/workbook.xml', $wb);

        $zip->addFromString('xl/styles.xml', $this->buildStyles());

        $sheetXmls = [];
        foreach ($this->sheets as $i => $sheet) {
            $sheetXmls[$i] = $this->buildSheet($sheet, $i + 1);
        }
        $zip->addFromString('xl/sharedStrings.xml', $this->buildSharedStrings());
        foreach ($sheetXmls as $i => $sheetXml) {
            $n = $i + 1;
            $zip->addFromString("xl/worksheets/sheet$n.xml", $sheetXml);
        }
        $zip->close();
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════════════════
function xs($v, $style = 0)         { return ['v' => (string)$v, 't' => 's', 's' => $style]; }
function xn($v, $style = 4)         { return ['v' => floatval($v), 't' => 'n', 's' => $style]; }
function xd($v, $style = 8)         { return ['v' => xlsxDate($v), 't' => 'd', 's' => $style]; }
function xpct($v, $style = 9)       { return ['v' => floatval($v) / 100, 't' => 'n', 's' => $style]; }
function xblank($n, $style = 0)     { $r = []; for ($i = 0; $i < $n; $i++) $r[] = xs('', $style); return $r; }

$H  = XlsxWriter::S_HEADER;
$H2 = XlsxWriter::S_HEADER2;
$T  = XlsxWriter::S_TOTAL;
$M  = XlsxWriter::S_MONEY;
$MG = XlsxWriter::S_MONEY_GRN;
$MR = XlsxWriter::S_MONEY_RED;
$ML = XlsxWriter::S_MONEY_GLD;
$D  = XlsxWriter::S_DATE;
$P  = XlsxWriter::S_PCT;
$B  = XlsxWriter::S_BOLD;
$MU = XlsxWriter::S_MUTED;
$TG = XlsxWriter::S_TOTAL_GRN;
$TR = XlsxWriter::S_TOTAL_RED;
$DEF= XlsxWriter::S_DEFAULT;
$SG = XlsxWriter::S_STATUS_GRN;
$SO = XlsxWriter::S_STATUS_GLD;
$SR = XlsxWriter::S_STATUS_RED;
$SM = XlsxWriter::S_STATUS_GRY;

$status_labels = [
    'paid'    => 'Soldé',
    'partial' => 'Partiel',
    'overdue' => 'En retard',
    'unpaid'  => 'Impayé',
    'no_fees' => 'Sans frais',
];
$status_styles = [
    'paid'    => $SG,
    'partial' => $SO,
    'overdue' => $SR,
    'unpaid'  => $SM,
    'no_fees' => $DEF,
];

// ═══════════════════════════════════════════════════════════════════════
// Construction du fichier Excel
// ═══════════════════════════════════════════════════════════════════════

// ────────────────────────────────────────────────────────────────────
// MODE STUDENT
// ────────────────────────────────────────────────────────────────────
if ($mode === 'student') {

    $xw  = new XlsxWriter();
    $idx = $xw->addSheet('Historique paiements');

    // Largeurs colonnes (10 colonnes)
    $xw->setColWidth($idx,  1, 16); // N° Reçu
    $xw->setColWidth($idx,  2, 14); // Date paiement
    $xw->setColWidth($idx,  3, 14); // Type
    $xw->setColWidth($idx,  4, 16); // Méthode
    $xw->setColWidth($idx,  5, 20); // Référence
    $xw->setColWidth($idx,  6, 14); // Montant payé
    $xw->setColWidth($idx,  7, 10); // Statut
    $xw->setColWidth($idx,  8, 22); // Motif annulation
    $xw->setColWidth($idx,  9, 18); // Enregistré par
    $xw->setColWidth($idx, 10, 40); // Ventilation

    // En-tête document
    $date_export = date('d/m/Y à H:i');
    $xw->writeRow($idx, [xs(defined('INSTITUTION_ID') ? INSTITUTION_ID : '', $B)], 18);
    $xw->writeRow($idx, [xs("Historique des paiements — {$stu['name']} ({$stu['id']}) — Classe : {$stu['class_name']} — Année : $annee", $B)], 22);
    $xw->writeRow($idx, [xs("Exporté le $date_export", $MU)], 14);
    $xw->writeBlank($idx);

    // En-tête colonnes
    $stu_headers = ['N° Reçu', 'Date paiement', 'Type', 'Méthode', 'Référence transaction',
                    'Montant payé', 'Statut', 'Motif annulation', 'Enregistré par', 'Détail ventilation'];
    $xw->writeRow($idx, array_map(fn($h) => xs($h, $H2), $stu_headers), 26);

    $total_valide = 0.0;

    foreach ($payments_raw as $p) {
        $isCancelled = ($p['status'] === 'cancelled');
        $base_style  = $isCancelled ? $MU : $DEF;
        $amt_style   = $isCancelled ? $MR : $MG;

        // Ventilation
        $allocs = $allocations_by_pay[(int)$p['id']] ?? [];
        $alloc_parts = [];
        foreach ($allocs as $al) {
            if ($al['deadline_id']) {
                $dl_num = array_search((int)$al['deadline_id'], $dl_order);
                $label  = 'Éch.' . ($dl_num !== false ? ($dl_num + 1) : '?');
            } else {
                $label = $type_labels[$al['allocation_type']] ?? $al['allocation_type'];
            }
            $alloc_parts[] = $label . ' → ' . number_format((float)$al['amount'], 0, ',', ' ') . ' FCFA';
        }
        $alloc_str = implode(' | ', $alloc_parts);

        if (!$isCancelled) {
            $total_valide += floatval($p['amount_paid']);
        }

        $xw->writeRow($idx, [
            xs($p['receipt_number'] ?: '—',        $base_style),
            xs(substr($p['payment_date'], 0, 10),   $base_style),
            xs($type_labels[$p['payment_type']]   ?? $p['payment_type'],   $base_style),
            xs($method_labels[$p['payment_method']] ?? $p['payment_method'], $base_style),
            xs($p['reference_number'] ?: '—',       $base_style),
            xn($p['amount_paid'],                    $amt_style),
            xs($isCancelled ? 'Annulé' : 'Validé',  $isCancelled ? $SR : $SG),
            xs($isCancelled ? ($p['cancel_reason'] ?: '—') : '',  $isCancelled ? $MR : $DEF),
            xs($p['recorded_by_name'] ?: 'Admin',   $base_style),
            xs($alloc_str ?: '—',                   $base_style),
        ], 20);
    }

    // Ligne total
    $xw->writeBlank($idx);
    $xw->writeRow($idx, [
        xs('TOTAL VALIDÉ', $T),
        xs('', $T), xs('', $T), xs('', $T), xs('', $T),
        xn($total_valide, $TG),
        xs('', $T), xs('', $T), xs('', $T), xs('', $T),
    ], 22);

    // Output
    $safe_id    = preg_replace('/[^A-Za-z0-9\-]/', '_', $stu['id']);
    $safe_annee = str_replace('/', '-', $annee);
    $filename   = "Historique_{$safe_id}_{$safe_annee}_" . date('Ymd') . '.xlsx';

    $tmpFile = sys_get_temp_dir() . '/uv_hist_' . session_id() . '_' . time() . '.xlsx';
    $xw->save($tmpFile);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: max-age=0');
    readfile($tmpFile);
    @unlink($tmpFile);
    exit();
}

// ────────────────────────────────────────────────────────────────────
// MODE CLASSE (original)
// ────────────────────────────────────────────────────────────────────
$xw = new XlsxWriter();
$idx = $xw->addSheet('Paiements');

// Column widths (16 columns)
$xw->setColWidth($idx,  1, 16); // Matricule
$xw->setColWidth($idx,  2, 28); // Nom complet
$xw->setColWidth($idx,  3, 28); // Email
$xw->setColWidth($idx,  4, 14); // Téléphone
$xw->setColWidth($idx,  5, 16); // Total frais
$xw->setColWidth($idx,  6, 14); // Réduction
$xw->setColWidth($idx,  7, 14); // Net dû
$xw->setColWidth($idx,  8, 14); // Montant payé
$xw->setColWidth($idx,  9, 14); // Solde restant
$xw->setColWidth($idx, 10, 12); // % progression
$xw->setColWidth($idx, 11, 12); // Statut
$xw->setColWidth($idx, 12, 10); // Nb échéances
$xw->setColWidth($idx, 13, 14); // Prochaine éch. date
$xw->setColWidth($idx, 14, 16); // Prochaine éch. montant
$xw->setColWidth($idx, 15, 14); // Dernier pmt date
$xw->setColWidth($idx, 16, 16); // Dernier pmt montant

// Title rows
$date_export = date('d/m/Y à H:i');
$xw->writeRow($idx, [xs(INSTITUTION_ID, $B)], 18);
$xw->writeRow($idx, [xs("Paiements — $class_name — Année $annee", $B)], 22);
$xw->writeRow($idx, [xs("Exporté le $date_export", $MU)], 14);
$xw->writeBlank($idx);

// Header row
$headers = [
    'Matricule', 'Nom complet', 'Email', 'Téléphone',
    'Total frais', 'Réduction', 'Net dû',
    'Montant payé', 'Solde restant', '% Progression',
    'Statut', 'Nb échéances',
    'Proch. échéance (date)', 'Proch. échéance (montant)',
    'Dernier paiement (date)', 'Dernier paiement (montant)',
];
$header_cells = array_map(fn($h) => xs($h, $H), $headers);
$xw->writeRow($idx, $header_cells, 26);

// Totals accumulators
$t_frais = $t_reduc = $t_net = $t_paye = $t_solde = 0.0;
$nb_count = ['paid' => 0, 'partial' => 0, 'overdue' => 0, 'unpaid' => 0, 'no_fees' => 0];

foreach ($students as $r) {
    $status     = $r['payment_status'] ?? 'unpaid';
    $st_label   = $status_labels[$status]  ?? $status;
    $st_style   = $status_styles[$status]  ?? $DEF;

    $t_frais += floatval($r['total_frais']);
    $t_reduc += floatval($r['reduction']);
    $t_net   += floatval($r['net_du']);
    $t_paye  += floatval($r['montant_paye']);
    $t_solde += floatval($r['solde_restant']);
    $nb_count[$status] = ($nb_count[$status] ?? 0) + 1;

    $nd_date   = $r['next_deadline_date']   ?? '';
    $nd_amount = floatval($r['next_deadline_amount'] ?? 0);
    $lp_date   = $r['last_payment_date']    ?? '';
    $lp_amount = floatval($r['last_payment_amount']  ?? 0);

    $row_cells = [
        xs($r['matricule'],   $DEF),
        xs($r['nom_complet'], $DEF),
        xs($r['email'],       $DEF),
        xs($r['telephone'],   $DEF),
        xn($r['total_frais'],    $M),
        xn($r['reduction'],      $r['reduction'] > 0 ? $ML : $M),
        xn($r['net_du'],         $M),
        xn($r['montant_paye'],   $r['montant_paye'] > 0 ? $MG : $M),
        xn($r['solde_restant'],  $r['solde_restant'] > 0 ? $MR : ($r['solde_restant'] == 0 ? $MG : $M)),
        xpct($r['pct_progression'], $P),
        xs($st_label, $st_style),
        xn($r['nb_echeances'], $DEF),
        $nd_date   ? xd($nd_date,   $D) : xs('—', $MU),
        $nd_amount ? xn($nd_amount, $M) : xs('—', $MU),
        $lp_date   ? xd($lp_date,   $D) : xs('—', $MU),
        $lp_amount ? xn($lp_amount, $M) : xs('—', $MU),
    ];
    $xw->writeRow($idx, $row_cells, 20);
}

// Summary row
$xw->writeBlank($idx);

// Status summary
$summary_parts = [];
if ($nb_count['paid'])    $summary_parts[] = $nb_count['paid']    . ' Soldé(s)';
if ($nb_count['partial']) $summary_parts[] = $nb_count['partial'] . ' Partiel(s)';
if ($nb_count['overdue']) $summary_parts[] = $nb_count['overdue'] . ' En retard';
if ($nb_count['unpaid'])  $summary_parts[] = $nb_count['unpaid']  . ' Impayé(s)';
if ($nb_count['no_fees']) $summary_parts[] = $nb_count['no_fees'] . ' Sans frais';
$summary_str = count($students) . ' étudiants : ' . implode(', ', $summary_parts);

$total_row = [
    xs('TOTAUX', $T),
    xs($summary_str, $T),
    xs('', $T),
    xs('', $T),
    xn($t_frais, $TG),
    xn($t_reduc, $t_reduc > 0 ? $ML : $T),
    xn($t_net,   $TG),
    xn($t_paye,  $TG),
    xn($t_solde, $t_solde > 0 ? $TR : $TG),
    xs('', $T),
    xs('', $T),
    xs('', $T),
    xs('', $T),
    xs('', $T),
    xs('', $T),
    xs('', $T),
];
$xw->writeRow($idx, $total_row, 22);

// ═══════════════════════════════════════════════════════════════════════
// Output
// ═══════════════════════════════════════════════════════════════════════
$safe_class = preg_replace('/[^A-Za-z0-9\-]/', '_', $class_name);
$safe_annee = str_replace('/', '-', $annee);
$filename   = "Paiements_{$safe_class}_{$safe_annee}_" . date('Ymd') . '.xlsx';

$tmpFile = sys_get_temp_dir() . '/uv_pmt_' . session_id() . '_' . time() . '.xlsx';
$xw->save($tmpFile);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: max-age=0');
readfile($tmpFile);
@unlink($tmpFile);
exit();
