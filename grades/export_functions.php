<?php
// grades/export_functions.php

require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Dompdf\Dompdf;
use Dompdf\Options;

// Dans export_functions.php
function export_csv($conn, $class_id, $period_id) {
    try {
        // Récupération des informations de la classe et de la période
        $class_info = $conn->query("SELECT name FROM classes WHERE id = $class_id")->fetch_assoc();
        $period_info = $conn->query("SELECT name FROM evaluation_periods WHERE id = $period_id")->fetch_assoc();

        // Définir les en-têtes du fichier CSV
        $headers = ['Étudiant', 'Matière', 'Type', 'Note', 'Coefficient', 'Date'];

        // Vider tout output parasite (display_errors, notices…) avant d'envoyer le fichier
        if (ob_get_level()) ob_end_clean();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="releve_notes_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');

        // BOM UTF-8 pour l'ouverture correcte sous Excel
        fwrite($output, "\xEF\xBB\xBF");

        // $escape explicite : évite le Deprecated PHP 8.4
        fputcsv($output, $headers, ";", '"', '\\');

        // Préparation de la requête SQL
        $query = "
        SELECT 
            u.name as student_name,
            c.name as course_name,
            et.name as evaluation_type,
            g.grade,
            c.coefficient,
            g.created_at
        FROM grades g
        JOIN users u ON g.student_id = u.id
        JOIN courses c ON g.course_id = c.id
        JOIN evaluation_types et ON g.evaluation_type_id = et.id
        WHERE JSON_CONTAINS(c.class_id, JSON_QUOTE(?)) 
          AND g.evaluation_period_id = ?
          AND u.class_id = ?
        ORDER BY u.name, c.name, g.created_at;
        ";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("sii", $class_id, $period_id, $class_id);
        $stmt->execute();
        $result = $stmt->get_result();

        // Corps du tableau CSV
        $currentStudent = '';
        $totalGrade = 0;
        $totalCoeff = 0;

        while ($row = $result->fetch_assoc()) {
            // Détection du changement d'étudiant
            if ($currentStudent != $row['student_name']) {
                // Afficher le nom de l'étudiant
                fputcsv($output, [$row['student_name'], '', '', '', '', ''], ";", '"', '\\');
                $currentStudent = $row['student_name'];
            }

            // Ajouter les données de chaque ligne
            fputcsv($output, [
                $row['student_name'],
                $row['course_name'],
                $row['evaluation_type'],
                number_format($row['grade'], 2),
                $row['coefficient'],
                date('d/m/Y', strtotime($row['created_at']))
            ], ";", '"', '\\');
        }

        // Fermer le flux CSV
        fclose($output);

    } catch (Exception $e) {
        error_log("Export CSV error: " . $e->getMessage());
        throw new Exception("Une erreur est survenue lors de l'export CSV");
    }
}


