// Récupérer la couleur du fond à partir du localStorage quand la page est chargée
document.addEventListener('DOMContentLoaded', function() {
    let savedColor = localStorage.getItem('backgroundColor');
    if (savedColor) {
        document.body.style.backgroundColor = savedColor;
    }
});

// Initialiser la couleur courante 
let currentColor = localStorage.getItem('backgroundColor') || '#051e34';

// Appliquer une transition fluide pour les changements de couleur
document.body.style.transition = 'background-color 0.5s ease';

// Fonction pour changer la couleur de fond et enregistrer dans le localStorage
function changeBackgroundColor() {
    switch(currentColor) {
        case '#051e34':
            document.body.style.backgroundColor = '#0c2d48';
            currentColor = '#0c2d48';
            break;
        case '#0c2d48':
            document.body.style.backgroundColor = '#3498db';
            currentColor = '#3498db';
            break;
        case '#3498db':
            document.body.style.backgroundColor = '#e91e63';
            currentColor = '#e91e63';
            break;
        case '#e91e63':
            document.body.style.backgroundColor = '#2ecc71';
            currentColor = '#2ecc71';
            break;
        case '#2ecc71':
            document.body.style.backgroundColor = '#95a5a6';
            currentColor = '#95a5a6';
            break;
        case '#95a5a6':
            document.body.style.backgroundColor = '#9b59b6';
            currentColor = '#9b59b6';
            break;
        case '#9b59b6':
            document.body.style.backgroundColor = '#1565C0';
            currentColor = '#1565C0';
            break;
        case '#1565C0':
            document.body.style.backgroundColor = '#0277BD';
            currentColor = '#0277BD';
            break;
        case '#0277BD':
            document.body.style.backgroundColor = '#006064';
            currentColor = '#006064';
            break;
        case '#006064':
            document.body.style.backgroundColor = '#051e34';
            currentColor = '#051e34';
            break;
        default:
            document.body.style.backgroundColor = '#051e34';
            currentColor = '#051e34';
            break;
    }
    // Enregistrer la couleur courante dans le stockage local
    localStorage.setItem('backgroundColor', currentColor);
}