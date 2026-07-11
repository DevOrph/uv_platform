<?php
/**
 * API pour la gestion des paiements côté étudiant
 * Université Virtuelle
 */

session_start();
require_once '../includes/db_connect.php';
require_once 'payment_functions.php';

// Vérification de l'authentification
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autorisé']);
    exit();
}

// Validation CSRF pour les requêtes POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token CSRF invalide']);
        exit();
    }
}

header('Content-Type: application/json');

$paymentManager = new PaymentManager($conn);
$student_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_payment_status':
            $status = $paymentManager->getStudentPaymentStatus($student_id);
            if ($status) {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'student_name' => $status['student_name'],
                        'class_name' => $status['class_name'],
                        'academic_year' => $status['academic_year'],
                        'total_amount' => $status['total_amount'],
                        'total_paid' => $status['total_paid'],
                        'remaining_balance' => $status['remaining_balance'],
                        'payment_status' => $status['payment_status'],
                        'payment_percentage' => $status['payment_percentage'],
                        'due_date' => $status['due_date'],
                        'installments_count' => $status['installments_count'],
                        'fee_breakdown' => [
                            'registration_fee' => $status['registration_fee'],
                            'tuition_fee' => $status['tuition_fee'],
                            'insurance_fee' => $status['insurance_fee'],
                            'library_fee' => $status['library_fee'],
                            'practical_fee' => $status['practical_fee'],
                            'other_fees' => $status['other_fees']
                        ]
                    ]
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Aucune information de paiement trouvée'
                ]);
            }
            break;
            
        case 'get_payment_history':
            $history = $paymentManager->getStudentPaymentHistory($student_id);
            echo json_encode([
                'success' => true,
                'data' => array_map(function($payment) {
                    return [
                        'id' => $payment['id'],
                        'payment_date' => date('d/m/Y H:i', strtotime($payment['payment_date'])),
                        'amount_paid' => $payment['amount_paid'],
                        'payment_method' => $payment['payment_method'],
                        'reference_number' => $payment['reference_number'],
                        'receipt_number' => $payment['receipt_number'],
                        'payment_type' => $payment['payment_type'],
                        'description' => $payment['description'],
                        'status' => $payment['status']
                    ];
                }, $history)
            ]);
            break;
            
        case 'get_payment_details':
            $payment_id = $_GET['payment_id'] ?? '';
            if (empty($payment_id)) {
                throw new Exception('ID de paiement requis');
            }
            
            // Vérifier que le paiement appartient à l'étudiant connecté
            $query = "SELECT sp.*, tf.academic_year, u.name as recorder_name
                      FROM student_payments sp
                      JOIN tuition_fees tf ON sp.tuition_fee_id = tf.id
                      LEFT JOIN users u ON sp.recorded_by = u.id
                      WHERE sp.id = ? AND sp.student_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("is", $payment_id, $student_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $payment = $result->fetch_assoc();
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'id' => $payment['id'],
                        'payment_date' => date('d/m/Y H:i', strtotime($payment['payment_date'])),
                        'amount_paid' => $payment['amount_paid'],
                        'payment_method' => $payment['payment_method'],
                        'reference_number' => $payment['reference_number'],
                        'receipt_number' => $payment['receipt_number'],
                        'payment_type' => $payment['payment_type'],
                        'description' => $payment['description'],
                        'status' => $payment['status'],
                        'academic_year' => $payment['academic_year'],
                        'recorded_by' => $payment['recorder_name'] ?? 'Système'
                    ]
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Paiement non trouvé'
                ]);
            }
            break;
            
        case 'generate_receipt':
            $payment_id = $_GET['payment_id'] ?? '';
            if (empty($payment_id)) {
                throw new Exception('ID de paiement requis');
            }
            
            // Vérifier que le paiement appartient à l'étudiant connecté
            $query = "SELECT sp.*, tf.academic_year, u.name as student_name, c.name as class_name
                      FROM student_payments sp
                      JOIN tuition_fees tf ON sp.tuition_fee_id = tf.id
                      JOIN users u ON sp.student_id = u.id
                      JOIN classes c ON u.class_id = c.id
                      WHERE sp.id = ? AND sp.student_id = ? AND sp.status = 'validated'";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("is", $payment_id, $student_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $payment = $result->fetch_assoc();
                
                // Générer le reçu (HTML ou PDF)
                $receipt_html = generateReceiptHTML($payment);
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'receipt_html' => $receipt_html,
                        'receipt_number' => $payment['receipt_number']
                    ]
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Paiement non trouvé ou non validé'
                ]);
            }
            break;
            
        case 'get_fee_breakdown':
            $query = "SELECT tf.*, c.name as class_name
                      FROM tuition_fees tf
                      JOIN users u ON u.class_id = tf.class_id
                      JOIN classes c ON tf.class_id = c.id
                      WHERE u.id = ? AND tf.academic_year = '" . ANNEE_ACADEMIQUE_COURANTE . "'";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $fees = $result->fetch_assoc();
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'class_name' => $fees['class_name'],
                        'academic_year' => $fees['academic_year'],
                        'total_amount' => $fees['total_amount'],
                        'due_date' => $fees['due_date'],
                        'installments_count' => $fees['installments_count'],
                        'breakdown' => [
                            'registration_fee' => $fees['registration_fee'],
                            'tuition_fee' => $fees['tuition_fee'],
                            'insurance_fee' => $fees['insurance_fee'],
                            'library_fee' => $fees['library_fee'],
                            'practical_fee' => $fees['practical_fee'],
                            'other_fees' => $fees['other_fees']
                        ],
                        'description' => $fees['description']
                    ]
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Aucun frais de scolarité configuré'
                ]);
            }
            break;
            
        case 'get_csrf_token':
            echo json_encode([
                'success' => true,
                'csrf_token' => generateCSRFToken()
            ]);
            break;
            
        case 'contact_finance':
            // Enregistrer une demande de contact avec le service financier
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Méthode non autorisée');
            }
            
            $subject = $_POST['subject'] ?? '';
            $message = $_POST['message'] ?? '';
            
            if (empty($subject) || empty($message)) {
                throw new Exception('Sujet et message requis');
            }
            
            // Enregistrer la demande dans la base de données
            $query = "INSERT INTO finance_contacts (student_id, subject, message, created_at) 
                      VALUES (?, ?, ?, NOW())";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sss", $student_id, $subject, $message);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Votre demande a été envoyée au service financier'
                ]);
            } else {
                throw new Exception('Erreur lors de l\'envoi de la demande');
            }
            break;
            
        default:
            throw new Exception('Action non reconnue');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Générer le HTML d'un reçu de paiement
 */