function export_excel($conn, $class_id, $period_id) {
    try {
        // Initialisation du classeur Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Récupération des informations de la classe et de la période
        $class_info = $conn->query("SELECT name FROM classes WHERE id = $class_id")->fetch_assoc();
        $period_info = $conn->query("SELECT name FROM evaluation_periods WHERE id = $period_id")->fetch_assoc();

        // En-têtes de la feuille Excel (M = Note Rattrapage, N = Statut rattrapage)
        $headers = ['Étudiant','', 'Matière','', 'Type','', 'Note','', 'Coefficient','', 'Date','', 'Note Rattrapage', 'Statut ratt.'];
        $sheet->fromArray($headers, NULL, 'A1');

        // Style des en-têtes
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '039BE5']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];

        // Appliquer le style des en-têtes (A à N)
        $sheet->getStyle('A1:N1')->applyFromArray($headerStyle);

        // Préparation de la requête SQL avec jointure rattrapages
        $query = "
        SELECT
            u.name as student_name,
            c.name as course_name,
            et.name as evaluation_type,
            g.grade,
            c.coefficient,
            g.created_at,
            r.grade as rattrapage_grade,
            r.original_average as rattrapage_original_avg,
            r.status as rattrapage_status
        FROM grades g
        JOIN users u ON g.student_id = u.id
        JOIN courses c ON g.course_id = c.id
        JOIN evaluation_types et ON g.evaluation_type_id = et.id
        LEFT JOIN rattrapages r ON r.student_id = g.student_id
            AND r.course_id = g.course_id
            AND r.evaluation_period_id = g.evaluation_period_id
            AND r.status = 'graded'
        WHERE JSON_CONTAINS(c.class_id, JSON_QUOTE(?))
          AND g.evaluation_period_id = ?
          AND u.class_id = ?
        ORDER BY u.name, c.name, g.created_at;
        ";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("sii", $class_id, $period_id, $class_id);
        $stmt->execute();
        $result = $stmt->get_result();

        // Corps du tableau Excel
        $row = 2;
        $currentStudent = '';
        $totalGrade = 0;
        $totalCoeff = 0;

        while ($row_data = $result->fetch_assoc()) {
            // Détection du changement d'étudiant
            if ($currentStudent != $row_data['student_name']) {

                // Afficher le nom de l'étudiant
                $sheet->setCellValue('A'.$row, $row_data['student_name']);
                $sheet->mergeCells('A'.$row.':N'.$row);
                $sheet->getStyle('A'.$row)->getFont()->setBold(true);
                $row++;
                $currentStudent = $row_data['student_name'];
            }

            // Définir la couleur de la note
            $gradeColor = '';
            if ($row_data['grade'] >= 14) {
                $gradeColor = 'C8E6C9';  // Bonnes notes
            } elseif ($row_data['grade'] >= 10) {
                $gradeColor = 'FFF9C4';  // Notes moyennes
            } else {
                $gradeColor = 'FFCDD2';  // Mauvaises notes
            }

            // Ajouter les données pour chaque ligne
            $sheet->setCellValue('A'.$row, $row_data['student_name']);
            $sheet->setCellValue('C'.$row, $row_data['course_name']);
            $sheet->setCellValue('E'.$row, $row_data['evaluation_type']);
            $sheet->setCellValue('G'.$row, number_format($row_data['grade'], 2));
            $sheet->setCellValue('I'.$row, $row_data['coefficient']);
            $sheet->setCellValue('K'.$row, date('d/m/Y', strtotime($row_data['created_at'])));

            // Colonnes rattrapage (M = note brute, N = statut retenu/non retenu)
            if ($row_data['rattrapage_grade'] !== null) {
                $rattGrade   = (float)$row_data['rattrapage_grade'];
                $origAvg     = (float)$row_data['rattrapage_original_avg'];
                $rattRetenu  = $rattGrade > $origAvg;

                $sheet->setCellValue('M'.$row, number_format($rattGrade, 2));
                $rattColor = $rattRetenu ? 'C8E6C9' : 'FFE0B2'; // vert si retenu, orange si non retenu
                $sheet->getStyle('M'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rattColor);

                $sheet->setCellValue('N'.$row, $rattRetenu ? 'Retenu' : 'Non retenu');
                $sheet->getStyle('N'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rattColor);
                $sheet->getStyle('N'.$row)->getFont()->setBold(true);
            } else {
                $sheet->setCellValue('M'.$row, '—');
                $sheet->setCellValue('N'.$row, '—');
            }

            // Appliquer la couleur de fond pour les notes
            $sheet->getStyle('G'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($gradeColor);

            // Calcul des totaux pour l'étudiant
            $totalGrade += $row_data['grade'] * $row_data['coefficient'];
            $totalCoeff += $row_data['coefficient'];
            $row++;
        }


        // Exportation du fichier Excel
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="releve_notes_' . date('Y-m-d') . '.xlsx"');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');

    } catch (Exception $e) {
        error_log("Export Excel error: " . $e->getMessage());
        throw new Exception("Une erreur est survenue lors de l'export Excel");
    }
}




