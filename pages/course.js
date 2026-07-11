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
