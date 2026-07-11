<?php
/**
 * Génère un bulletin de notes au format XLSX
 * en utilisant le template officiel de l'école (BULL_N0).
 *
 * Mapping feuille BULL_N0 :
 *   C = code UE | D = nom UE | E = module | F = crédits
 *   G = note_normale | H = moy_UE normale | I = crédits session normale
 *   J = note_rattrapage | K = moy_UE finale | L = crédits finaux
 *   M = décision UE
 *
 * GET params:
 *   class_id, student_id, period_id          → bulletin individuel
 *   class_id, period_id, mode=class          → tous les étudiants de la classe
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('memory_limit', '512M');
set_time_limit(120);
ob_start();

require_once '../includes/db_connect.php';
require_once '../includes/grade_calculator.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

function removeGroupSuffix(string $name): string {
    return trim(preg_replace('/\s*\(groupe\s*\d+\)\s*/i', '', $name));
}

function errorPage(string $message, int $httpCode = 400): void {
    ob_end_clean();
    http_response_code($httpCode);
    echo '<!DOCTYPE html><html lang="fr"><head>
    <meta charset="utf-8">
    <title>Erreur bulletin</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f5f5f5;
               display:flex; align-items:center; justify-content:center;
               height:100vh; margin:0; }
        .box { background:white; padding:30px 40px; border-radius:8px;
               box-shadow:0 2px 10px rgba(0,0,0,0.1);
               max-width:520px; text-align:center; }
        .icon { font-size:48px; margin-bottom:15px; }
        h3 { color:#e74c3c; margin:0 0 10px; }
        p { color:#666; margin:0 0 20px; line-height:1.5; }
        a { background:#3498db; color:white; padding:10px 20px;
            border-radius:5px; text-decoration:none; font-size:14px; }
        a:hover { background:#2980b9; }
    </style>
    </head><body><div class="box">
    <div class="icon">&#9888;&#65039;</div>
    <h3>Impossible de générer le bulletin</h3>
    <p>' . nl2br(htmlspecialchars($message)) . '</p>
    <a href="javascript:history.back()">&#8592; Retour</a>
    </div></body></html>';
    exit;
}

/**
 * Injecte logo + cachet dans la feuille $sheetName du fichier XLSX.
 * Fonctionne sur n'importe quelle feuille (pas seulement "Bulletin").
 */
function injectBulletinImages(string $xlsxPath, string $templatePath, int $totalRow, string $sheetName = 'Bulletin'): void {
    if (!class_exists('ZipArchive') || !file_exists($templatePath)) {
        return;
    }

    $workFile = $xlsxPath . '.imgwork';
    if (!copy($xlsxPath, $workFile)) {
        return;
    }

    try {
        $tmpl = new ZipArchive();
        if ($tmpl->open($templatePath) !== true) {
            throw new RuntimeException('Impossible d\'ouvrir le template');
        }
        $xlsx = new ZipArchive();
        if ($xlsx->open($workFile) !== true) {
            $tmpl->close();
            throw new RuntimeException('Impossible d\'ouvrir le XLSX généré');
        }

        try {
            // ── 1. Trouver la feuille $sheetName dans workbook.xml ────────────
            $wbXml = $xlsx->getFromName('xl/workbook.xml');
            if ($wbXml === false) {
                throw new RuntimeException('xl/workbook.xml introuvable');
            }
            $escapedName = preg_quote($sheetName, '/');
            if (!preg_match('/<sheet\b([^>]*)name="' . $escapedName . '"([^>]*)\/>/i', $wbXml, $sm)) {
                throw new RuntimeException("Feuille \"$sheetName\" introuvable dans workbook.xml");
            }
            if (!preg_match('/\br:id="([^"]+)"/', $sm[1] . $sm[2], $m)) {
                throw new RuntimeException("Attribut r:id introuvable sur la feuille $sheetName");
            }
            $sheetRid = $m[1];

            // ── 2. Résoudre rId → chemin du fichier sheet ─────────────────────
            $wbRels = $xlsx->getFromName('xl/_rels/workbook.xml.rels');
            if ($wbRels === false) {
                throw new RuntimeException('xl/_rels/workbook.xml.rels introuvable');
            }
            if (!preg_match('/\bId="' . preg_quote($sheetRid, '/') . '"[^>]*Target="([^"]+)"/i', $wbRels, $m)) {
                throw new RuntimeException("Relation $sheetRid introuvable dans workbook.xml.rels");
            }
            $sheetTarget   = $m[1];
            $sheetBasename = basename($sheetTarget);
            $sheetXmlPath  = 'xl/' . ltrim($sheetTarget, '/');
            $sheetRelsPath = 'xl/worksheets/_rels/' . $sheetBasename . '.rels';

            // ── 3. Trouver le drawing existant ou en créer un nouveau ──────────
            $drawingRelType  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing';
            $sheetRelsXml    = $xlsx->getFromName($sheetRelsPath);
            $drawingPath     = null;
            $drawingRelsPath = null;
            $drawingIsNew    = false;

            if ($sheetRelsXml !== false) {
                $pat = '/\bType="' . preg_quote($drawingRelType, '/') . '"[^>]*Target="([^"]+)"/i';
                if (preg_match($pat, $sheetRelsXml, $m)) {
                    $drawingFile     = basename($m[1]);
                    $drawingPath     = 'xl/drawings/' . $drawingFile;
                    $drawingRelsPath = 'xl/drawings/_rels/' . $drawingFile . '.rels';
                }
            }

            if ($drawingPath === null) {
                // Trouver le prochain nom de fichier drawing disponible (évite les conflits entre feuilles)
                $drawingIsNew  = true;
                $drawingIndex  = 1;
                while ($xlsx->locateName('xl/drawings/drawing' . $drawingIndex . '.xml') !== false) {
                    $drawingIndex++;
                }
                $drawingFile     = 'drawing' . $drawingIndex . '.xml';
                $drawingPath     = 'xl/drawings/' . $drawingFile;
                $drawingRelsPath = 'xl/drawings/_rels/' . $drawingFile . '.rels';

                preg_match_all('/\bId="rId(\d+)"/i', (string)$sheetRelsXml, $ids);
                $nextRid    = empty($ids[1]) ? 1 : max(array_map('intval', $ids[1])) + 1;
                $newDrawRid = 'rId' . $nextRid;

                $relEntry = '<Relationship Id="' . $newDrawRid . '" Type="' . $drawingRelType . '" Target="../drawings/' . $drawingFile . '"/>';
                if ($sheetRelsXml !== false) {
                    $newSheetRels = str_replace('</Relationships>', $relEntry . "\n</Relationships>", $sheetRelsXml);
                } else {
                    $relsNs       = 'http://schemas.openxmlformats.org/package/2006/relationships';
                    $newSheetRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                        . '<Relationships xmlns="' . $relsNs . '">' . $relEntry . '</Relationships>';
                }
                $xlsx->deleteName($sheetRelsPath);
                $xlsx->addFromString($sheetRelsPath, $newSheetRels);

                $sheetXml = $xlsx->getFromName($sheetXmlPath);
                if ($sheetXml !== false && strpos($sheetXml, '<drawing ') === false) {
                    $sheetXml = str_replace(
                        '</worksheet>',
                        '<drawing r:id="' . $newDrawRid . '"/></worksheet>',
                        $sheetXml
                    );
                    $xlsx->deleteName($sheetXmlPath);
                    $xlsx->addFromString($sheetXmlPath, $sheetXml);
                }
            }

            // ── 4. Construire le drawing XML depuis drawing9.xml du template ──
            $draw9Xml = $tmpl->getFromName('xl/drawings/drawing9.xml');
            if ($draw9Xml === false) {
                throw new RuntimeException('drawing9.xml introuvable dans le template');
            }
            if (!preg_match_all('/<xdr:twoCellAnchor\b[^>]*>.*?<\/xdr:twoCellAnchor>/s', $draw9Xml, $anchors)
                || count($anchors[0]) < 2
            ) {
                throw new RuntimeException('Ancres introuvables dans drawing9.xml du template');
            }

            $cachetFromRow0 = $totalRow - 2;
            $cachetToRow0   = $totalRow + 4;

            $origCachet    = $anchors[0][1];
            $updatedCachet = preg_replace_callback(
                '/<xdr:from>(.*?)<\/xdr:from>/s',
                fn($m) => '<xdr:from>' . preg_replace('/<xdr:row>\d+<\/xdr:row>/', '<xdr:row>' . $cachetFromRow0 . '</xdr:row>', $m[1]) . '</xdr:from>',
                $origCachet
            );
            $updatedCachet = preg_replace_callback(
                '/<xdr:to>(.*?)<\/xdr:to>/s',
                fn($m) => '<xdr:to>' . preg_replace('/<xdr:row>\d+<\/xdr:row>/', '<xdr:row>' . $cachetToRow0 . '</xdr:row>', $m[1]) . '</xdr:to>',
                $updatedCachet
            );

            $newDrawXml = str_replace($origCachet, $updatedCachet, $draw9Xml);

            if (strpos($newDrawXml, 'xmlns:r=') === false) {
                $newDrawXml = str_replace(
                    '<xdr:wsDr ',
                    '<xdr:wsDr xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" ',
                    $newDrawXml
                );
            }

            $xlsx->deleteName($drawingPath);
            $xlsx->addFromString($drawingPath, $newDrawXml);

            // ── 5. Copier drawing9.xml.rels du template ───────────────────────
            $draw9Rels = $tmpl->getFromName('xl/drawings/_rels/drawing9.xml.rels');
            if ($draw9Rels === false) {
                throw new RuntimeException('drawing9.xml.rels introuvable dans le template');
            }
            // Les chemins relatifs ../media/ sont valides depuis n'importe quel drawingN.xml.rels
            $xlsx->deleteName($drawingRelsPath);
            $xlsx->addFromString($drawingRelsPath, $draw9Rels);

            // ── 6. Copier image8.jpeg et image9.png depuis le template ────────
            foreach (['xl/media/image8.jpeg', 'xl/media/image9.png'] as $imgEntry) {
                $imgData = $tmpl->getFromName($imgEntry);
                if ($imgData === false) {
                    throw new RuntimeException("$imgEntry introuvable dans le template");
                }
                $xlsx->deleteName($imgEntry);
                $xlsx->addFromString($imgEntry, $imgData);
            }

            // ── 7. [Content_Types].xml ────────────────────────────────────────
            $ctXml = $xlsx->getFromName('[Content_Types].xml');
            if ($ctXml !== false) {
                $ct = $ctXml;
                if (strpos($ct, 'Extension="png"') === false) {
                    $ct = str_replace('</Types>', '<Default Extension="png" ContentType="image/png"/>' . "\n</Types>", $ct);
                }
                if (strpos($ct, 'Extension="jpeg"') === false && strpos($ct, 'Extension="jpg"') === false) {
                    $ct = str_replace('</Types>', '<Default Extension="jpeg" ContentType="image/jpeg"/>' . "\n</Types>", $ct);
                }
                if ($drawingIsNew) {
                    $drawingCT = 'application/vnd.openxmlformats-officedocument.drawing+xml';
                    $partName  = '/' . $drawingPath;
                    if (strpos($ct, $partName) === false) {
                        $ct = str_replace('</Types>', '<Override PartName="' . $partName . '" ContentType="' . $drawingCT . '"/>' . "\n</Types>", $ct);
                    }
                }
                if ($ct !== $ctXml) {
                    $xlsx->deleteName('[Content_Types].xml');
                    $xlsx->addFromString('[Content_Types].xml', $ct);
                }
            }

        } finally {
            $xlsx->close();
            $tmpl->close();
        }

        if (!rename($workFile, $xlsxPath)) {
            copy($workFile, $xlsxPath);
            unlink($workFile);
        }

    } catch (Throwable $e) {
        error_log('Bulletin image injection échouée : ' . $e->getMessage());
        if (file_exists($workFile)) {
            unlink($workFile);
        }
    }
}

/**
 * Remplit une feuille XLSX avec les données de bulletin d'un étudiant.
 * Retourne le numéro de ligne TOTAL (pour positionnement du cachet).
 */
function fillBulletinSheet(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
    string $studentId,
    string $classId,
    int $periodId,
    mysqli $conn
): int {
    // ── Configuration école ───────────────────────────────────────────────
    $configQuery = $conn->query("SELECT * FROM bulletin_config LIMIT 1");
    $config = $configQuery ? $configQuery->fetch_assoc() : [];

    // ── Colonnes disponibles dans users ───────────────────────────────────
    $columnsQuery = $conn->query("SHOW COLUMNS FROM users");
    $availCols = [];
    while ($r = $columnsQuery->fetch_assoc()) $availCols[] = $r['Field'];

    $selectFields = ['name', 'id'];
    foreach (['matricule', 'date_of_birth', 'place_of_birth', 'nationality', 'option', 'redoublant'] as $f) {
        if (in_array($f, $availCols)) $selectFields[] = $f;
    }

    // ── Étudiant ──────────────────────────────────────────────────────────
    $sq = $conn->prepare('SELECT ' . implode(', ', $selectFields) . ' FROM users WHERE id = ?');
    $sq->bind_param('s', $studentId);
    $sq->execute();
    $student = $sq->get_result()->fetch_assoc();
    $sq->close();
    if (!$student) throw new RuntimeException("L'étudiant demandé est introuvable ou n'appartient pas à cette classe.");

    $studentName = $student['name'];
    $matricule   = $student['matricule']     ?? $student['id'];
    $dateOfBirth = $student['date_of_birth'] ?? '';
    $nationality = $student['nationality']   ?? 'Gabonaise';

    // ── Classe ────────────────────────────────────────────────────────────
    $cq = $conn->prepare('SELECT name, semester_start FROM classes WHERE id = ?');
    $cq->bind_param('s', $classId);
    $cq->execute();
    $classRow = $cq->get_result()->fetch_assoc();
    $cq->close();
    if (!$classRow) throw new RuntimeException("La classe sélectionnée est introuvable. Vérifiez la configuration des classes.");
    $className = removeGroupSuffix($classRow['name']);

    // ── Filière ───────────────────────────────────────────────────────────
    $fq = $conn->prepare(
        'SELECT f.name, f.type_diplome FROM filieres f JOIN classes c ON c.filiere_id = f.id WHERE c.id = ?'
    );
    $fq->bind_param('s', $classId);
    $fq->execute();
    $filiereRow     = $fq->get_result()->fetch_assoc();
    $fq->close();
    $filiereName    = $filiereRow['name']         ?? $className;
    $filiereDiplome = $filiereRow['type_diplome'] ?? null;

    // ── Période ───────────────────────────────────────────────────────────
    $pq = $conn->prepare('SELECT name, school_year FROM evaluation_periods WHERE id = ?');
    $pq->bind_param('i', $periodId);
    $pq->execute();
    $periodRow = $pq->get_result()->fetch_assoc();
    $pq->close();
    if (!$periodRow) throw new RuntimeException("La période académique sélectionnée est introuvable. Vérifiez la configuration des semestres.");
    $periodSchoolYear = $periodRow['school_year'] ?? '';

    $poq = $conn->prepare('SELECT id FROM evaluation_periods WHERE school_year = ? ORDER BY id ASC');
    $poq->bind_param('s', $periodSchoolYear);
    $poq->execute();
    $allPeriodIds = [];
    $poqRes = $poq->get_result();
    while ($poqRow = $poqRes->fetch_assoc()) $allPeriodIds[] = (int)$poqRow['id'];
    $poq->close();
    $periodOrder    = (($idx = array_search((int)$periodId, $allPeriodIds)) !== false) ? $idx + 1 : 1;
    $semesterStart  = (int)($classRow['semester_start'] ?? 1);
    $semesterNumber = ($periodOrder === 1) ? $semesterStart : $semesterStart + 1;

    // ── Calcul des notes ──────────────────────────────────────────────────
    $calcResult = calculate_student_semester_grades($conn, $studentId, $classId, $periodId);
    if (empty($calcResult['ues'])) {
        throw new RuntimeException("Aucun cours n'est configuré pour cette classe et ce semestre.\nVérifiez que des UE et des modules ont bien été ajoutés dans la gestion des cours.");
    }

    $allGrades = [];
    foreach ($calcResult['ues'] as $ue) {
        $courses   = [];
        $normWSum  = 0.0;
        $normTCoef = 0.0;

        foreach ($ue['modules'] as $mod) {
            $noteNorm   = round($mod['note_cc'] * 0.4 + $mod['note_exa'] * 0.6, 2);
            $normWSum  += $noteNorm * $mod['credit'];
            $normTCoef += $mod['credit'];

            $courses[] = [
                'name'            => removeGroupSuffix($mod['name']),
                'credits'         => (float)$mod['credit'],
                'note_cc'         => $mod['note_cc'],
                'note_exa'        => $mod['note_exa'],
                'note_rattrapage' => $mod['note_rattrapage'],
                'note_normale'    => $noteNorm,
                'note_finale'     => $mod['note_finale'],
                'eliminatoire'    => $mod['eliminatoire'],
            ];
        }

        $moyNormale       = $normTCoef > 0 ? round($normWSum / $normTCoef, 2) : 0.0;
        $creditsNormale   = $moyNormale >= 10 ? (int)round($ue['credit_total']) : 0;
        $creditsValidated = $ue['valide']      ? (int)round($ue['credit_total']) : 0;

        $allGrades[] = [
            'code'              => $ue['code'],
            'name'              => $ue['name'],
            'courses'           => $courses,
            'total_ects'        => $ue['credit_total'],
            'moy_normale'       => $moyNormale,
            'moy_finale'        => $ue['moy_ue'],
            'credits_normale'   => $creditsNormale,
            'credits_validated' => $creditsValidated,
            'validated'         => $ue['valide'],
            'has_eliminatoire'  => $ue['has_eliminatoire'],
        ];
    }

    $normWSum = 0.0; $normTCoef = 0.0; $totalCreditsNormale = 0;
    foreach ($allGrades as $ue) {
        $normWSum  += $ue['moy_normale'] * $ue['total_ects'];
        $normTCoef += $ue['total_ects'];
        $totalCreditsNormale += $ue['credits_normale'];
    }
    $moyNormaleGlobale = $normTCoef > 0 ? round($normWSum / $normTCoef, 2) : 0.0;

    $generalAverage        = $calcResult['moy_generale'];
    $totalCreditsRequired  = $calcResult['credits_total'];
    $totalCreditsValidated = $calcResult['credits_obtenus'];
    $semestreValide        = $calcResult['decision'] === 'valide';

    $totalModules = array_sum(array_map(fn($u) => count($u['courses']), $allGrades));

    // ── Mise à jour de l'année académique ─────────────────────────────────
    $currentE5 = (string)$sheet->getCell('E5')->getValue();
    if ($periodSchoolYear) {
        $yearDisplay = str_replace('-', '/', $periodSchoolYear);
        $newE5 = preg_replace('/\d{4}\/\d{4}/', $yearDisplay, $currentE5);
        if ($newE5 !== $currentE5) $sheet->setCellValue('E5', $newE5);
    }

    // ── En-tête ────────────────────────────────────────────────────────────
    $sheet->setCellValue('B8',  'RELEVE DE NOTE SEMESTRE ' . $semesterNumber);
    $sheet->setCellValue('C10', $studentName);
    $sheet->setCellValue('D11', $dateOfBirth ?: '—');
    $sheet->setCellValue('C12', $nationality);
    $sheet->setCellValue('C13', $matricule);
    $sheet->setCellValue('G10', $filiereName);
    $sheet->setCellValue('G11', $className);
    $sheet->setCellValue('G12', $student['option'] ?? $filiereDiplome ?? '—');
    $sheet->setCellValue('G13', $student['redoublant'] ?? 'Non');
    $sheet->setCellValue('B16', 'SEMESTRE ' . $semesterNumber);

    // ── Zone des notes : lignes 18-27 dans le template (10 modules) ──────
    $gradeRowStart    = 18;
    $gradeRowTemplate = 10;
    $gradeTotalRow    = 28;
    $gradeModuleEnd   = $gradeRowStart + $gradeRowTemplate - 1;

    foreach (array_keys($sheet->getMergeCells()) as $merge) {
        $topLeft = explode(':', $merge)[0];
        preg_match('/(\d+)/', $topLeft, $m);
        $r = (int)($m[1] ?? 0);
        if ($r >= $gradeRowStart && $r <= $gradeTotalRow) {
            $sheet->unmergeCells($merge);
        }
    }

    for ($row = $gradeRowStart; $row <= $gradeTotalRow; $row++) {
        for ($colIdx = 2; $colIdx <= 15; $colIdx++) {
            $col = Coordinate::stringFromColumnIndex($colIdx);
            $sheet->getCell($col . $row)->setValue('');
        }
    }

    if ($totalModules > $gradeRowTemplate) {
        $extra = $totalModules - $gradeRowTemplate;
        $sheet->insertNewRowBefore($gradeTotalRow, $extra);
        $gradeTotalRow  += $extra;
        $gradeModuleEnd += $extra;
    }

    $currentRow = $gradeRowStart;

    foreach ($allGrades as $ue) {
        $ueStartRow = $currentRow;
        $numMods    = count($ue['courses']);
        $ueEndRow   = $currentRow + $numMods - 1;

        $sheet->setCellValue('C' . $ueStartRow, $ue['code']);
        $sheet->setCellValue('D' . $ueStartRow, $ue['name']);
        $sheet->setCellValue('H' . $ueStartRow, $ue['moy_normale']);
        $sheet->setCellValue('I' . $ueStartRow, $ue['credits_normale']);
        $sheet->setCellValue('K' . $ueStartRow, $ue['moy_finale']);
        $sheet->setCellValue('L' . $ueStartRow, $ue['credits_validated']);

        $decisionText = $ue['validated'] ? 'Validé' : 'Non validé';
        if ($ue['has_eliminatoire']) $decisionText .= ' ';
        $sheet->setCellValue('M' . $ueStartRow, $decisionText);

        if ($numMods > 1) {
            foreach (['C', 'D', 'H', 'I', 'K', 'L', 'M'] as $col) {
                $sheet->mergeCells("{$col}{$ueStartRow}:{$col}{$ueEndRow}");
            }
            $sheet->getStyle("C{$ueStartRow}:D{$ueEndRow}")
                  ->getAlignment()
                  ->setVertical(Alignment::VERTICAL_CENTER)
                  ->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("H{$ueStartRow}:M{$ueEndRow}")
                  ->getAlignment()
                  ->setVertical(Alignment::VERTICAL_CENTER)
                  ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        foreach ($ue['courses'] as $course) {
            $sheet->setCellValue('E' . $currentRow, $course['name']);
            $sheet->setCellValue('F' . $currentRow, $course['credits']);
            $sheet->setCellValue('G' . $currentRow, $course['note_normale']);
            if ($course['note_rattrapage'] !== null) {
                $sheet->setCellValue('J' . $currentRow, $course['note_rattrapage']);
            }
            $sheet->getStyle("A{$currentRow}:M{$currentRow}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']]],
            ]);
            $currentRow++;
        }

        $sheet->getStyle("A{$ueEndRow}:M{$ueEndRow}")->applyFromArray([
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']]],
        ]);
    }

    // Ligne TOTAL
    $sheet->setCellValue('C' . $gradeTotalRow, 'TOTAL');
    $sheet->setCellValue('F' . $gradeTotalRow, $totalCreditsRequired);
    $sheet->setCellValue('H' . $gradeTotalRow, $moyNormaleGlobale);
    $sheet->setCellValue('I' . $gradeTotalRow, $totalCreditsNormale);
    $sheet->setCellValue('K' . $gradeTotalRow, round($generalAverage, 2));
    $sheet->setCellValue('L' . $gradeTotalRow, $totalCreditsValidated);
    $sheet->getStyle("A{$gradeTotalRow}:M{$gradeTotalRow}")->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']]],
    ]);
    $sheet->getStyle('A17:M17')->applyFromArray([
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']]],
    ]);

    for ($r = 14; $r <= $gradeTotalRow; $r++) {
        $sheet->getStyle("A{$r}")
              ->getBorders()
              ->getAllBorders()
              ->setBorderStyle(Border::BORDER_NONE);
    }

    // ── Résumé / pied de page ─────────────────────────────────────────────
    $shift = $gradeTotalRow - 28;

    // Absences non justifiées : status='absent' (≠ 'justified')
    // Priorité durée : att.duration (créneaux fusionnés) > TIMESTAMPDIFF(ts) > 1.5h fallback
    $absQ = $conn->prepare(
        "SELECT COALESCE(SUM(
            COALESCE(att.duration,
                CASE WHEN ts.id IS NOT NULL
                     THEN TIMESTAMPDIFF(MINUTE, ts.start_time, ts.end_time) / 60
                     ELSE 1.5
                END
            )
        ), 0) AS heures_absence
        FROM attendance_records ar
        JOIN attendance_sessions att ON ar.session_id = att.id
        LEFT JOIN time_slots ts ON att.time_slot_id = ts.id
        JOIN evaluation_periods ep ON ep.id = ?
        WHERE ar.student_id = ?
          AND att.class_id = ?
          AND att.session_date BETWEEN ep.start_date AND ep.end_date
          AND ar.status = 'absent'"
    );
    $absClassId = (int)$classId;
    $absQ->bind_param('isi', $periodId, $studentId, $absClassId);
    $absQ->execute();
    $absRow        = $absQ->get_result()->fetch_assoc();
    $absQ->close();
    $heuresAbsence = round((float)($absRow['heures_absence'] ?? 0), 1);

    $sheet->setCellValue('D' . (30 + $shift), $totalCreditsValidated . ' / ' . $totalCreditsRequired);
    $sheet->setCellValue('D' . (31 + $shift), $semestreValide ? 'Semestre validé' : 'Semestre non validé');
    $sheet->setCellValue('D' . (32 + $shift), $heuresAbsence);

    $sigName = $config['signature_name'] ?? '';
    if ($sigName) {
        $sheet->setCellValue('G' . (34 + $shift), $sigName);
    }

    // ── Mise en forme ──────────────────────────────────────────────────────
    $sheet->getColumnDimension('A')->setWidth(2.0);
    $sheet->getColumnDimension('B')->setWidth(9.5);
    $sheet->getColumnDimension('C')->setWidth(8.5);
    $sheet->getColumnDimension('D')->setWidth(19.0);
    $sheet->getColumnDimension('E')->setWidth(26.0);
    $sheet->getColumnDimension('F')->setWidth(7.0);
    $sheet->getColumnDimension('G')->setWidth(7.5);
    $sheet->getColumnDimension('H')->setWidth(7.0);
    $sheet->getColumnDimension('I')->setWidth(6.5);
    $sheet->getColumnDimension('J')->setWidth(7.5);
    $sheet->getColumnDimension('K')->setWidth(7.5);
    $sheet->getColumnDimension('L')->setWidth(6.5);
    $sheet->getColumnDimension('M')->setWidth(9.0);

    $sheet->getStyle('A1:M44')->getFont()->setSize(8);
    $sheet->getStyle('A1:M8')->getFont()->setSize(10);

    foreach (range('N', 'P') as $col) {
        foreach ($sheet->getColumnIterator($col, $col) as $column) {
            foreach ($column->getCellIterator() as $cell) {
                $cell->setValue(null);
            }
        }
        $sheet->getColumnDimension($col)->setWidth(0);
        $sheet->getColumnDimension($col)->setVisible(false);
    }

    $lastRow = $sheet->getHighestRow();
    $sheet->getPageSetup()->setPrintArea("A1:M{$lastRow}");

    for ($row = 39; $row <= 50; $row++) {
        for ($col = 'A'; $col <= 'P'; $col++) {
            $sheet->getCell($col . $row)->setValue(null);
        }
    }

    return $gradeTotalRow;
}

