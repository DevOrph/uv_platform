<?php
/**
 * Fonctions centralisées pour le module de paiement
 * Université Virtuelle - Système de gestion des paiements
 */
require_once __DIR__ . '/../includes/super_admin.php';

class PaymentManager {
    private $conn;
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }
    
    /**
     * Vérifier les permissions de paiement pour un utilisateur
     */
    public function checkPaymentPermission($user_id) {
        // Un super administrateur a toujours la permission
        if (is_super_admin($this->conn, $user_id)) {
            return true;
        }
        
        $query = "SELECT * FROM payment_permissions 
                  WHERE user_id = ? AND is_active = 1 
                  AND (expires_at IS NULL OR expires_at > NOW())";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->num_rows > 0;
    }
    
    /**
     * Obtenir les statistiques de paiement
     */
    public function getPaymentStats() {
        $stats = [];
        
        // Total encaissé
        $query = "SELECT COALESCE(SUM(amount_paid), 0) as total 
                  FROM student_payments WHERE status = 'validated'";
        $result = $this->conn->query($query);
        $stats['total_collected'] = $result->fetch_assoc()['total'];
        
        // Total en attente
        $query = "SELECT COALESCE(SUM(tf.total_amount - COALESCE(paid.total_paid, 0)), 0) as pending
                  FROM tuition_fees tf 
                  LEFT JOIN (
                      SELECT tuition_fee_id, SUM(amount_paid) as total_paid 
                      FROM student_payments 
                      WHERE status = 'validated' 
                      GROUP BY tuition_fee_id
                  ) paid ON tf.id = paid.tuition_fee_id 
                  WHERE tf.academic_year = '" . ANNEE_ACADEMIQUE_COURANTE . "'";
        $result = $this->conn->query($query);
        $stats['total_pending'] = $result->fetch_assoc()['pending'];
        
        // Étudiants à jour
        $query = "SELECT COUNT(*) as count FROM payment_status_view WHERE payment_status = 'paid'";
        $result = $this->conn->query($query);
        $stats['students_paid'] = $result->fetch_assoc()['count'];
        
        // Étudiants en retard
        $query = "SELECT COUNT(*) as count FROM payment_status_view WHERE payment_status = 'overdue'";
        $result = $this->conn->query($query);
        $stats['overdue_students'] = $result->fetch_assoc()['count'];
        
        return $stats;
    }
    
    /**
     * Ajouter un nouveau paiement
     */
    public function addPayment($data, $recorded_by) {
        try {
            $this->conn->begin_transaction();
            
            // Validation des données
            $this->validatePaymentData($data);
            
            // Récupérer l'ID des frais de scolarité
            $tuition_fee_id = $this->getTuitionFeeId($data['student_id']);
            if (!$tuition_fee_id) {
                throw new Exception("Aucun frais de scolarité trouvé pour cet étudiant");
            }
            
            // Générer numéro de reçu si absent
            if (empty($data['receipt_number'])) {
                $data['receipt_number'] = $this->generateReceiptNumber();
            }
            
            // Insérer le paiement
            $query = "INSERT INTO student_payments 
                      (student_id, tuition_fee_id, amount_paid, payment_method, 
                       reference_number, description, receipt_number, payment_type, 
                       installment_number, recorded_by, validated_by, validation_date, status) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'validated')";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("sidsssssiss", 
                $data['student_id'], $tuition_fee_id, $data['amount'], $data['payment_method'],
                $data['reference_number'], $data['description'], $data['receipt_number'], 
                $data['payment_type'], $data['installment_number'], $recorded_by, $recorded_by
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Erreur lors de l'enregistrement du paiement");
            }
            
            $payment_id = $this->conn->insert_id;
            
            // Ajouter l'historique
            $this->addPaymentHistory($payment_id, 'CREATE', $recorded_by, 
                "Paiement de {$data['amount']} FCFA enregistré - Méthode: {$data['payment_method']}");
            
            $this->conn->commit();
            return ['success' => true, 'payment_id' => $payment_id, 'receipt_number' => $data['receipt_number']];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Obtenir le statut de paiement d'un étudiant
     */
    public function getStudentPaymentStatus($student_id) {
        $query = "SELECT * FROM payment_status_view WHERE student_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        return null;
    }
    
    /**
     * Obtenir l'historique des paiements d'un étudiant
     */
    public function getStudentPaymentHistory($student_id) {
        $query = "SELECT sp.*, tf.academic_year, tf.total_amount as total_fees
                  FROM student_payments sp
                  JOIN tuition_fees tf ON sp.tuition_fee_id = tf.id
                  WHERE sp.student_id = ? AND sp.status = 'validated'
                  ORDER BY sp.payment_date DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $payments = [];
        while ($row = $result->fetch_assoc()) {
            $payments[] = $row;
        }
        return $payments;
    }
    
    /**
     * Obtenir tous les étudiants avec leur statut de paiement
     */
    public function getAllStudentsPaymentStatus($filters = []) {
        $query = "SELECT * FROM payment_status_view WHERE 1=1";
        $params = [];
        $types = "";
        
        // Filtres optionnels
        if (!empty($filters['class_id'])) {
            $query .= " AND class_id = ?";
            $params[] = $filters['class_id'];
            $types .= "i";
        }
        
        if (!empty($filters['payment_status'])) {
            $query .= " AND payment_status = ?";
            $params[] = $filters['payment_status'];
            $types .= "s";
        }
        
        if (!empty($filters['search'])) {
            $query .= " AND student_name LIKE ?";
            $params[] = "%" . $filters['search'] . "%";
            $types .= "s";
        }
        
        $query .= " ORDER BY student_name";
        
        $stmt = $this->conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }
        return $students;
    }
    
    /**
     * Créer des frais de scolarité pour une classe
     */
    public function createTuitionFees($data, $created_by) {
        try {
            // Validation des données
            $this->validateTuitionData($data);
            
            // Calculer le montant total
            $total_amount = $data['registration_fee'] + $data['tuition_fee'] + 
                           $data['insurance_fee'] + $data['library_fee'] + 
                           $data['practical_fee'] + $data['other_fees'];
            
            if ($total_amount <= 0) {
                throw new Exception("Le montant total ne peut pas être 0");
            }
            
            // Vérifier si des frais existent déjà
            $check_query = "SELECT id FROM tuition_fees WHERE class_id = ? AND academic_year = ?";
            $stmt = $this->conn->prepare($check_query);
            $stmt->bind_param("is", $data['class_id'], $data['academic_year']);
            $stmt->execute();
            
            if ($stmt->get_result()->num_rows > 0) {
                throw new Exception("Des frais existent déjà pour cette classe et cette année académique");
            }
            
            // Insérer les nouveaux frais
            $query = "INSERT INTO tuition_fees 
                      (class_id, academic_year, total_amount, registration_fee, tuition_fee, 
                       insurance_fee, library_fee, practical_fee, other_fees, description, 
                       due_date, installments_count, created_by) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("isddddddssis", 
                $data['class_id'], $data['academic_year'], $total_amount,
                $data['registration_fee'], $data['tuition_fee'], $data['insurance_fee'],
                $data['library_fee'], $data['practical_fee'], $data['other_fees'],
                $data['description'], $data['due_date'], $data['installments_count'], $created_by
            );
            
            if ($stmt->execute()) {
                return ['success' => true, 'tuition_fee_id' => $this->conn->insert_id];
            } else {
                throw new Exception("Erreur lors de la création des frais");
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // Méthodes privées utilitaires
    
    private function validatePaymentData($data) {
        $required_fields = ['student_id', 'amount', 'payment_method', 'payment_type'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                throw new Exception("Le champ $field est requis");
            }
        }
        
        if ($data['amount'] <= 0) {
            throw new Exception("Le montant doit être supérieur à 0");
        }
    }
    
    private function validateTuitionData($data) {
        $required_fields = ['class_id', 'academic_year'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                throw new Exception("Le champ $field est requis");
            }
        }
    }
    
    private function getTuitionFeeId($student_id) {
        $query = "SELECT tf.id FROM tuition_fees tf 
                  JOIN users u ON u.class_id = tf.class_id 
                  WHERE u.id = ? AND tf.academic_year = '" . ANNEE_ACADEMIQUE_COURANTE . "'";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc()['id'];
        }
        return null;
    }
    
    private function generateReceiptNumber() {
        return 'REC-' . date('Ymd') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    }
    
    private function addPaymentHistory($payment_id, $action, $performed_by, $details) {
        $query = "INSERT INTO payment_history (payment_id, action, performed_by, details) 
                  VALUES (?, ?, ?, ?)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("isss", $payment_id, $action, $performed_by, $details);
        $stmt->execute();
    }
    
    /**
     * Accorder une permission de paiement
     */
    public function grantPaymentPermission($user_id, $duration_days, $notes, $granted_by) {
        if (!is_super_admin($this->conn, $granted_by)) {
            return ['success' => false, 'error' => 'Seul un super administrateur peut accorder des permissions'];
        }
        
        $expires_at = null;
        if ($duration_days) {
            $expires_at = date('Y-m-d H:i:s', strtotime("+$duration_days days"));
        }
        
        $query = "INSERT INTO payment_permissions (user_id, granted_by, expires_at, notes) 
                  VALUES (?, ?, ?, ?) 
                  ON DUPLICATE KEY UPDATE 
                  expires_at = VALUES(expires_at), is_active = 1, 
                  notes = VALUES(notes), granted_at = NOW()";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ssss", $user_id, $granted_by, $expires_at, $notes);
        
        if ($stmt->execute()) {
            return ['success' => true];
        } else {
            return ['success' => false, 'error' => 'Erreur lors de l\'octroi de la permission'];
        }
    }
    
    /**
     * Révoquer une permission de paiement
     */
    public function revokePaymentPermission($user_id, $revoker) {
        if (!is_super_admin($this->conn, $revoker)) {
            return ['success' => false, 'error' => 'Seul un super administrateur peut révoquer des permissions'];
        }
        
        $query = "UPDATE payment_permissions SET is_active = 0 WHERE user_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $user_id);
        
        if ($stmt->execute()) {
            return ['success' => true];
        } else {
            return ['success' => false, 'error' => 'Erreur lors de la révocation de la permission'];
        }
    }
}

/**
 * Fonctions utilitaires globales
 */

function formatCurrency($amount) {
    return number_format($amount, 0, ',', ' ') . ' FCFA';
}

function getPaymentStatusBadge($status) {
    $badges = [
        'paid' => '<span class="status-badge status-paid"><i class="fas fa-check-circle"></i> Payé</span>',
        'partial' => '<span class="status-badge status-partial"><i class="fas fa-clock"></i> Partiel</span>',
        'unpaid' => '<span class="status-badge status-unpaid"><i class="fas fa-times-circle"></i> Non payé</span>',
        'overdue' => '<span class="status-badge status-overdue"><i class="fas fa-exclamation-circle"></i> En retard</span>'
    ];
    
    return $badges[$status] ?? $badges['unpaid'];
}

function calculatePaymentProgress($paid, $total) {
    if ($total == 0) return 0;
    return min(100, round(($paid / $total) * 100, 2));
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

?>