<?php
session_start();
require_once '../includes/db_connect.php';

$conn->set_charset('utf8mb4');
$conn->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
$conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

$institution  = INSTITUTION_ID;
$annee        = intval($_GET['annee'] ?? date('Y'));
$type_rapport = $_GET['type'] ?? 'complet'; // complet | journal | depenses | encaissements
$date_export  = date('d/m/Y à H:i');

// ── Exercice ──────────────────────────────────────────────────────────
$ex = $conn->query("SELECT * FROM exercices_comptables WHERE institution_id='$institution' AND annee=$annee LIMIT 1");
$exercice    = $ex ? $ex->fetch_assoc() : null;
$exercice_id = $exercice['id'] ?? 0;

// ── Journal ───────────────────────────────────────────────────────────
$ecritures = [];
if ($exercice_id) {
    $res = $conn->query("
        SELECT ec.date_ecriture, ec.numero_piece, ec.libelle,
               jc.code as journal_code,
               ec.compte_debit, cc_d.libelle as lib_debit,
               ec.compte_credit, cc_c.libelle as lib_credit,
               ec.montant, ec.source_type
        FROM ecritures_comptables ec
        JOIN journaux_comptables jc ON ec.journal_id = jc.id
        LEFT JOIN comptes_comptables cc_d ON ec.compte_debit  = cc_d.code
        LEFT JOIN comptes_comptables cc_c ON ec.compte_credit = cc_c.code
        WHERE ec.exercice_id = $exercice_id
        ORDER BY ec.date_ecriture ASC, ec.id ASC
    ");
    if ($res) while ($r = $res->fetch_assoc()) $ecritures[] = $r;
}

// ── Balance ───────────────────────────────────────────────────────────
$balance = [];
if ($exercice_id) {
    $res = $conn->query("SELECT * FROM vue_balance WHERE annee=$annee ORDER BY code");
    if ($res) while ($r = $res->fetch_assoc()) $balance[] = $r;
}

// ── Paiements étudiants ───────────────────────────────────────────────
$pmt_etudiants = [];
$res = $conn->query("
    SELECT sp.payment_date, sp.receipt_number, sp.student_id,
           u.name as student_name,
           sp.payment_type, sp.payment_method, sp.amount_paid, sp.status
    FROM student_payments sp
    LEFT JOIN users u ON sp.student_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
    WHERE sp.status='validated' AND YEAR(sp.payment_date)=$annee
    ORDER BY sp.payment_date ASC
");
if ($res) while ($r = $res->fetch_assoc()) $pmt_etudiants[] = $r;

// ── Paiements personnel ───────────────────────────────────────────────
$pmt_staff = [];
$res = $conn->query("
    SELECT sp.payment_date, sp.receipt_number,
           u.name as staff_name, u.role,
           spt.name as type_name, spt.category,
           sp.payment_method, sp.amount,
           COALESCE(rs.montant_retenue,0) as retenue,
           COALESCE(rs.montant_net, sp.amount) as net,
           sp.status
    FROM staff_payments sp
    LEFT JOIN users u ON sp.staff_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
    LEFT JOIN staff_payment_types spt ON sp.payment_type_id = spt.id
    LEFT JOIN retenues_source rs ON rs.staff_payment_id = sp.id
    WHERE sp.status='processed' AND YEAR(sp.payment_date)=$annee
    ORDER BY sp.payment_date ASC
");
if ($res) while ($r = $res->fetch_assoc()) $pmt_staff[] = $r;

// ── Retenues DGI ──────────────────────────────────────────────────────
$retenues = [];
$total_retenues = 0;
$res = $conn->query("
    SELECT rs.periode, rs.staff_name, rs.montant_brut,
           rs.taux_retenue, rs.montant_retenue, rs.montant_net, rs.statut
    FROM retenues_source rs
    WHERE YEAR(rs.created_at)=$annee
    ORDER BY rs.periode ASC, rs.staff_name ASC
");
if ($res) while ($r = $res->fetch_assoc()) {
    $retenues[] = $r;
    if ($r['statut'] === 'a_reverser') $total_retenues += $r['montant_retenue'];
}

// ── Dépenses ──────────────────────────────────────────────────────────
$depenses = [];
$res = $conn->query("
    SELECT oe.expense_date, oe.expense_type, oe.category,
           oe.vendor_name, oe.invoice_number, oe.payment_method,
           oe.amount, oe.status
    FROM operational_expenses oe
    WHERE YEAR(oe.expense_date)=$annee
    ORDER BY oe.expense_date ASC
");
if ($res) while ($r = $res->fetch_assoc()) $depenses[] = $r;

// ── États financiers ──────────────────────────────────────────────────
$total_produits = $total_charges = 0;
$detail_produits = $detail_charges = [];
$bilan_actif = $bilan_passif = [];
$actif_total = $passif_total = 0;

if ($exercice_id) {
    $res = $conn->query("SELECT cc.code, cc.libelle, COALESCE(SUM(ec.montant),0) AS montant FROM ecritures_comptables ec JOIN comptes_comptables cc ON ec.compte_credit=cc.code WHERE ec.exercice_id=$exercice_id AND cc.type='produit' GROUP BY cc.code,cc.libelle HAVING montant>0 ORDER BY cc.code");
    if ($res) while ($r = $res->fetch_assoc()) { $detail_produits[] = $r; $total_produits += $r['montant']; }

    $res = $conn->query("SELECT cc.code, cc.libelle, COALESCE(SUM(ec.montant),0) AS montant FROM ecritures_comptables ec JOIN comptes_comptables cc ON ec.compte_debit=cc.code WHERE ec.exercice_id=$exercice_id AND cc.type='charge' GROUP BY cc.code,cc.libelle HAVING montant>0 ORDER BY cc.code");
    if ($res) while ($r = $res->fetch_assoc()) { $detail_charges[] = $r; $total_charges += $r['montant']; }

    $res = $conn->query("SELECT cc.code, cc.libelle, COALESCE(SUM(CASE WHEN ec.compte_debit=cc.code THEN ec.montant ELSE 0 END),0)-COALESCE(SUM(CASE WHEN ec.compte_credit=cc.code THEN ec.montant ELSE 0 END),0) AS solde FROM comptes_comptables cc LEFT JOIN ecritures_comptables ec ON (ec.compte_debit=cc.code OR ec.compte_credit=cc.code) AND ec.exercice_id=$exercice_id WHERE cc.type='actif' AND cc.is_active=1 GROUP BY cc.code,cc.libelle HAVING solde!=0 ORDER BY cc.code");
    if ($res) while ($r = $res->fetch_assoc()) { $bilan_actif[] = $r; $actif_total += $r['solde']; }

    $res = $conn->query("SELECT cc.code, cc.libelle, COALESCE(SUM(CASE WHEN ec.compte_credit=cc.code THEN ec.montant ELSE 0 END),0)-COALESCE(SUM(CASE WHEN ec.compte_debit=cc.code THEN ec.montant ELSE 0 END),0) AS solde FROM comptes_comptables cc LEFT JOIN ecritures_comptables ec ON (ec.compte_debit=cc.code OR ec.compte_credit=cc.code) AND ec.exercice_id=$exercice_id WHERE cc.type='passif' AND cc.is_active=1 GROUP BY cc.code,cc.libelle HAVING solde!=0 ORDER BY cc.code");
    if ($res) while ($r = $res->fetch_assoc()) { $bilan_passif[] = $r; $passif_total += $r['solde']; }
}
$resultat_net = $total_produits - $total_charges;
$conn->close();

// ════════════════════════════════════════════════════════════════════
// GÉNÉRATION XLSX EN PHP PUR (SpreadsheetWriter maison)
// Format OOXML (.xlsx) sans dépendance externe
// ════════════════════════════════════════════════════════════════════

function xlsxEscape($s) {
    return htmlspecialchars((string)$s, ENT_XML1, 'UTF-8');
}

function xlsxDate($dateStr) {
    if (empty($dateStr)) return '';
    $ts = strtotime($dateStr);
    if (!$ts) return $dateStr;
    // Excel date serial (days since 1900-01-01, with leap year bug)
    return ($ts / 86400) + 25569;
}

class XlsxWriter {
    private $sheets = [];
    private $sharedStrings = [];
    private $sharedStringsMap = [];
    private $styles = [];
    private $styleMap = [];

    // Styles prédéfinis
    const S_DEFAULT   = 0;
    const S_HEADER    = 1;
    const S_HEADER2   = 2;
    const S_TOTAL     = 3;
    const S_MONEY     = 4;
    const S_MONEY_GRN = 5;
    const S_MONEY_RED = 6;
    const S_MONEY_GLD = 7;
    const S_DATE      = 8;
    const S_PCT       = 9;
    const S_SECTION   = 10;
    const S_TOTAL_GRN = 11;
    const S_TOTAL_RED = 12;
    const S_BOLD      = 13;
    const S_MUTED     = 14;
    const S_WARN      = 15;
    const S_HEADER3   = 16;

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
        $type  = $cell['t'] ?? 's'; // s=string, n=number, d=date
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
        } elseif ($type === 'f') {
            $f = xlsxEscape($val);
            return "<c r=\"$ref\" s=\"$style\" t=\"n\"><f>$f</f></c>";
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
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Largeurs colonnes
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
  <cellXfs count="17">
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
  </cellXfs>
  <cellStyles count="1">
    <cellStyle name="Normal" xfId="0" builtinId="0"/>
  </cellStyles>
</styleSheet>';
    }

    private function buildSharedStrings() {
        $count = count($this->sharedStrings);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
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

        // _rels/.rels
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>');

        // [Content_Types].xml
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
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

        // xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rIdSty" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '<Relationship Id="rIdSS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $wbRels .= "<Relationship Id=\"rId$n\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$n.xml\"/>";
        }
        $wbRels .= '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // xl/workbook.xml
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<fileVersion appName="xl" lastEdited="5" lowestEdited="5" rupBuild="9302"/>' .
            '<workbookPr defaultThemeVersion="124226"/>' .
            '<bookViews><workbookView xWindow="480" yWindow="60" windowWidth="18195" windowHeight="8505"/></bookViews>' .
            '<sheets>';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $name = xlsxEscape($sheet['name']);
            $wb .= "<sheet name=\"$name\" sheetId=\"$n\" r:id=\"rId$n\"/>";
        }
        $wb .= '</sheets>' .
            '<calcPr calcId="144525"/>' .
            '</workbook>';
        $zip->addFromString('xl/workbook.xml', $wb);

        // Styles
        $zip->addFromString('xl/styles.xml', $this->buildStyles());

        // Sheets D'ABORD (pour peupler les sharedStrings)
        $sheetXmls = [];
        foreach ($this->sheets as $i => $sheet) {
            $sheetXmls[$i] = $this->buildSheet($sheet, $i + 1);
        }

        // SharedStrings APRÈS (maintenant toutes les strings sont collectées)
        $zip->addFromString('xl/sharedStrings.xml', $this->buildSharedStrings());

        // Écrire les sheets dans le zip
        foreach ($sheetXmls as $i => $sheetXml) {
            $n = $i + 1;
            $zip->addFromString("xl/worksheets/sheet$n.xml", $sheetXml);
        }

        $zip->close();
    }
}

// ════════════════════════════════════════════════════════════════════
// CONSTRUCTION DES ONGLETS
// ════════════════════════════════════════════════════════════════════
$xw = new XlsxWriter();

// Helpers locaux
$S = 'XlsxWriter::';
$H  = XlsxWriter::S_HEADER;
$H2 = XlsxWriter::S_HEADER2;
$H3 = XlsxWriter::S_HEADER3;
$T  = XlsxWriter::S_TOTAL;
$M  = XlsxWriter::S_MONEY;
$MG = XlsxWriter::S_MONEY_GRN;
$MR = XlsxWriter::S_MONEY_RED;
$ML = XlsxWriter::S_MONEY_GLD;
$D  = XlsxWriter::S_DATE;
$P  = XlsxWriter::S_PCT;
$SC = XlsxWriter::S_SECTION;
$TG = XlsxWriter::S_TOTAL_GRN;
$TR = XlsxWriter::S_TOTAL_RED;
$B  = XlsxWriter::S_BOLD;
$MU = XlsxWriter::S_MUTED;
$W  = XlsxWriter::S_WARN;
$DEF= XlsxWriter::S_DEFAULT;

function s($v,$t='s',$style=0){return['v'=>$v,'t'=>$t,'s'=>$style];}
function n($v,$style=4){return['v'=>floatval($v),'t'=>'n','s'=>$style];}
function d($v,$style=8){return['v'=>xlsxDate($v),'t'=>'d','s'=>$style];}
function pct($v,$style=9){return['v'=>floatval($v),'t'=>'n','s'=>$style];}

function titleRows($xw, $idx, $titre, $sous, $institution, $annee, $date_export, $MU, $B, $DEF) {
    $xw->writeRow($idx, [s($institution,'s',$MU)], 14);
    $xw->writeRow($idx, [s($titre,'s',$B)], 20);
    $xw->writeRow($idx, [s("Exercice $annee  ·  $sous  ·  Exporté le $date_export",'s',$MU)], 14);
    $xw->writeRow($idx, [], 6);
}

function headerRow($xw, $idx, $cols, $style=1) {
    $cells = [];
    foreach ($cols as $label) $cells[] = s($label,'s',$style);
    $xw->writeRow($idx, $cells, 26);
}

function sectionRow($xw, $idx, $label, $ncols, $style=10) {
    $cells = [s($label,'s',$style)];
    for ($i=1;$i<$ncols;$i++) $cells[] = s('','s',$style);
    $xw->writeRow($idx, $cells, 20);
}

// ── ONGLET 1 : Notice ────────────────────────────────────────────────
$titres_rapport = [
    'complet'       => 'Rapport Financier Complet',
    'journal'       => 'Journal des Écritures Comptables',
    'depenses'      => 'Rapport des Décaissements',
    'encaissements' => 'Rapport des Encaissements',
];
$titre_rapport = $titres_rapport[$type_rapport] ?? 'Rapport Financier';

$i0 = $xw->addSheet('Notice');
$xw->setColWidth($i0,1,60); $xw->setColWidth($i0,2,65);
$xw->writeRow($i0,[s("UNIVERSITÉ VIRTUELLE — UAS",'s',$B)],28);
$xw->writeRow($i0,[s("Université Africaine des Sciences — Libreville, Gabon",'s',$MU)],16);
$xw->writeBlank($i0);
$xw->writeRow($i0,[s("$titre_rapport — Exercice $annee",'s',$B)],20);
$xw->writeRow($i0,[s("Généré le $date_export — Conforme SYSCOHADA Révisé",'s',$MU)],16);
$xw->writeBlank($i0);

// Onglets inclus selon le type
$onglets_inclus = [];
switch ($type_rapport) {
    case 'journal':
        $onglets_inclus = [
            ['Journal OHADA','Toutes les écritures comptables par ordre chronologique'],
        ];
        break;
    case 'depenses':
        $onglets_inclus = [
            ['Paiements Personnel','Salaires et honoraires avec retenue IRPP 9.5%'],
            ['Retenues DGI','Récapitulatif mensuel à reverser à la DGI'],
            ['Depenses','Dépenses opérationnelles et achats'],
        ];
        break;
    case 'encaissements':
        $onglets_inclus = [
            ['Paiements Etudiants','Historique complet des encaissements étudiants'],
        ];
        break;
    default: // complet
        $onglets_inclus = [
            ['Tableau de Bord','Résumé exécutif — KPIs financiers clés'],
            ['Journal OHADA','Toutes les écritures comptables filtrables par date'],
            ['Balance','Balance générale SYSCOHADA tous comptes mouvementés'],
            ['Paiements Etudiants','Historique complet des encaissements étudiants'],
            ['Paiements Personnel','Salaires et honoraires avec retenue IRPP 9.5%'],
            ['Retenues DGI','Récapitulatif mensuel à reverser à la DGI'],
            ['Depenses','Dépenses opérationnelles et achats'],
            ['Etats Financiers','Compte de résultat et Bilan OHADA'],
        ];
}
foreach ($onglets_inclus as $j=>$no) {
    $xw->writeRow($i0,[s($no[0],'s',$H),s($no[1],'s',$DEF)],20);
}
$xw->writeBlank($i0);
$xw->writeRow($i0,[s("Fichier généré automatiquement par UV. Ne pas modifier les formules.",'s',$W)],22);
$xw->writeRow($i0,[s("Powered by Coding Enterprise — codingenterprise.com",'s',$MU)],16);

// ── ONGLET 2 : Tableau de bord (complet uniquement) ───────────────────
if ($type_rapport === 'complet') {
$i1 = $xw->addSheet('Tableau de Bord');
$xw->setColWidth($i1,1,30); $xw->setColWidth($i1,2,50);
$xw->setColWidth($i1,3,22); $xw->setColWidth($i1,4,18); $xw->setColWidth($i1,5,18);
titleRows($xw,$i1,"TABLEAU DE BORD FINANCIER — $annee",'Résumé exécutif',$institution,$annee,$date_export,$MU,$B,$DEF);
headerRow($xw,$i1,['MODULE','DESCRIPTION','MONTANT (FCFA)','NB OPÉRATIONS','STATUT']);
$total_pe  = array_sum(array_column($pmt_etudiants,'amount_paid'));
$total_ps  = array_sum(array_column($pmt_staff,'amount'));
$total_dep = array_sum(array_column($depenses,'amount'));
$modules=[
    ['Paiements Etudiants','Frais scolarité inscription assurance',$total_pe,count($pmt_etudiants),'A jour'],
    ['Paiements Personnel','Salaires primes indemnités enseignants',$total_ps,count($pmt_staff),'Traite'],
    ['Depenses Operat.','Équipements fournitures services',$total_dep,count($depenses),'Paye'],
    ['Retenues DGI 9.5%','IRPP retenu sur honoraires enseignants',$total_retenues,count($retenues),'A reverser'],
    ['Total Produits','Classe 7 — Recettes de l\'exercice',$total_produits,count($detail_produits),'OHADA'],
    ['Total Charges','Classe 6 — Dépenses de l\'exercice',$total_charges,count($detail_charges),'OHADA'],
    ['Resultat Net',$resultat_net>=0?'Bénéfice de l\'exercice':'Déficit de l\'exercice',$resultat_net,'-',$resultat_net>=0?'Benefice':'Deficit'],
];
foreach ($modules as $j=>$m) {
    $xw->writeRow($i1,[s($m[0],'s',$DEF),s($m[1],'s',$DEF),n($m[2],$M),s($m[3],'s',$DEF),s($m[4],'s',$DEF)],22);
}
} // fin complet tableau de bord

// ── ONGLET Journal (complet + journal) ───────────────────────────────
if (in_array($type_rapport, ['complet','journal'])) {
$i2 = $xw->addSheet('Journal OHADA');
$xw->setColWidth($i2,1,14); $xw->setColWidth($i2,2,18); $xw->setColWidth($i2,3,50);
$xw->setColWidth($i2,4,12); $xw->setColWidth($i2,5,12); $xw->setColWidth($i2,6,32);
$xw->setColWidth($i2,7,12); $xw->setColWidth($i2,8,32); $xw->setColWidth($i2,9,22); $xw->setColWidth($i2,10,16);
titleRows($xw,$i2,"JOURNAL DES ÉCRITURES COMPTABLES — $annee",'Par ordre chronologique',$institution,$annee,$date_export,$MU,$B,$DEF);
headerRow($xw,$i2,['DATE','N PIECE','LIBELLE','JOURNAL','CPT DEBIT','LIB DEBIT','CPT CREDIT','LIB CREDIT','MONTANT FCFA','SOURCE']);
$total_j = 0;
foreach ($ecritures as $j=>$e) {
    $total_j += floatval($e['montant']);
    $xw->writeRow($i2,[
        d($e['date_ecriture']),
        s($e['numero_piece']??''),
        s($e['libelle']),
        s($e['journal_code'],'s',$DEF),
        s($e['compte_debit'],'s',$DEF),
        s($e['lib_debit']??''),
        s($e['compte_credit'],'s',$DEF),
        s($e['lib_credit']??''),
        n($e['montant'],$M),
        s(str_replace('_',' ',$e['source_type'])),
    ],22);
}
$xw->writeRow($i2,[s('TOTAL','s',$T),s('',$T),s('',$T),s('',$T),s('',$T),s('',$T),s('',$T),s('',$T),n($total_j,$TG),s('',$T)],22);
} // fin journal

// ── ONGLET Balance (complet uniquement) ───────────────────────────────
if ($type_rapport === 'complet') {
$i3 = $xw->addSheet('Balance');
$xw->setColWidth($i3,1,8); $xw->setColWidth($i3,2,12); $xw->setColWidth($i3,3,40);
$xw->setColWidth($i3,4,18); $xw->setColWidth($i3,5,18); $xw->setColWidth($i3,6,18); $xw->setColWidth($i3,7,18);
titleRows($xw,$i3,"BALANCE GÉNÉRALE SYSCOHADA — $annee",'Tous les comptes mouvementés',$institution,$annee,$date_export,$MU,$B,$DEF);
headerRow($xw,$i3,['CLASSE','CODE','LIBELLE DU COMPTE','TOTAL DEBIT','TOTAL CREDIT','SOLDE DEBITEUR','SOLDE CREDITEUR']);
$prev_cl=null; $td=$tc=$tsd=$tsc=0;
$labels_cl=[1=>'Classe 1 - Ressources durables',2=>'Classe 2 - Actif immobilise',3=>'Classe 3 - Stocks',4=>'Classe 4 - Tiers',5=>'Classe 5 - Tresorerie',6=>'Classe 6 - Charges',7=>'Classe 7 - Produits'];
foreach ($balance as $j=>$b) {
    if ($b['classe'] != $prev_cl) {
        sectionRow($xw,$i3,$labels_cl[$b['classe']]??'Classe '.$b['classe'],7);
        $prev_cl=$b['classe'];
    }
    $sd=max(floatval($b['solde_debiteur']),0); $sc=max(floatval($b['solde_crediteur']),0);
    $td+=floatval($b['total_debit']); $tc+=floatval($b['total_credit']); $tsd+=$sd; $tsc+=$sc;
    $xw->writeRow($i3,[s($b['classe'],'s',$DEF),s($b['code'],'s',$DEF),s($b['libelle']),n($b['total_debit'],$M),n($b['total_credit'],$M),n($sd>0?$sd:0,$M),n($sc>0?$sc:0,$M)],22);
}
$xw->writeRow($i3,[s('',$T),s('TOTAUX','s',$T),s('',$T),n($td,$TG),n($tc,$TR),n($tsd,$TG),n($tsc,$ML)],22);
} // fin balance

// ── ONGLET Paiements Étudiants (complet + encaissements) ─────────────
// Variables partagées entre onglets
$types_p=['registration'=>"Frais d'inscription",'tuition'=>'Frais de scolarité','insurance'=>'Assurance','library'=>'Bibliothèque','practical'=>'TP','other'=>'Autre'];
$meth_p=['cash'=>'Espèces','bank_transfer'=>'Virement','mobile_money'=>'Mobile Money','check'=>'Chèque','other'=>'Autre'];

if (in_array($type_rapport, ['complet','encaissements'])) {
$i4 = $xw->addSheet('Paiements Etudiants');
$xw->setColWidth($i4,1,12); $xw->setColWidth($i4,2,18); $xw->setColWidth($i4,3,14);
$xw->setColWidth($i4,4,28); $xw->setColWidth($i4,5,18); $xw->setColWidth($i4,6,16);
$xw->setColWidth($i4,7,18); $xw->setColWidth($i4,8,12);
titleRows($xw,$i4,"PAIEMENTS ÉTUDIANTS — $annee",'Historique complet des encaissements',$institution,$annee,$date_export,$MU,$B,$DEF);
headerRow($xw,$i4,['DATE','N RECU','ID ETUDIANT','NOM ETUDIANT','TYPE PAIEMENT','METHODE','MONTANT FCFA','STATUT']);
$tot_pe=0;
foreach ($pmt_etudiants as $j=>$p) {
    $tot_pe+=floatval($p['amount_paid']);
    $xw->writeRow($i4,[d($p['payment_date']),s($p['receipt_number']??''),s($p['student_id']??''),s($p['student_name']??''),s($types_p[$p['payment_type']]??$p['payment_type']),s($meth_p[$p['payment_method']]??$p['payment_method']),n($p['amount_paid'],$M),s('Valide')],22);
}
$xw->writeRow($i4,[s('TOTAL ENCAISSE','s',$T),s('',$T),s('',$T),s('',$T),s('',$T),s(count($pmt_etudiants).' paiements','s',$T),n($tot_pe,$TG),s('',$T)],22);
} // fin encaissements

// ── ONGLET Paiements Personnel (complet + depenses) ───────────────────
if (in_array($type_rapport, ['complet','depenses'])) {
$i5 = $xw->addSheet('Paiements Personnel');
$xw->setColWidth($i5,1,12); $xw->setColWidth($i5,2,16); $xw->setColWidth($i5,3,28);
$xw->setColWidth($i5,4,12); $xw->setColWidth($i5,5,16); $xw->setColWidth($i5,6,14);
$xw->setColWidth($i5,7,16); $xw->setColWidth($i5,8,14); $xw->setColWidth($i5,9,16); $xw->setColWidth($i5,10,12);
titleRows($xw,$i5,"PAIEMENTS PERSONNEL — $annee",'Salaires et honoraires avec retenue IRPP 9.5%',$institution,$annee,$date_export,$MU,$B,$DEF);
headerRow($xw,$i5,['DATE','N RECU','NOM PERSONNEL','FONCTION','TYPE','METHODE','MONTANT BRUT','RETENUE 9.5%','NET VERSE','STATUT'],$H2);
$cats_s=['salary'=>'Salaire','bonus'=>'Prime','allowance'=>'Indemnite','social'=>'Cotisation','operational'=>'Operationnel','supplier'=>'Fournisseur'];
$roles_s=['teacher'=>'Enseignant','admin'=>'Administrateur'];
$tot_brut=$tot_ret=$tot_net=0;
foreach ($pmt_staff as $j=>$p) {
    $brut=floatval($p['amount']); $ret=floatval($p['retenue']); $net=floatval($p['net']);
    $tot_brut+=$brut; $tot_ret+=$ret; $tot_net+=$net;
    $xw->writeRow($i5,[d($p['payment_date']),s($p['receipt_number']??''),s($p['staff_name']??''),s($roles_s[$p['role']]??$p['role']??''),s($cats_s[$p['category']]??$p['category']??''),s($meth_p[$p['payment_method']]??$p['payment_method']??''),n($brut,$M),n($ret>0?$ret:0,$MR),n($net,$MG),s('Traite')],22);
}
$xw->writeRow($i5,[s('TOTAUX','s',$T),s('',$T),s('',$T),s('',$T),s('',$T),s(count($pmt_staff).' paiements','s',$T),n($tot_brut,$T),n($tot_ret,$TR),n($tot_net,$TG),s('',$T)],22);
} // fin personnel

// ── ONGLET Retenues DGI (complet + depenses) ──────────────────────────
if (in_array($type_rapport, ['complet','depenses'])) {
$i6 = $xw->addSheet('Retenues DGI');
$xw->setColWidth($i6,1,14); $xw->setColWidth($i6,2,28); $xw->setColWidth($i6,3,16);
$xw->setColWidth($i6,4,12); $xw->setColWidth($i6,5,16); $xw->setColWidth($i6,6,16); $xw->setColWidth($i6,7,16);
titleRows($xw,$i6,"RETENUES IRPP 9.5% A REVERSER A LA DGI — $annee",'Recapitulatif mensuel des retenues a la source',$institution,$annee,$date_export,$MU,$B,$DEF);
$xw->writeRow($i6,[s('ATTENTION : Ce tableau doit etre soumis a la DGI chaque mois. Conserver une copie archivee.','s',$W)],22);
headerRow($xw,$i6,['PERIODE','NOM ENSEIGNANT','MONTANT BRUT','TAUX','RETENUE IRPP','NET VERSE','STATUT DGI']);
$tot_ret_dgi=0; $tot_net_dgi=0; $tot_brut_dgi=0;
foreach ($retenues as $j=>$r) {
    $tot_brut_dgi+=floatval($r['montant_brut']);
    $tot_ret_dgi+=floatval($r['montant_retenue']);
    $tot_net_dgi+=floatval($r['montant_net']);
    $statut_label=$r['statut']==='reversee'?'Reversee':'A reverser';
    $xw->writeRow($i6,[s($r['periode']),s($r['staff_name']),n($r['montant_brut'],$M),pct($r['taux_retenue']/100),n($r['montant_retenue'],$MR),n($r['montant_net'],$MG),s($statut_label)],22);
}
$xw->writeRow($i6,[s('TOTAL A REVERSER A LA DGI','s',$T),s('',$T),n($tot_brut_dgi,$T),s('9.5%','s',$T),n($tot_ret_dgi,$TR),n($tot_net_dgi,$TG),s('',$T)],22);
} // fin retenues DGI

// ── ONGLET Dépenses (complet + depenses) ─────────────────────────────
if (in_array($type_rapport, ['complet','depenses'])) {
$i7 = $xw->addSheet('Depenses');
$xw->setColWidth($i7,1,12); $xw->setColWidth($i7,2,26); $xw->setColWidth($i7,3,16);
$xw->setColWidth($i7,4,22); $xw->setColWidth($i7,5,14); $xw->setColWidth($i7,6,14);
$xw->setColWidth($i7,7,18); $xw->setColWidth($i7,8,12); $xw->setColWidth($i7,9,12);
titleRows($xw,$i7,"DÉPENSES OPÉRATIONNELLES — $annee",'Achats equipements et services',$institution,$annee,$date_export,$MU,$B,$DEF);
headerRow($xw,$i7,['DATE','TYPE DEPENSE','CATEGORIE','FOURNISSEUR','N FACTURE','METHODE','MONTANT FCFA','STATUT','CPT OHADA']);
$cats_dep=['equipment'=>'Equipement','maintenance'=>'Maintenance','utilities'=>'Services','supplies'=>'Fournitures','services'=>'Services','other'=>'Autre'];
$cptes_dep=['equipment'=>'232','maintenance'=>'604','utilities'=>'626','supplies'=>'604','services'=>'621','other'=>'604'];
$meth_dep=['bank_transfer'=>'Virement','cash'=>'Especes','check'=>'Cheque'];
$stat_dep=['paid'=>'Paye','pending'=>'En attente','cancelled'=>'Annule'];
$tot_dep=0;
foreach ($depenses as $j=>$dep) {
    $tot_dep+=floatval($dep['amount']);
    $xw->writeRow($i7,[d($dep['expense_date']),s($dep['expense_type']),s($cats_dep[$dep['category']]??$dep['category']),s($dep['vendor_name']??'N/A'),s($dep['invoice_number']??'N/A'),s($meth_dep[$dep['payment_method']]??$dep['payment_method']),n($dep['amount'],$MR),s($stat_dep[$dep['status']]??$dep['status']),s($cptes_dep[$dep['category']]??'604')],22);
}
$xw->writeRow($i7,[s('TOTAL DEPENSES','s',$T),s('',$T),s('',$T),s('',$T),s('',$T),s(count($depenses).' depenses','s',$T),n($tot_dep,$TR),s('',$T),s('',$T)],22);
} // fin depenses

// ── ONGLET États Financiers (complet uniquement) ──────────────────────
if ($type_rapport === 'complet') {
$i8 = $xw->addSheet('Etats Financiers');
$xw->setColWidth($i8,1,10); $xw->setColWidth($i8,2,42); $xw->setColWidth($i8,3,20); $xw->setColWidth($i8,4,14);
titleRows($xw,$i8,"ÉTATS FINANCIERS SIMPLIFIÉS — $annee",'Compte de résultat et Bilan — Conforme SYSCOHADA Révisé',$institution,$annee,$date_export,$MU,$B,$DEF);

sectionRow($xw,$i8,'COMPTE DE RESULTAT — PRODUITS (CLASSE 7)',4,$H3);
headerRow($xw,$i8,['CODE','LIBELLE','MONTANT FCFA','PART %'],$H3);
foreach ($detail_produits as $j=>$p) {
    $part = $total_produits>0 ? floatval($p['montant'])/$total_produits : 0;
    $xw->writeRow($i8,[s($p['code']),s($p['libelle']),n($p['montant'],$M),pct($part)],22);
}
$xw->writeRow($i8,[s('','s',$TG),s('TOTAL PRODUITS (Classe 7)','s',$TG),n($total_produits,$TG),s('100%','s',$TG)],22);
$xw->writeBlank($i8);

sectionRow($xw,$i8,'COMPTE DE RESULTAT — CHARGES (CLASSE 6)',4);
headerRow($xw,$i8,['CODE','LIBELLE','MONTANT FCFA','PART %']);
foreach ($detail_charges as $j=>$c) {
    $part = $total_charges>0 ? floatval($c['montant'])/$total_charges : 0;
    $xw->writeRow($i8,[s($c['code']),s($c['libelle']),n($c['montant'],$M),pct($part)],22);
}
$xw->writeRow($i8,[s('','s',$TR),s('TOTAL CHARGES (Classe 6)','s',$TR),n($total_charges,$TR),s('100%','s',$TR)],22);
$xw->writeBlank($i8);

$rn_label = $resultat_net>=0?'RESULTAT BENEFICIAIRE':'RESULTAT DEFICITAIRE';
$rn_style = $resultat_net>=0?$TG:$TR;
$xw->writeRow($i8,[s('',$rn_style),s($rn_label,'s',$rn_style),n($resultat_net,$rn_style),s('',$rn_style)],28);
$xw->writeBlank($i8,2);

sectionRow($xw,$i8,"BILAN AU 31/12/$annee — ACTIF",4,$H2);
headerRow($xw,$i8,['CODE','LIBELLE ACTIF','SOLDE FCFA',''],$H2);
foreach ($bilan_actif as $a) {
    $xw->writeRow($i8,[s($a['code']),s($a['libelle']),n($a['solde'],$M),s('')],22);
}
$xw->writeRow($i8,[s('',$TG),s('TOTAL ACTIF','s',$TG),n($actif_total,$TG),s('',$TG)],22);
$xw->writeBlank($i8);

sectionRow($xw,$i8,"BILAN AU 31/12/$annee — PASSIF",4);
headerRow($xw,$i8,['CODE','LIBELLE PASSIF','SOLDE FCFA','']);
foreach ($bilan_passif as $p) {
    $xw->writeRow($i8,[s($p['code']),s($p['libelle']),n($p['solde'],$M),s('')],22);
}
if ($resultat_net!=0) $xw->writeRow($i8,[s('130'),s('Résultat net de l\'exercice'),n($resultat_net,$resultat_net>=0?$MG:$MR),s('')],22);
$xw->writeRow($i8,[s('',$ML),s('TOTAL PASSIF','s',$ML),n($passif_total+$resultat_net,$ML),s('',$ML)],22);
} // fin etats financiers

// ════════════════════════════════════════════════════════════════════
// ENVOI DU FICHIER
// ════════════════════════════════════════════════════════════════════
$tmpFile = sys_get_temp_dir() . '/uv_export_' . session_id() . '_' . time() . '.xlsx';
$xw->save($tmpFile);

$suffixes = [
    'complet'       => 'Complet',
    'journal'       => 'Journal_OHADA',
    'depenses'      => 'Decaissements',
    'encaissements' => 'Encaissements',
];
$suffix = $suffixes[$type_rapport] ?? 'Export';
$filename = "UV_{$suffix}_{$institution}_{$annee}_" . date('Ymd_His') . ".xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: max-age=0');
readfile($tmpFile);
@unlink($tmpFile);
exit();