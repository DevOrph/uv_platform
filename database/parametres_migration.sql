-- Migration : table parametres
-- Permet de configurer l'année académique courante (et d'autres paramètres)
-- depuis l'interface admin sans modifier le code source.
-- À exécuter une seule fois sur la base de données.

CREATE TABLE IF NOT EXISTS parametres (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    cle         VARCHAR(100)   NOT NULL UNIQUE,
    valeur      TEXT           NOT NULL,
    description TEXT           NULL,
    updated_at  DATETIME       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Année académique courante (lue par includes/db_connect.php au chargement)
INSERT INTO parametres (cle, valeur, description)
VALUES ('annee_academique_courante', '2024-2025', 'Année académique active pour les frais de scolarité et les paiements étudiants')
ON DUPLICATE KEY UPDATE valeur = VALUES(valeur);

-- Tarif horaire enseignant (utilisé dans payment_admin.php)
INSERT INTO parametres (cle, valeur, description)
VALUES ('tarif_horaire_enseignant', '7500', 'Tarif horaire par défaut pour les enseignants vacataires (FCFA)')
ON DUPLICATE KEY UPDATE valeur = VALUES(valeur);

-- Taux de retenue IRPP (9.5%)
INSERT INTO parametres (cle, valeur, description)
VALUES ('taux_irpp', '0.095', 'Taux de retenue IRPP appliqué sur les honoraires enseignants')
ON DUPLICATE KEY UPDATE valeur = VALUES(valeur);

-- Informations bancaires (pour les emails de rappel et la page étudiant)
INSERT INTO parametres (cle, valeur, description)
VALUES ('banque_nom', 'BGFI Bank', 'Nom de la banque de l''institution')
ON DUPLICATE KEY UPDATE valeur = VALUES(valeur);

INSERT INTO parametres (cle, valeur, description)
VALUES ('banque_compte', 'XXXXX-XXXXX-XXXXX', 'Numéro de compte bancaire de l''institution')
ON DUPLICATE KEY UPDATE valeur = VALUES(valeur);

INSERT INTO parametres (cle, valeur, description)
VALUES ('airtel_money', '+241 XX XX XX XX', 'Numéro Airtel Money pour les paiements mobiles')
ON DUPLICATE KEY UPDATE valeur = VALUES(valeur);

INSERT INTO parametres (cle, valeur, description)
VALUES ('moov_money', '+241 XX XX XX XX', 'Numéro Moov Money pour les paiements mobiles')
ON DUPLICATE KEY UPDATE valeur = VALUES(valeur);
