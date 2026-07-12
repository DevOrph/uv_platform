<?php
// ============================================================
// Page fusionnée dans grades_management.php (vue tableau unique
// avec drawer). Conservée en simple redirection pour les anciens
// liens, favoris et appels AJAX (les noms d'actions sont identiques).
// ============================================================
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: grades_management.php' . ($qs === '' ? '' : '?' . $qs));
exit();
