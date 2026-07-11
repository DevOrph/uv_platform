#!/bin/bash

###############################################################################
# Script de Maintenance Automatique du Système de Paiements
# Fichier : /cron/update_payment_deadlines.sh
#
# Ce script doit être exécuté quotidiennement pour :
# 1. Mettre à jour les statuts des échéances
# 2. Envoyer des rappels aux étudiants en retard
# 3. Générer des rapports quotidiens
#
# Installation dans crontab :
# crontab -e
# Ajouter : 0 1 * * * /path/to/update_payment_deadlines.sh
###############################################################################

# Configuration
DB_HOST="localhost"
DB_USER="u641337841_DevOrph"
DB_PASS="votre_mot_de_passe"
DB_NAME="u641337841_uv_platform"
LOG_DIR="/var/log/uv_platform"
LOG_FILE="$LOG_DIR/payment_updates_$(date +%Y%m%d).log"
EMAIL_ADMIN="admin@universite-virtuelle.ga"

# Créer le répertoire de logs si nécessaire
mkdir -p "$LOG_DIR"

# Fonction de logging
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

log "========================================="
log "Démarrage de la maintenance des paiements"
log "========================================="

# 1. Mettre à jour les statuts des échéances
log "Mise à jour des statuts des échéances..."
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "CALL UpdateDeadlineStatus();" 2>> "$LOG_FILE"
if [ $? -eq 0 ]; then
    log "✓ Statuts des échéances mis à jour avec succès"
else
    log "✗ ERREUR lors de la mise à jour des statuts"
    exit 1
fi

# 2. Identifier les échéances en retard
log "Identification des échéances en retard..."
OVERDUE_QUERY="
SELECT 
    u.id as student_id,
    u.name as student_name,
    u.email as student_email,
    pd.installment_number,
    pd.due_date,
    pd.amount_due,
    pd.amount_paid,
    (pd.amount_due - pd.amount_paid) as remaining,
    DATEDIFF(CURDATE(), pd.due_date) as days_overdue
FROM payment_deadlines pd
JOIN users u ON pd.student_id = u.id
WHERE pd.status = 'overdue'
AND u.role = 'student'
AND u.blocked = 0
ORDER BY days_overdue DESC, u.name
"

OVERDUE_COUNT=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e "$OVERDUE_QUERY" | wc -l)
log "Nombre d'échéances en retard trouvées : $OVERDUE_COUNT"

# 3. Générer un rapport des échéances en retard
if [ $OVERDUE_COUNT -gt 0 ]; then
    REPORT_FILE="$LOG_DIR/overdue_report_$(date +%Y%m%d).csv"
    log "Génération du rapport des retards : $REPORT_FILE"
    
    echo "ID Étudiant,Nom,Email,N° Échéance,Date Limite,Montant Dû,Montant Payé,Reste à Payer,Jours de Retard" > "$REPORT_FILE"
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e "$OVERDUE_QUERY" | sed 's/\t/,/g' >> "$REPORT_FILE"
    
    log "✓ Rapport généré : $OVERDUE_COUNT étudiant(s) en retard"
fi

# 4. Identifier les échéances à venir (dans les 7 jours)
log "Identification des échéances à venir..."
UPCOMING_QUERY="
SELECT COUNT(*) as count
FROM payment_deadlines pd
JOIN users u ON pd.student_id = u.id
WHERE pd.status IN ('pending', 'partial')
AND pd.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
AND u.role = 'student'
AND u.blocked = 0
"

UPCOMING_COUNT=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e "$UPCOMING_QUERY")
log "Échéances à venir dans les 7 jours : $UPCOMING_COUNT"

# 5. Mettre à jour la table de rappels (si elle existe)
log "Mise à jour des rappels envoyés..."
REMINDER_UPDATE="
UPDATE payment_deadlines 
SET reminder_sent = 1, last_reminder_date = NOW()
WHERE status = 'overdue'
AND (last_reminder_date IS NULL OR last_reminder_date < DATE_SUB(NOW(), INTERVAL 7 DAY))
"

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$REMINDER_UPDATE" 2>> "$LOG_FILE"
if [ $? -eq 0 ]; then
    log "✓ Rappels mis à jour"
else
    log "✗ ERREUR lors de la mise à jour des rappels"
fi

# 6. Statistiques globales
log "Génération des statistiques globales..."
STATS_QUERY="
SELECT 
    COUNT(DISTINCT pd.student_id) as total_students,
    SUM(CASE WHEN pd.status = 'paid' THEN 1 ELSE 0 END) as paid_count,
    SUM(CASE WHEN pd.status = 'partial' THEN 1 ELSE 0 END) as partial_count,
    SUM(CASE WHEN pd.status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
    SUM(CASE WHEN pd.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
    SUM(pd.amount_due) as total_expected,
    SUM(pd.amount_paid) as total_collected
FROM payment_deadlines pd
JOIN users u ON pd.student_id = u.id
WHERE u.role = 'student' AND u.blocked = 0
"

STATS=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e "$STATS_QUERY")
log "Statistiques : $STATS"

# 7. Envoyer un email de rapport à l'admin (optionnel)
if [ $OVERDUE_COUNT -gt 0 ] || [ $UPCOMING_COUNT -gt 10 ]; then
    log "Envoi du rapport par email à l'admin..."
    
    EMAIL_SUBJECT="Rapport Quotidien - Paiements Université Virtuelle - $(date +%d/%m/%Y)"
    EMAIL_BODY="Rapport quotidien du système de paiements
    
Date : $(date '+%d/%m/%Y %H:%M:%S')

RÉSUMÉ :
--------
Échéances en retard : $OVERDUE_COUNT
Échéances à venir (7 jours) : $UPCOMING_COUNT

STATISTIQUES :
-------------
$STATS

Un rapport détaillé des retards est disponible à :
$REPORT_FILE

Pour plus de détails, connectez-vous à :
https://universite-virtuelle.ga/admin/payment_dashboard.php

---
Ce message a été généré automatiquement par le système.
"

    echo "$EMAIL_BODY" | mail -s "$EMAIL_SUBJECT" "$EMAIL_ADMIN"
    
    if [ $? -eq 0 ]; then
        log "✓ Email envoyé à $EMAIL_ADMIN"
    else
        log "✗ ERREUR lors de l'envoi de l'email"
    fi
fi

# 8. Nettoyer les vieux logs (garder 30 jours)
log "Nettoyage des anciens logs..."
find "$LOG_DIR" -name "payment_updates_*.log" -mtime +30 -delete
find "$LOG_DIR" -name "overdue_report_*.csv" -mtime +30 -delete
log "✓ Logs nettoyés"

# 9. Optimiser les tables (une fois par semaine)
if [ $(date +%u) -eq 1 ]; then
    log "Optimisation hebdomadaire des tables..."
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
        OPTIMIZE TABLE payment_deadlines;
        OPTIMIZE TABLE finance_messages;
        OPTIMIZE TABLE student_payments;
    " 2>> "$LOG_FILE"
    log "✓ Tables optimisées"
fi

log "========================================="
log "Maintenance terminée avec succès"
log "========================================="

exit 0