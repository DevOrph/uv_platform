# API Mobile UV - Université Virtuelle

API REST pour l'application mobile UV développée par **Coding Enterprise** (Orphé MYENE & Filbert KASSA)

## 📋 Vue d'ensemble

Cette API permet à l'application mobile React Native de communiquer avec la base de données MySQL existante de la plateforme UV.

## 🚀 Installation sur Hostinger

### Étape 1 : Upload des fichiers
Uploadez le dossier `uv-api-mobile` à la racine de votre hébergement Hostinger, à côté de votre projet web UV existant.

Structure recommandée :
```
public_html/
├── UV/                    # Votre projet web actuel
├── uv-api-mobile/         # Nouvelle API mobile
│   ├── config/
│   ├── auth/
│   ├── student/
│   └── README.md
```

### Étape 2 : Configuration des permissions
Donnez les permissions appropriées :
```bash
chmod 755 uv-api-mobile/
chmod 644 uv-api-mobile/config/*.php
chmod 644 uv-api-mobile/auth/*.php
chmod 644 uv-api-mobile/student/*.php
```

### Étape 3 : Créer le dossier de logs
```bash
mkdir uv-api-mobile/logs
chmod 777 uv-api-mobile/logs
```

### Étape 4 : Tester l'API
Accédez à : `https://votre-domaine.com/uv-api-mobile/auth/login.php`

## 📡 Endpoints disponibles

### 🔐 Authentification

#### POST `/auth/login.php`
Connexion d'un utilisateur

**Request Body:**
```json
{
  "identifiant": "user_id ou email",
  "password": "mot_de_passe"
}
```

**Response Success (200):**
```json
{
  "success": true,
  "message": "Connexion réussie",
  "data": {
    "user": {
      "id": "E001",
      "name": "John Doe",
      "email": "john@example.com",
      "role": "student",
      "last_login": "2024-01-15 10:30:00"
    },
    "token": "abc123def456..."
  }
}
```

**Response Error (401):**
```json
{
  "success": false,
  "message": "Identifiant ou mot de passe incorrect",
  "data": null
}
```

---

### 👤 Profil Étudiant

#### GET `/student/profile.php?user_id=XXX`
Récupérer le profil complet d'un étudiant

**Parameters:**
- `user_id` (required): ID de l'étudiant

**Response Success (200):**
```json
{
  "success": true,
  "message": "Profil récupéré avec succès",
  "data": {
    "profile": {
      "id": "E001",
      "name": "John Doe",
      "email": "john@example.com",
      "phone": "+241 XX XX XX XX",
      "date_of_birth": "2000-05-15",
      "gender": "M",
      "address": "Libreville",
      "city": "Libreville",
      "country": "Gabon",
      "profile_photo": "path/to/photo.jpg",
      "created_at": "2024-01-01 00:00:00",
      "last_login": "2024-01-15 10:30:00"
    },
    "academic": {
      "class_id": "1",
      "class_name": "Licence 1 Informatique",
      "level": "L1",
      "department": "Informatique",
      "academic_year": "2024-2025",
      "registration_date": "2024-01-01",
      "status": "active"
    }
  }
}
```

---

### 📊 Notes et Bulletins

#### GET `/student/grades.php?user_id=XXX&period_id=XXX`
Récupérer les notes d'un étudiant

**Parameters:**
- `user_id` (required): ID de l'étudiant
- `period_id` (optional): ID de la période d'évaluation

**Response Success (200):**
```json
{
  "success": true,
  "message": "Notes récupérées avec succès",
  "data": {
    "periods": [
      {
        "id": "1",
        "name": "Semestre 1",
        "start_date": "2024-01-01",
        "end_date": "2024-06-30",
        "is_active": true
      }
    ],
    "current_period_id": "1",
    "grades": [
      {
        "id": "1",
        "grade_value": "15.5",
        "coefficient": "2",
        "grade_date": "2024-02-15",
        "comments": "Bon travail",
        "course_name": "Programmation C",
        "course_code": "INF101",
        "teaching_unit_name": "Informatique Fondamentale",
        "credits": "6",
        "period_name": "Semestre 1"
      }
    ],
    "statistics": {
      "units": [
        {
          "unit_name": "Informatique Fondamentale",
          "credits": "6",
          "average": 15.5,
          "courses": [...]
        }
      ],
      "general_average": 14.2,
      "total_courses": 8,
      "total_credits": 30
    }
  }
}
```

---

### 📅 Emploi du temps

#### GET `/student/schedule.php?user_id=XXX&week_offset=0`
Récupérer l'emploi du temps d'un étudiant