function export_pdf($conn, $class_id, $period_id) {
    ob_start();
    try {
        // Configuration de Dompdf
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        
        $dompdf = new Dompdf($options);

        // Récupération des informations de la classe et de la période
        $class_info = $conn->query("SELECT name FROM classes WHERE id = $class_id")->fetch_assoc();
        $period_info = $conn->query("SELECT name FROM evaluation_periods WHERE id = $period_id")->fetch_assoc();

        // En-tête HTML et styles CSS
        $html = '
        <style>
            body { font-family: DejaVu Sans, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th { background-color: #039be5; color: white; font-weight: bold; text-align: center; }
            td, th { border: 1px solid #ddd; padding: 8px; font-size: 12px; text-align: center; }
            .grade-good { background-color: #c8e6c9; }
            .grade-average { background-color: #fff9c4; }
            .grade-poor { background-color: #ffcdd2; }
            .footer { text-align: center; font-size: 10px; margin-top: 20px; }
            .student-row { background-color: #f5f5f5; font-weight: bold; }
            .average-row { background-color: #e0f7fa; font-weight: bold; }
        </style>
        
        <div class="header">
            <h1>Relevé de Notes</h1>
            <p>
                <strong>Classe:</strong> '.htmlspecialchars($class_info['name']).'<br>
                <strong>Période:</strong> '.htmlspecialchars($period_info['name']).'
            </p>
        </div>';

        // Préparation de la requête SQL
        $query = "
        SELECT 
            u.name as student_name,
            c.name as course_name,
            et.name as evaluation_type,
            g.grade,
            c.coefficient,
            g.created_at
        FROM grades g
        JOIN users u ON g.student_id = u.id
        JOIN courses c ON g.course_id = c.id
        JOIN evaluation_types et ON g.evaluation_type_id = et.id
        WHERE JSON_CONTAINS(c.class_id, JSON_QUOTE(?)) 
          AND g.evaluation_period_id = ?
          AND u.class_id = ? -- Ajout de la condition pour les étudiants
        ORDER BY u.name, c.name, g.created_at;
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sii", $class_id, $period_id, $class_id);  // u.class_id utilise une comparaison simple
        $stmt->execute();
        $result = $stmt->get_result();
        
        

        // Corps du tableau HTML
        $html .= '
        <table>
            <thead>
                <tr>
                    <th>Étudiant</th>
                    <th>Matière</th>
                    <th>Type</th>
                    <th>Note</th>
                    <th>Coefficient</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>';

        $currentStudent = '';

        while ($row = $result->fetch_assoc()) {
            // Détection du changement d'étudiant
            if ($currentStudent != $row['student_name']) {
                // Afficher le nom de l'étudiant
                $html .= '<tr class="student-row"><td colspan="6">'.
                    htmlspecialchars($row['student_name']).'</td></tr>';
                $currentStudent = $row['student_name'];
            }

            // Définir la classe CSS en fonction de la note
            $gradeClass = '';
            if ($row['grade'] >= 14) {
                $gradeClass = 'grade-good';
            } elseif ($row['grade'] >= 10) {
                $gradeClass = 'grade-average';
            } else {
                $gradeClass = 'grade-poor';
            }

            // Ajouter une ligne au tableau
            $html .= '<tr>
                <td>'.htmlspecialchars($row['student_name']).'</td>
                <td>'.htmlspecialchars($row['course_name']).'</td>
                <td>'.htmlspecialchars($row['evaluation_type']).'</td>
                <td class="'.$gradeClass.'">'.number_format($row['grade'], 2).'/20</td>
                <td>'.htmlspecialchars($row['coefficient']).'</td>
                <td>'.date('d/m/Y', strtotime($row['created_at'])).'</td>
            </tr>';
        }


        $html .= '</tbody></table>';

        // Pied de page
        $html .= '<div class="footer">
            <p>Document généré le '.date('d/m/Y à H:i').' - Université Virtuelle</p>
        </div>';

        // Génération du PDF
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        ob_end_clean();
        $dompdf->stream("notes_" . date('Y-m-d') . ".pdf", ["Attachment" => true]);

    } catch (Exception $e) {
        ob_end_clean();
        error_log("Export PDF error: " . $e->getMessage());
        throw new Exception("Une erreur est survenue lors de l'export PDF");
    }
}
