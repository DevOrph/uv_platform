/**
 * JavaScript pour l'interface de paiement étudiant
 * Université Virtuelle
 */

class StudentPaymentManager {
    constructor() {
        this.apiBase = '../api/student_payment_api.php';
        this.csrfToken = window.CSRF_TOKEN || '';
        this.paymentData = null;
        this.paymentHistory = [];
        
        this.initializeEventListeners();
    }

    initializeEventListeners() {
        // Formulaire de contact
        const contactForm = document.getElementById('contactForm');
        if (contactForm) {
            contactForm.addEventListener('submit', (e) => this.handleContactSubmit(e));
        }

        // Fermeture des modales en cliquant à l'extérieur
        window.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) {
                e.target.style.display = 'none';
            }
        });
    }

    /**
     * Charger toutes les données de paiement
     */
    async loadPaymentData() {
        this.showLoading(true);
        
        try {
            await Promise.all([
                this.loadPaymentStatus(),
                this.loadPaymentHistory(),
                this.loadFeeBreakdown()
            ]);
        } catch (error) {
            this.showError('Erreur lors du chargement des données: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }

    /**
     * Charger le statut de paiement
     */
    async loadPaymentStatus() {
        try {
            const response = await this.apiCall('get_payment_status');
            
            if (response.success && response.data) {
                this.paymentData = response.data;
                this.updatePaymentStatusDisplay();
            } else {
                throw new Error(response.error || 'Erreur lors du chargement du statut');
            }
        } catch (error) {
            console.error('Erreur loadPaymentStatus:', error);
            this.showError('Impossible de charger le statut de paiement');
        }
    }

    /**
     * Charger l'historique des paiements
     */
    async loadPaymentHistory() {
        try {
            const response = await this.apiCall('get_payment_history');
            
            if (response.success) {
                this.paymentHistory = response.data || [];
                this.updatePaymentHistoryDisplay();
            } else {
                throw new Error(response.error || 'Erreur lors du chargement de l\'historique');
            }
        } catch (error) {
            console.error('Erreur loadPaymentHistory:', error);
            this.showError('Impossible de charger l\'historique des paiements');
        }
    }

    /**
     * Charger le détail des frais
     */
    async loadFeeBreakdown() {
        try {
            const response = await this.apiCall('get_fee_breakdown');
            
            if (response.success && response.data) {
                this.updateFeeBreakdownDisplay(response.data);
            } else {
                console.warn('Pas de détail des frais disponible');
            }
        } catch (error) {
            console.error('Erreur loadFeeBreakdown:', error);
        }
    }

    /**
     * Mettre à jour l'affichage du statut de paiement
     */
    updatePaymentStatusDisplay() {
        if (!this.paymentData) return;

        const data = this.paymentData;
        
        // Montants
        document.getElementById('totalAmount').textContent = this.formatCurrency(data.total_amount);
        document.getElementById('paidAmount').textContent = this.formatCurrency(data.total_paid);
        document.getElementById('remainingAmount').textContent = this.formatCurrency(data.remaining_balance);
        document.getElementById('progressPercent').textContent = data.payment_percentage + '%';
        
        // Barre de progression
        const progressBar = document.getElementById('progressBar');
        progressBar.style.width = data.payment_percentage + '%';
        progressBar.textContent = data.payment_percentage + '%';
        
        // Badge de statut
        const statusBadge = document.getElementById('paymentStatusBadge');
        statusBadge.className = `status-badge status-${data.payment_status}`;
        statusBadge.innerHTML = this.getStatusIcon(data.payment_status) + ' ' + this.getStatusText(data.payment_status);
        
        // Année académique
        document.getElementById('academicYear').textContent = data.academic_year;
    }

    /**
     * Mettre à jour l'affichage de l'historique des paiements
     */
    updatePaymentHistoryDisplay() {
        const container = document.getElementById('paymentHistory');
        
        if (this.paymentHistory.length === 0) {
            container.innerHTML = `
                <div style="text-align: center; padding: 40px; color: #ccc;">
                    <i class="fas fa-money-bill-wave" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                    <h3>Aucun paiement enregistré</h3>
                    <p>Vous n'avez encore effectué aucun paiement pour cette année académique.</p>
                </div>
            `;
            return;
        }

        container.innerHTML = this.paymentHistory.map(payment => `
            <div class="payment-item" onclick="showPaymentDetails('${payment.id}')">
                <div class="payment-info">
                    <div class="payment-date" style="font-weight: bold; color: var(--accent-color);">
                        ${payment.payment_date}
                    </div>
                    <div class="payment-details" style="font-size: 14px; color: #ccc; margin-top: 5px;">
                        <span class="payment-method" style="background: var(--info-color); color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px;">
                            ${payment.payment_method}
                        </span>
                        ${payment.reference_number ? `<span style="margin-left: 10px;">Réf: ${payment.reference_number}</span>` : ''}
                    </div>
                </div>
                <div class="payment-amount" style="font-size: 18px; font-weight: bold; color: var(--success-color);">
                    ${this.formatCurrency(payment.amount_paid)}
                </div>
                <i class="fas fa-chevron-right" style="color: var(--accent-color);"></i>
            </div>
        `).join('');
    }

    /**
     * Mettre à jour l'affichage du détail des frais
     */
    updateFeeBreakdownDisplay(feeData) {
        const container = document.getElementById('feeGrid');
        
        const fees = [
            { name: 'Frais d\'Inscription', amount: feeData.breakdown.registration_fee, icon: 'fas fa-edit' },
            { name: 'Frais de Scolarité', amount: feeData.breakdown.tuition_fee, icon: 'fas fa-graduation-cap' },
            { name: 'Frais d\'Assurance', amount: feeData.breakdown.insurance_fee, icon: 'fas fa-shield-alt' },
            { name: 'Frais de Bibliothèque', amount: feeData.breakdown.library_fee, icon: 'fas fa-book' },
            { name: 'Frais de Travaux Pratiques', amount: feeData.breakdown.practical_fee, icon: 'fas fa-flask' },
            { name: 'Autres Frais', amount: feeData.breakdown.other_fees, icon: 'fas fa-plus' }
        ];

        container.innerHTML = `
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                ${fees.map(fee => `
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px; background: rgba(255, 255, 255, 0.05); border-radius: 8px; border-left: 4px solid var(--accent-color);">
                        <span style="font-weight: 500;">
                            <i class="${fee.icon}"></i> ${fee.name}
                        </span>
                        <span style="font-weight: bold; color: var(--accent-color);">
                            ${this.formatCurrency(fee.amount)}
                        </span>
                    </div>
                `).join('')}
            </div>
        `;
    }

    /**
     * Afficher les détails d'un paiement
     */
    async showPaymentDetails(paymentId) {
        this.showLoading(true);
        
        try {
            const response = await this.apiCall(`get_payment_details&payment_id=${paymentId}`);
            
            if (response.success && response.data) {
                const payment = response.data;
                const content = document.getElementById('paymentDetailsContent');
                
                content.innerHTML = `
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                        <div>
                            <strong style="color: var(--accent-color);">Date de Paiement:</strong><br>
                            ${payment.payment_date}
                        </div>
                        <div>
                            <strong style="color: var(--accent-color);">Montant:</strong><br>
                            <span style="font-size: 18px; color: var(--success-color); font-weight: bold;">${this.formatCurrency(payment.amount_paid)}</span>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                        <div>
                            <strong style="color: var(--accent-color);">Méthode:</strong><br>
                            ${payment.payment_method}
                        </div>
                        <div>
                            <strong style="color: var(--accent-color);">Référence:</strong><br>
                            ${payment.reference_number || 'N/A'}
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                        <div>
                            <strong style="color: var(--accent-color);">Type:</strong><br>
                            ${payment.payment_type}
                        </div>
                        <div>
                            <strong style="color: var(--accent-color);">Statut:</strong><br>
                            <span class="status-badge status-paid">Validé</span>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                        <div>
                            <strong style="color: var(--accent-color);">N° Reçu:</strong><br>
                            ${payment.receipt_number}
                        </div>
                        <div>
                            <strong style="color: var(--accent-color);">Enregistré par:</strong><br>
                            ${payment.recorded_by}
                        </div>
                    </div>
                    
                    ${payment.description ? `
                        <div style="margin-bottom: 20px;">
                            <strong style="color: var(--accent-color);">Description:</strong><br>
                            ${payment.description}
                        </div>
                    ` : ''}
                    
                    <div style="text-align: center; margin-top: 25px;">
                        <button class="btn btn-success" onclick="downloadReceipt('${payment.id}')">
                            <i class="fas fa-download"></i> Télécharger Reçu
                        </button>
                        <button class="btn btn-primary" onclick="printReceipt('${payment.id}')">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                    </div>
                `;
                
                document.getElementById('paymentDetailsModal').style.display = 'block';
            } else {
                this.showError(response.error || 'Impossible de charger les détails du paiement');
            }
        } catch (error) {
            this.showError('Erreur lors du chargement des détails: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }

    /**
     * Télécharger un reçu de paiement
     */
    async downloadReceipt(paymentId) {
        this.showLoading(true);
        
        try {
            const response = await this.apiCall(`generate_receipt&payment_id=${paymentId}`);
            
            if (response.success && response.data) {
                // Créer et télécharger le fichier HTML
                const blob = new Blob([response.data.receipt_html], { type: 'text/html' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `recu_${response.data.receipt_number}.html`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                
                this.showSuccess('Reçu téléchargé avec succès');
            } else {
                this.showError(response.error || 'Impossible de générer le reçu');
            }
        } catch (error) {
            this.showError('Erreur lors du téléchargement: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }

    /**
     * Imprimer un reçu de paiement
     */
    async printReceipt(paymentId) {
        try {
            const response = await this.apiCall(`generate_receipt&payment_id=${paymentId}`);
            
            if (response.success && response.data) {
                const printWindow = window.open('', '_blank');
                printWindow.document.write(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>Reçu ${response.data.receipt_number}</title>
                        <style>
                            body { font-family: Arial, sans-serif; margin: 20px; }
                            @media print { 
                                body { margin: 0; }
                                .no-print { display: none; }
                            }
                        </style>
                    </head>
                    <body>
                        ${response.data.receipt_html}
                        <div class="no-print" style="text-align: center; margin-top: 20px;">
                            <button onclick="window.print()">Imprimer</button>
                            <button onclick="window.close()">Fermer</button>
                        </div>
                    </body>
                    </html>
                `);
                printWindow.document.close();
                printWindow.focus();
            } else {
                this.showError(response.error || 'Impossible de générer le reçu');
            }
        } catch (error) {
            this.showError('Erreur lors de l\'impression: ' + error.message);
        }
    }

    /**
     * Gérer la soumission du formulaire de contact
     */
    async handleContactSubmit(event) {
        event.preventDefault();
        
        const formData = new FormData(event.target);
        formData.append('csrf_token', this.csrfToken);
        
        this.showLoading(true);
        
        try {
            const response = await fetch(this.apiBase + '?action=contact_finance', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showSuccess(result.message);
                event.target.reset();
                this.closeModal('contactModal');
            } else {
                this.showError(result.error || 'Erreur lors de l\'envoi du message');
            }
        } catch (error) {
            this.showError('Erreur lors de l\'envoi: ' + error.message);
        } finally {
            this.showLoading(false);
        }
    }

    /**
     * Appel API générique
     */
    async apiCall(action) {
        const response = await fetch(`${this.apiBase}?action=${action}`);
        
        if (!response.ok) {
            throw new Error(`Erreur HTTP: ${response.status}`);
        }
        
        return await response.json();
    }

    /**
     * Afficher/masquer le spinner de chargement
     */
    showLoading(show) {
        const spinner = document.getElementById('loadingSpinner');
        if (spinner) {
            spinner.style.display = show ? 'block' : 'none';
        }
    }

    /**
     * Afficher un message de succès
     */
    showSuccess(message) {
        const alert = document.getElementById('successAlert');
        const messageSpan = document.getElementById('successMessage');
        
        messageSpan.textContent = message;
        alert.style.display = 'flex';
        
        setTimeout(() => {
            alert.style.display = 'none';
        }, 5000);
    }

    /**
     * Afficher un message d'erreur
     */
    showError(message) {
        const alert = document.getElementById('errorAlert');
        const messageSpan = document.getElementById('errorMessage');
        
        messageSpan.textContent = message;
        alert.style.display = 'flex';
        
        setTimeout(() => {
            alert.style.display = 'none';
        }, 7000);
    }

    /**
     * Fermer une modale
     */
    closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }

    /**
     * Formater une devise
     */
    formatCurrency(amount) {
        return new Intl.NumberFormat('fr-FR').format(amount) + ' FCFA';
    }

    /**
     * Obtenir l'icône pour un statut
     */
    getStatusIcon(status) {
        const icons = {
            'paid': '<i class="fas fa-check-circle"></i>',
            'partial': '<i class="fas fa-clock"></i>',
            'unpaid': '<i class="fas fa-times-circle"></i>',
            'overdue': '<i class="fas fa-exclamation-circle"></i>'
        };
        return icons[status] || icons['unpaid'];
    }

    /**
     * Obtenir le texte pour un statut
     */
    getStatusText(status) {
        const texts = {
            'paid': 'Paiement Complet',
            'partial': 'Paiement Partiel',
            'unpaid': 'Non Payé',
            'overdue': 'Paiement en Retard'
        };
        return texts[status] || texts['unpaid'];
    }
}

// Fonctions globales pour l'interface
function showPaymentDetails(paymentId) {
    window.paymentManager.showPaymentDetails(paymentId);
}

function downloadReceipt(paymentId) {
    window.paymentManager.downloadReceipt(paymentId);
}

function printReceipt(paymentId) {
    window.paymentManager.printReceipt(paymentId);
}

function closeModal(modalId) {
    window.paymentManager.closeModal(modalId);
}

function downloadPaymentHistory() {
    // Générer un CSV de l'historique des paiements
    if (!window.paymentManager.paymentHistory.length) {
        window.paymentManager.showError('Aucun historique à télécharger');
        return;
    }

    const headers = ['Date', 'Montant', 'Méthode', 'Référence', 'Type', 'Statut'];
    const csvContent = [
        headers.join(','),
        ...window.paymentManager.paymentHistory.map(payment => [
            payment.payment_date,
            payment.amount_paid,
            payment.payment_method,
            payment.reference_number || '',
            payment.payment_type,
            payment.status
        ].join(','))
    ].join('\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `historique_paiements_${new Date().toISOString().split('T')[0]}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function downloadAllReceipts() {
    window.paymentManager.showSuccess('Fonctionnalité en cours de développement');
}

function viewPaymentPlan() {
    window.paymentManager.showSuccess('Fonctionnalité en cours de développement');
}

function contactFinance() {
    document.getElementById('contactModal').style.display = 'block';
}

function loadPaymentData() {
    window.paymentManager.loadPaymentData();
}

// Initialisation au chargement de la page
document.addEventListener('DOMContentLoaded', function() {
    window.paymentManager = new StudentPaymentManager();
    loadPaymentData();
});