// ── Paramètres GET ────────────────────────────────────────────────────────────
$classId   = $_GET['class_id']   ?? null;
$studentId = $_GET['student_id'] ?? null;
$periodId  = $_GET['period_id']  ?? null;
$mode      = $_GET['mode']       ?? 'individual';
$yearParam = $_GET['year']       ?? null;

if (!$classId) {
    errorPage("Paramètres de requête incomplets.\nVeuillez sélectionner une classe et une période avant de télécharger le bulletin.");
}

// Si period_id absent, le déduire depuis year ou ANNEE_ACADEMIQUE_COURANTE
if (!$periodId) {
    require_once '../includes/semester_helper.php';
    $targetYear = $yearParam ?? ANNEE_ACADEMIQUE_COURANTE;
    // Essayer la période courante via semester_helper
    $currentPeriod = get_current_period($conn);
    if ($currentPeriod['id'] && ($currentPeriod['school_year'] ?? '') === $targetYear) {
        $periodId = (string) $currentPeriod['id'];
    } else {
        // Prendre la dernière période de l'année cible
        $stmtFp = $conn->prepare(
            "SELECT id FROM evaluation_periods WHERE school_year = ? ORDER BY id DESC LIMIT 1"
        );
        $stmtFp->bind_param('s', $targetYear);
        $stmtFp->execute();
        $fpRow = $stmtFp->get_result()->fetch_assoc();
        $stmtFp->close();
        if ($fpRow) {
            $periodId = (string) $fpRow['id'];
        } else {
            errorPage("Aucune période académique trouvée pour l'année $targetYear.\nVeuillez configurer les périodes avant de générer un bulletin.");
        }
    }
}