**Parameters:**
- `user_id` (required): ID de l'étudiant
- `week_offset` (optional, default=0): Décalage en semaines (0=semaine actuelle, 1=semaine prochaine, -1=semaine dernière)

**Response Success (200):**
```json
{
  "success": true,
  "message": "Emploi du temps récupéré avec succès",
  "data": {
    "week_info": {
      "start_date": "2024-01-15",
      "end_date": "2024-01-21",
      "week_offset": 0,
      "formatted_range": "15/01/2024 - 21/01/2024"
    },
    "class_info": {
      "class_id": "1",
      "class_name": "Licence 1 Informatique"
    },
    "schedule": [
      {
        "day": "Lundi",
        "date": "2024-01-15",
        "formatted_date": "15/01/2024",
        "courses": [
          {
            "id": "1",
            "course_name": "Programmation C",
            "course_code": "INF101",
            "course_type": "CM",
            "teacher_name": "Dr. Dupont",
            "start_time": "08:00:00",
            "end_time": "10:00:00",
            "room": "Salle A101",
            "specific_date": null
          }
        ]
      }
    ]
  }
}
```

---

## 🔧 Configuration

### Fichier `config/database.php`
Contient les informations de connexion à la base de données MySQL.

**Important:** Assurez-vous que les informations de connexion sont correctes :
```php
private $host = "localhost";
private $db_name = "u641337841_uv_platform";
private $username = "u641337841_DevOrph";
private $password = "Dev_uv_platform/8";
```

### Fichier `config/helpers.php`
Contient les fonctions utilitaires réutilisables dans toute l'API.

---

## 🔒 Sécurité

### CORS (Cross-Origin Resource Sharing)
L'API est configurée pour accepter les requêtes depuis n'importe quelle origine pendant le développement.

**⚠️ IMPORTANT EN PRODUCTION:**
Modifiez `config/helpers.php` pour restreindre les origines autorisées :
```php
// Au lieu de :
header("Access-Control-Allow-Origin: *");

// Utilisez :
header("Access-Control-Allow-Origin: https://votre-app-mobile.com");
```

### Validation des données
Toutes les entrées utilisateur sont nettoyées et validées avant traitement.

### Gestion des erreurs
Les erreurs sont loguées dans `logs/error.log` sans exposer d'informations sensibles.

---

## 📝 Codes de réponse HTTP

| Code | Signification |
|------|---------------|
| 200 | Succès |
| 400 | Requête invalide (données manquantes ou incorrectes) |
| 401 | Non autorisé (identifiants incorrects) |
| 403 | Accès interdit (compte bloqué) |
| 404 | Ressource non trouvée |
| 405 | Méthode HTTP non autorisée |
| 500 | Erreur serveur |

---

## 🧪 Tests

### Test avec cURL

**Test de connexion:**
```bash
curl -X POST https://votre-domaine.com/uv-api-mobile/auth/login.php \
  -H "Content-Type: application/json" \
  -d '{"identifiant":"E001","password":"votre_password"}'
```

**Test profil:**
```bash
curl https://votre-domaine.com/uv-api-mobile/student/profile.php?user_id=E001
```

**Test notes:**
```bash
curl https://votre-domaine.com/uv-api-mobile/student/grades.php?user_id=E001
```

**Test emploi du temps:**
```bash
curl https://votre-domaine.com/uv-api-mobile/student/schedule.php?user_id=E001&week_offset=0
```

---

## 📱 Intégration avec React Native

Dans votre application React Native, configurez l'URL de base de l'API :

```javascript
// src/config/constants.js
export const API_BASE_URL = 'https://votre-domaine.com/uv-api-mobile';
```

---

## 🐛 Débogage

### Activer les logs d'erreur
Les erreurs sont automatiquement loguées dans `logs/error.log`.

Pour consulter les logs en temps réel :
```bash
tail -f uv-api-mobile/logs/error.log
```

### Problèmes courants

**1. Erreur de connexion à la base de données**
- Vérifiez les informations dans `config/database.php`
- Vérifiez que l'utilisateur MySQL a les permissions nécessaires

**2. Erreur CORS**
- Vérifiez que `setCorsHeaders()` est appelée dans chaque endpoint
- Consultez la console du navigateur/app pour plus de détails

**3. Erreur 500**
- Consultez `logs/error.log`
- Activez l'affichage des erreurs PHP temporairement

---

## 📞 Support

Pour toute question ou problème :
- **Email:** coding-enterprise@example.com
- **Développeurs:** Orphé MYENE & Filbert KASSA

---

## 📄 Licence

© 2024 Coding Enterprise - Tous droits réservés