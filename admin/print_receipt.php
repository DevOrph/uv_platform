<?php
session_start();
require_once '../includes/db_connect.php';

date_default_timezone_set('Africa/Libreville');

// ── Sécurité : admin uniquement ───────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

// ── Validation du paramètre receipt_id ───────────────────────────────────────
$receipt_id = trim($_GET['receipt_id'] ?? '');
if (!preg_match('/^REC-\d{4}-\d{4}$/', $receipt_id)) {
    http_response_code(400);
    exit('Paramètre receipt_id invalide.');
}

// ── Chargement du paiement avec étudiant, classe, année académique ────────────
$stmt = $conn->prepare("
    SELECT sp.id, sp.student_id, sp.tuition_fee_id, sp.payment_date,
           sp.amount_paid, sp.payment_method, sp.payment_type,
           sp.reference_number, sp.description, sp.receipt_number,
           sp.status,
           u.name  AS student_name,
           u.id    AS student_matricule,
           c.name  AS class_name,
           tf.academic_year,
           tf.total_amount AS tuition_total,
           recorder.name AS recorded_by_name
    FROM student_payments sp
    JOIN users u         ON sp.student_id      = u.id AND u.role = 'student'
    JOIN classes c       ON u.class_id          = c.id
    JOIN tuition_fees tf  ON sp.tuition_fee_id   = tf.id
    LEFT JOIN users recorder ON sp.recorded_by   = recorder.id
    WHERE sp.receipt_number = ?
    LIMIT 1
");
$stmt->bind_param("s", $receipt_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    http_response_code(404);
    exit('Reçu introuvable ou accès refusé.');
}
$pmt = $res->fetch_assoc();
$stmt->close();

// Rejet des paiements annulés
if ($pmt['status'] === 'cancelled') {
    http_response_code(403);
    exit('Ce paiement a été annulé — aucun reçu ne peut être généré.');
}

// ── Réduction éventuelle (pour montant net) ───────────────────────────────────
$dq = $conn->prepare(
    "SELECT COALESCE(discount_amount, 0) AS disc
     FROM student_discounts
     WHERE student_id = ? AND tuition_fee_id = ?
     LIMIT 1"
);
$dq->bind_param("si", $pmt['student_id'], $pmt['tuition_fee_id']);
$dq->execute();
$drow     = $dq->get_result()->fetch_assoc();
$dq->close();
$discount   = floatval($drow['disc'] ?? 0);
$net_amount = floatval($pmt['tuition_total']) - $discount;

// ── Paiements précédents (pour le calcul de solde) ────────────────────────────
$pq = $conn->prepare(
    "SELECT COALESCE(SUM(amount_paid), 0) AS prior
     FROM student_payments
     WHERE student_id = ? AND tuition_fee_id = ? AND status = 'validated' AND id != ?"
);
$pq->bind_param("sii", $pmt['student_id'], $pmt['tuition_fee_id'], $pmt['id']);
$pq->execute();
$prior_paid     = floatval($pq->get_result()->fetch_assoc()['prior']);
$pq->close();

$balance_before = $net_amount - $prior_paid;
$amount_this    = floatval($pmt['amount_paid']);
$balance_after  = $balance_before - $amount_this;

// ── Informations institution (depuis bulletin_config) ─────────────────────────
try {
    $cfg = $conn->query("SELECT * FROM bulletin_config LIMIT 1")->fetch_assoc();
} catch (Exception $e) {
    $cfg = [];
}
$conn->close();

$inst_name = trim($cfg['school_name']     ?? 'Université Africaine des Sciences');
$inst_city = trim($cfg['location']        ?? 'Libreville');
$sig_title = trim($cfg['signature_title'] ?? 'Le Secrétaire Général');

// Chemin absolu du logo (bulletin_config.logo_path ou fallback uploads/logo-ismm.jpg)
$logo_fs = null;
if (!empty($cfg['logo_path'])) {
    $c = realpath(__DIR__ . '/../' . ltrim($cfg['logo_path'], '/'));
    if ($c && file_exists($c)) {
        $logo_fs = $c;
    }
}
if (!$logo_fs) {
    $c = realpath(__DIR__ . '/../uploads/logo-ismm.jpg');
    if ($c && file_exists($c)) {
        $logo_fs = $c;
    }
}

// ── Libellés ─────────────────────────────────────────────────────────────────
$method_labels = [
    'cash'          => 'Espèces',
    'bank_transfer' => 'Virement bancaire',
    'mobile_money'  => 'Mobile Money',
    'check'         => 'Chèque',
    'other'         => 'Autre',
];
$type_labels = [
    'registration' => 'Inscription',
    'tuition'      => 'Frais de scolarité',
    'insurance'    => 'Assurance',
    'library'      => 'Bibliothèque',
    'practical'    => 'Travaux pratiques',
    'installment'  => 'Échéance',
    'other'        => 'Autre',
];

$method_label = $method_labels[$pmt['payment_method']] ?? $pmt['payment_method'];
$type_label   = $type_labels[$pmt['payment_type']]     ?? $pmt['payment_type'];

// ═══════════════════════════════════════════════════════════════════════════════
//  GÉNÉRATION PDF — TCPDF
// ═══════════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';

class ReceiptPDF extends TCPDF
{
    public $footer_inst = '';
    public $footer_date = '';

    public function Header() {}

    public function Footer()
    {
        $this->SetY(-18);
        $this->SetFont('dejavusans', 'I', 7.5);
        $this->SetTextColor(140, 140, 140);
        $this->Cell(0, 5,
            'Document généré le ' . $this->footer_date . '  —  ' . $this->footer_inst,
            0, 1, 'C');
        $this->Cell(0, 4,
            'Page ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(),
            0, 0, 'C');
    }
}

$pdf = new ReceiptPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->footer_inst = $inst_name;
$pdf->footer_date = date('d/m/Y à H:i');

$pdf->SetCreator('UV Platform');
$pdf->SetAuthor($inst_name);
$pdf->SetTitle('Reçu de Paiement ' . $pmt['receipt_number']);
$pdf->SetSubject('Reçu de paiement étudiant');

$pdf->SetMargins(20, 20, 20);
$pdf->SetFooterMargin(18);
$pdf->SetAutoPageBreak(true, 28);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(true);

$pdf->AddPage();

// ── En-tête : logo + nom institution ─────────────────────────────────────────
$y0 = 20;

if ($logo_fs) {
    $pdf->Image($logo_fs, 20, $y0, 22, 0, '', '', '', false, 300);
    $tx = 46; $tw = 144;
} else {
    $tx = 20; $tw = 170;
}

$pdf->SetFont('dejavusans', 'B', 14);
$pdf->SetTextColor(5, 30, 52);
$pdf->SetXY($tx, $y0);
$pdf->MultiCell($tw, 7, $inst_name, 0, 'C', false, 1);

$pdf->SetFont('dejavusans', '', 9);
$pdf->SetTextColor(90, 90, 90);
$pdf->SetX($tx);
$pdf->Cell($tw, 5, $inst_city, 0, 1, 'C');

// Ligne de séparation bleue
$y_sep = max($pdf->GetY() + 2, $y0 + 26);
$pdf->SetDrawColor(3, 155, 229);
$pdf->SetLineWidth(0.8);
$pdf->Line(20, $y_sep, 190, $y_sep);

// ── Titre ─────────────────────────────────────────────────────────────────────
$pdf->SetY($y_sep + 5);
$pdf->SetFont('dejavusans', 'B', 18);
$pdf->SetTextColor(3, 155, 229);
$pdf->Cell(0, 9, 'REÇU DE PAIEMENT', 0, 1, 'C');

$pdf->SetFont('dejavusans', '', 10);
$pdf->SetTextColor(70, 70, 70);
$pdf->Cell(0, 6, 'Numéro : ' . $pmt['receipt_number'], 0, 1, 'C');
$pdf->Ln(5);

// ── Helpers ───────────────────────────────────────────────────────────────────
$row_h = 6.5;
$lbl_w = 72;

function pdf_section(ReceiptPDF $pdf, string $title): void
{
    $pdf->SetFillColor(8, 44, 76);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('dejavusans', 'B', 9);
    $pdf->Cell(0, 7, '  ' . mb_strtoupper($title), 0, 1, 'L', true);
    $pdf->Ln(1);
}

function pdf_row(ReceiptPDF $pdf, string $label, string $value, bool $alt, bool $highlight = false): void
{
    global $row_h, $lbl_w;
    if ($highlight) {
        $pdf->SetFillColor(3, 80, 140);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusans', 'B', 10);
        $h = 8;
    } else {
        if ($alt) {
            $pdf->SetFillColor(245, 247, 251);
        } else {
            $pdf->SetFillColor(255, 255, 255);
        }
        $pdf->SetTextColor(40, 40, 40);
        $pdf->SetFont('dejavusans', 'B', 9);
        $h = $row_h;
    }
    $pdf->Cell($lbl_w, $h, $label, 0, 0, 'L', true);
    $pdf->SetFont('dejavusans', '', $highlight ? 10 : 9);
    $pdf->Cell(0, $h, $value, 0, 1, 'L', true);
}

// ── Section : Informations étudiant ──────────────────────────────────────────
pdf_section($pdf, 'Informations Étudiant');
$alt = false;
foreach ([
    ['Nom complet',       $pmt['student_name']],
    ['Matricule',         $pmt['student_matricule']],
    ['Classe',            $pmt['class_name']],
    ['Année académique',  $pmt['academic_year']],
] as [$lbl, $val]) {
    pdf_row($pdf, $lbl, $val, $alt);
    $alt = !$alt;
}
$pdf->Ln(5);

// ── Section : Détails du paiement ────────────────────────────────────────────
pdf_section($pdf, 'Détails du Paiement');
$rows_pmt = [
    ['Numéro de reçu',   $pmt['receipt_number']],
    ['Date du paiement', date('d/m/Y  H:i', strtotime($pmt['payment_date']))],
    ['Mode de paiement', $method_label],
    ['Type de paiement', $type_label],
];
if (!empty($pmt['reference_number'])) {
    $rows_pmt[] = ['Référence transaction', $pmt['reference_number']];
}
if (!empty($pmt['description'])) {
    $rows_pmt[] = ['Description', $pmt['description']];
}
$rows_pmt[] = ['Enregistré par', $pmt['recorded_by_name'] ?? 'Administration'];

$alt = false;
foreach ($rows_pmt as [$lbl, $val]) {
    pdf_row($pdf, $lbl, $val, $alt);
    $alt = !$alt;
}
// Montant mis en valeur
pdf_row($pdf, 'Montant encaissé', number_format($amount_this, 0, ',', ' ') . ' FCFA', false, true);
$pdf->Ln(5);

// ── Section : Récapitulatif financier ────────────────────────────────────────
pdf_section($pdf, 'Récapitulatif Financier');
$alt = false;
foreach ([
    ['Total frais de scolarité', number_format($net_amount,     0, ',', ' ') . ' FCFA'],
    ['Solde avant ce paiement',  number_format($balance_before, 0, ',', ' ') . ' FCFA'],
    ['Montant payé (ce reçu)',   number_format($amount_this,    0, ',', ' ') . ' FCFA'],
] as [$lbl, $val]) {
    pdf_row($pdf, $lbl, $val, $alt);
    $alt = !$alt;
}

// Solde restant avec couleur selon situation
if ($balance_after > 0) {
    $pdf->SetFillColor(255, 240, 228);
    $pdf->SetTextColor(180, 75, 20);
} else {
    $pdf->SetFillColor(228, 250, 235);
    $pdf->SetTextColor(28, 130, 58);
}
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell($lbl_w, 8, 'Solde restant', 0, 0, 'L', true);
$pdf->Cell(0,      8, number_format($balance_after, 0, ',', ' ') . ' FCFA', 0, 1, 'L', true);

$pdf->Ln(12);

// ── Zone de signature ─────────────────────────────────────────────────────────
$sig_y = $pdf->GetY();
$pdf->SetDrawColor(170, 170, 170);
$pdf->SetLineWidth(0.3);
$pdf->Line(120, $sig_y + 16, 190, $sig_y + 16);
$pdf->SetXY(120, $sig_y + 18);
$pdf->SetFont('dejavusans', 'I', 9);
$pdf->SetTextColor(90, 90, 90);
$pdf->Cell(70, 5, $sig_title, 0, 1, 'C');
$pdf->SetX(120);
$pdf->SetFont('dejavusans', '', 8);
$pdf->SetTextColor(120, 120, 120);
$pdf->Cell(70, 4, 'Cachet et signature de l\'administration', 0, 1, 'C');

// ── Sortie PDF ────────────────────────────────────────────────────────────────
$filename = 'recu_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $pmt['receipt_number']) . '.pdf';
$pdf->Output($filename, 'I');