if ($mode !== 'class' && !$studentId) {
    errorPage("Aucun étudiant sélectionné.\nVeuillez choisir un étudiant dans la liste avant de générer le bulletin.");
}

$templatePath = __DIR__ . '/templates/bulletin_template_official.xlsm';

try {
    if (!file_exists($templatePath)) {
        throw new RuntimeException("Template introuvable : $templatePath");
    }

    if ($mode === 'class') {
        // ── Mode classe : un onglet par étudiant ──────────────────────────

        $cq = $conn->prepare('SELECT name FROM classes WHERE id = ?');
        $cq->bind_param('s', $classId);
        $cq->execute();
        $classRow = $cq->get_result()->fetch_assoc();
        $cq->close();
        if (!$classRow) throw new RuntimeException("La classe sélectionnée est introuvable. Vérifiez la configuration des classes.");
        $classNameSafe = removeGroupSuffix($classRow['name']);

        $pq = $conn->prepare('SELECT school_year FROM evaluation_periods WHERE id = ?');
        $pq->bind_param('i', $periodId);
        $pq->execute();
        $periodRow = $pq->get_result()->fetch_assoc();
        $pq->close();
        $schoolYear = $periodRow['school_year'] ?? date('Y');

        $sq = $conn->prepare(
            "SELECT id, name FROM users WHERE class_id = ? AND role = 'student' AND status = 'active' AND blocked = 0 ORDER BY name ASC"
        );
        $sq->bind_param('s', $classId);
        $sq->execute();
        $students = $sq->get_result()->fetch_all(MYSQLI_ASSOC);
        $sq->close();

        if (empty($students)) {
            throw new RuntimeException("Aucun étudiant actif n'a été trouvé dans cette classe.\nVérifiez que des étudiants sont bien inscrits et que leur statut est « actif ».");
        }

        $spreadsheet   = IOFactory::load($templatePath);
        $templateSheet = $spreadsheet->getSheetByName('BULL_N0');
        if (!$templateSheet) {
            throw new RuntimeException("Feuille 'BULL_N0' introuvable dans le template.");
        }

        // Supprimer toutes les feuilles sauf BULL_N0
        for ($i = $spreadsheet->getSheetCount() - 1; $i >= 0; $i--) {
            if ($spreadsheet->getSheet($i)->getTitle() !== 'BULL_N0') {
                $spreadsheet->removeSheetByIndex($i);
            }
        }

        $gradeTotalRows = []; // sheetTitle => gradeTotalRow
        $studentErrors  = []; // name => message

        foreach ($students as $student) {
            $sheetTitle = mb_substr($student['name'], 0, 31);
            // Dédoublonner les titres si deux étudiants ont le même nom tronqué
            $baseTitle = $sheetTitle;
            $suffix = 1;
            while (isset($gradeTotalRows[$sheetTitle])) {
                $sheetTitle = mb_substr($baseTitle, 0, 28) . '_' . $suffix++;
            }

            $newSheet = clone $templateSheet;
            $newSheet->setTitle($sheetTitle);
            $spreadsheet->addSheet($newSheet);

            try {
                $gradeTotalRows[$sheetTitle] = fillBulletinSheet($newSheet, $student['id'], $classId, (int)$periodId, $conn);
            } catch (Throwable $e) {
                $studentErrors[$student['name']] = $e->getMessage();
                $gradeTotalRows[$sheetTitle]     = 28;
                error_log("Erreur bulletin étudiant {$student['id']} : " . $e->getMessage());
                // Laisser la feuille vide (sans contenu d'erreur) pour ne pas corrompre le fichier
                $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($newSheet));
                unset($gradeTotalRows[$sheetTitle]);
            }
        }

        // Supprimer la feuille template BULL_N0
        $bull0Index = $spreadsheet->getIndex($templateSheet);
        if ($bull0Index !== false) {
            $spreadsheet->removeSheetByIndex($bull0Index);
        }

        // Si tous les étudiants ont échoué → erreur globale
        if ($spreadsheet->getSheetCount() === 0) {
            $firstError = reset($studentErrors);
            throw new RuntimeException(
                "La génération a échoué pour tous les étudiants de la classe.\n" .
                "Première erreur rencontrée : " . $firstError
            );
        }

        // Si seulement certains ont échoué → ajouter une feuille récapitulative des erreurs
        if (!empty($studentErrors)) {
            $errSheet = $spreadsheet->createSheet(0);
            $errSheet->setTitle('_Erreurs');
            $errSheet->setCellValue('A1', 'Étudiants ignorés lors de la génération');
            $errSheet->setCellValue('A2', count($studentErrors) . ' étudiant(s) n\'ont pas pu être générés :');
            $row = 4;
            foreach ($studentErrors as $name => $msg) {
                $errSheet->setCellValue('A' . $row, $name);
                $errSheet->setCellValue('B' . $row, $msg);
                $row++;
            }
            $errSheet->getColumnDimension('A')->setWidth(30);
            $errSheet->getColumnDimension('B')->setWidth(60);
        }

        if ($spreadsheet->getSheetCount() > 0) {
            // Activer la première feuille étudiant (index 0 si pas de feuille erreurs, sinon index 1)
            $spreadsheet->setActiveSheetIndex(empty($studentErrors) ? 0 : 1);
        }

        ob_end_clean();

        $safeClass = preg_replace('/[^a-z0-9_\-]/i', '_', $classNameSafe);
        $safeYear  = preg_replace('/[^0-9\-]/', '_', $schoolYear);
        $filename  = 'Bulletins_' . $safeClass . '_' . $safeYear . '_' . date('Ymd') . '.xlsx';

        $tempFile = tempnam(sys_get_temp_dir(), 'bulletins_class_');
        try {
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            foreach ($gradeTotalRows as $sheetTitle => $totalRow) {
                injectBulletinImages($tempFile, $templatePath, $totalRow, $sheetTitle);
            }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($tempFile));
            header('Cache-Control: max-age=0');
            header('Pragma: public');

            readfile($tempFile);
        } finally {
            if (file_exists($tempFile)) unlink($tempFile);
        }

    } else {
        // ── Mode individuel ───────────────────────────────────────────────
        $spreadsheet = IOFactory::load($templatePath);
        $sheet       = $spreadsheet->getSheetByName('BULL_N0');
        if (!$sheet) {
            throw new RuntimeException("Feuille 'BULL_N0' introuvable dans le template.");
        }

        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));
        for ($i = $spreadsheet->getSheetCount() - 1; $i >= 0; $i--) {
            if ($spreadsheet->getSheet($i)->getTitle() !== 'BULL_N0') {
                $spreadsheet->removeSheetByIndex($i);
            }
        }
        $sheet->setTitle('Bulletin');

        $gradeTotalRow = fillBulletinSheet($sheet, $studentId, $classId, (int)$periodId, $conn);

        $snq = $conn->prepare('SELECT name FROM users WHERE id = ?');
        $snq->bind_param('s', $studentId);
        $snq->execute();
        $snRow = $snq->get_result()->fetch_assoc();
        $snq->close();
        $studentName = $snRow['name'] ?? $studentId;

        ob_end_clean();

        $safeName = preg_replace('/[^a-z0-9_\-]/i', '_', $studentName);
        $filename = 'bulletin_' . $safeName . '_' . date('Ymd') . '.xlsx';

        $tempFile = tempnam(sys_get_temp_dir(), 'bulletin_');
        try {
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            injectBulletinImages($tempFile, $templatePath, $gradeTotalRow, 'Bulletin');

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($tempFile));
            header('Cache-Control: max-age=0');
            header('Pragma: public');

            readfile($tempFile);
        } finally {
            if (file_exists($tempFile)) unlink($tempFile);
        }
    }

} catch (Throwable $e) {
    error_log('Erreur bulletin XLSX : ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    errorPage($e->getMessage(), 500);
}
