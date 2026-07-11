function loadStudents(classId) {
    if (!classId) {
        document.querySelector('.grade-form').style.display = 'none';
        document.getElementById('gradesTable').innerHTML = '';
        return;
    }

    fetch(`../includes/get_students.php?class_id=${classId}`)
        .then(response => response.json())
        .then(students => {
            const studentSelect = document.getElementById('student');
            studentSelect.innerHTML = '<option value="">Sélectionner un étudiant</option>';
            students.forEach(student => {
                studentSelect.innerHTML += `<option value="${student.id}">${student.name}</option>`;
            });
            
            document.querySelector('.grade-form').style.display = 'block';
            loadCourses(classId);
        });
}

function loadCourses(classId) {
    fetch(`get_courses.php?class_id=${classId}`)
        .then(response => response.json())
        .then(courses => {
            const courseSelect = document.getElementById('course');
            courseSelect.innerHTML = '<option value="">Sélectionner un cours</option>';
            courses.forEach(course => {
                courseSelect.innerHTML += `<option value="${course.id}">${course.name}</option>`;
            });
        });
}

document.getElementById('gradeForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('add_grade.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Note ajoutée avec succès');
            this.reset();
            loadGradesTable(document.getElementById('class').value);
        } else {
            alert('Erreur: ' + data.message);
        }
    });
});
function loadStudents(classId) {
    if (!classId) {
        document.querySelector('.grade-form').style.display = 'none';
        document.getElementById('gradesTable').innerHTML = '';
        return;
    }

    fetch(`get_students.php?class_id=${classId}`)
        .then(response => response.json())
        .then(students => {
            const studentSelect = document.getElementById('student');
            studentSelect.innerHTML = '<option value="">Sélectionner un étudiant</option>';
            students.forEach(student => {
                studentSelect.innerHTML += `<option value="${student.id}">${student.name}</option>`;
            });
            
            document.querySelector('.grade-form').style.display = 'block';
            loadCourses(classId);
        });
}

function loadCourses(classId) {
    fetch(`get_courses.php?class_id=${classId}`)
        .then(response => response.json())
        .then(courses => {
            const courseSelect = document.getElementById('course');
            courseSelect.innerHTML = '<option value="">Sélectionner un cours</option>';
            courses.forEach(course => {
                courseSelect.innerHTML += `<option value="${course.id}">${course.name}</option>`;
            });
        });
}

document.getElementById('gradeForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    
    fetch('add_grade.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Note ajoutée avec succès');
            this.reset();
            loadGradesTable(document.getElementById('class').value);
        } else {
            alert('Erreur: ' + data.message);
        }
    });
});
// Ajouter dans grades.js
function previewGrade(grade) {
    const preview = document.getElementById('gradePreview');
    const value = parseFloat(grade);
    
    if (isNaN(value) || value < 0 || value > 20) {
        preview.className = 'grade-preview invalid';
        preview.textContent = 'Note invalide';
        return;
    }

    preview.className = `grade-preview ${getGradeClass(value)}`;
    preview.textContent = value.toFixed(2) + '/20';
}

function getGradeClass(value) {
    if (value >= 14) return 'grade-good';
    if (value >= 10) return 'grade-average';
    return 'grade-poor';
}