function generateReceiptHTML($payment) {
    $html = '
    <div class="receipt-container" style="max-width: 600px; margin: 0 auto; font-family: Arial, sans-serif;">
        <div class="receipt-header" style="text-align: center; border-bottom: 2px solid #051e34; padding-bottom: 20px; margin-bottom: 20px;">
            <h2 style="color: #051e34; margin: 0;">Université Virtuelle</h2>
            <h3 style="color: #039be5; margin: 10px 0;">REÇU DE PAIEMENT</h3>
            <p style="margin: 5px 0;">N° ' . htmlspecialchars($payment['receipt_number']) . '</p>
        </div>
        
        <div class="receipt-body">
            <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
                <div>
                    <strong>Étudiant:</strong> ' . htmlspecialchars($payment['student_name']) . '<br>
                    <strong>Classe:</strong> ' . htmlspecialchars($payment['class_name']) . '<br>
                    <strong>Année académique:</strong> ' . htmlspecialchars($payment['academic_year']) . '
                </div>
                <div style="text-align: right;">
                    <strong>Date:</strong> ' . date('d/m/Y H:i', strtotime($payment['payment_date'])) . '<br>
                    <strong>Statut:</strong> <span style="color: #2ecc71;">Validé</span>
                </div>
            </div>
            
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <tr style="background-color: #f8f9fa;">
                    <th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Description</th>
                    <th style="border: 1px solid #ddd; padding: 10px; text-align: right;">Montant</th>
                </tr>
                <tr>
                    <td style="border: 1px solid #ddd; padding: 10px;">
                        ' . ucfirst($payment['payment_type']) . '
                        ' . ($payment['description'] ? '<br><small>' . htmlspecialchars($payment['description']) . '</small>' : '') . '
                    </td>
                    <td style="border: 1px solid #ddd; padding: 10px; text-align: right; font-weight: bold;">
                        ' . number_format($payment['amount_paid'], 0, ',', ' ') . ' FCFA
                    </td>
                </tr>
            </table>
            
            <div style="margin-bottom: 20px;">
                <strong>Méthode de paiement:</strong> ' . htmlspecialchars($payment['payment_method']) . '<br>
                ' . ($payment['reference_number'] ? '<strong>Référence:</strong> ' . htmlspecialchars($payment['reference_number']) . '<br>' : '') . '
            </div>
            
            <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
                <p style="margin: 0; font-size: 12px; color: #666;">
                    Ce reçu est généré automatiquement et fait foi de paiement.<br>
                    Pour toute question, contactez le service financier.
                </p>
            </div>
        </div>
    </div>';
    
    return $html;
}

$conn->close();
?>