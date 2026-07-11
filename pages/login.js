window.addEventListener('DOMContentLoaded', (event) => {
    const logo = document.getElementById('logo');
    logo.style.opacity = '1';
});

document.addEventListener("DOMContentLoaded", function() {
    const loginForm = document.querySelector('.login-form');
    const logo = document.querySelector('.logo img');
    const logoText = document.querySelector('.logo h3');

    // Initialisation des styles pour l'animation
    loginForm.style.opacity = 0;
    loginForm.style.transform = 'translateY(-50px)';
    logo.style.opacity = 0;
    logoText.style.opacity = 0;
    logoText.style.transform = 'translateY(-20px)';

    // Animation d'apparition avec un effet de glissement et de fondu
    setTimeout(() => {
        loginForm.style.transition = 'opacity 2s, transform 1.5s ease-out';
        loginForm.style.opacity = 1;
        loginForm.style.transform = 'translateY(0)';

        logo.style.transition = 'opacity 2s ease-in-out';
        logo.style.opacity = 1;

        logoText.style.transition = 'opacity 2.5s ease-in-out, transform 2s ease-out';
        logoText.style.opacity = 1;
        logoText.style.transform = 'translateY(0)';
    }, 500); // Délai de 0,5s avant l'apparition

    // Effet au survol du bouton
    const loginButton = document.querySelector('button');
    loginButton.addEventListener('mouseenter', () => {
        loginButton.style.transform = 'scale(1.1)';
        loginButton.style.boxShadow = '0 4px 12px rgba(0, 0, 0, 0.2)';
        loginButton.style.transition = 'transform 0.3s, box-shadow 0.3s';
    });
    loginButton.addEventListener('mouseleave', () => {
        loginButton.style.transform = 'scale(1)';
        loginButton.style.boxShadow = 'none';
    });
});
document.addEventListener('DOMContentLoaded', function() {
    const visibilityToggles = document.querySelectorAll('.toggle-visibility');

    visibilityToggles.forEach(toggle => {
        toggle.addEventListener('click', function() {
            const target = document.getElementById(this.getAttribute('data-target'));
            
            if (target.type === 'password') {
                target.type = 'text';
                this.src = '../images/eye-slash.png'; // Remplacer l'icône par un œil barré
                this.alt = 'Masquer';
            } else {
                target.type = 'password';
                this.src = '../images/eye.png'; // Remettre l'icône de l'œil
                this.alt = 'Afficher';
            }
        });
    });
});

// Sélectionner toutes les cartes de cours
const courseCards = document.querySelectorAll('.course-card');

// Pour chaque carte de cours, ajouter un écouteur d'événement de clic
courseCards.forEach(card => {
    card.addEventListener('click', () => {
        const courseId = card.id;  // Utiliser l'ID du cours comme identifiant unique
        const courseName = card.querySelector('h3').textContent;  // Récupérer le nom du cours
        window.location.href = `discussions.php?course=${courseId}&name=${encodeURIComponent(courseName)}`;  // Rediriger vers la page de discussion avec les paramètres de l'URL
    });
});

