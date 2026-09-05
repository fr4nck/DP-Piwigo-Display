# Changelog

## 0.1.0-alpha1 — 2026-09-05

Première alpha publique de **Piwigo Display** pour Drupal 10.3+ et Drupal 11.

### Flux éditorial

- source Drupal Media native conservant l’identifiant canonique de l’image Piwigo ;
- recherche globale, navigation par albums, prévisualisation et sélection multiple dans la Media Library ;
- grille photo responsive avec prise en charge du clavier, du focus, des contrastes forcés et de la réduction des animations ;
- respect du nombre de médias encore sélectionnables dans la Media Library ;
- chargement différé des miniatures afin de ne pas bloquer les mises à jour AJAX.

### Intégration Piwigo

- client Web API pour les principales opérations nécessaires à la Media Library ;
- prise en charge des photothèques Piwigo publiques/anonymes ;
- prise en charge des clés API personnelles Piwigo 16+ via `X-PIWIGO-API` ;
- compatibilité optionnelle avec une session identifiant/mot de passe pour les installations plus anciennes ou les dérivées protégées ;
- validation stricte de l’origine Piwigo (schéma, hôte et port effectif) ;
- redirections désactivées pour les appels API et les récupérations binaires authentifiées.

### Bibliothèques privées et sécurité

- identifiants saisis dans Drupal stockés dans l’état local plutôt que dans la configuration exportable, avec migration des anciens builds de développement ;
- miniatures authentifiées récupérées côté serveur et transmises sans stockage persistant dans `public://` ;
- URL de miniatures privées signées par Drupal afin de limiter l’énumération arbitraire des identifiants Piwigo ;
- dérivées frontend authentifiées servies par un proxy Drupal soumis au contrôle d’accès `media.view` ;
- proxy limité aux formats raster reconnus (JPEG, PNG, GIF, WebP), avec en-têtes de protection adaptés ;
- purge des anciens caches publics de miniatures lors de la mise à jour depuis les builds de développement concernés.

### Qualité et compatibilité

- matrice PHP 8.1 / PHP 8.3 ;
- contrôles de compatibilité avec Drupal 10 et Drupal 11 installés par Composer ;
- validation Composer, JavaScript et YAML ;
- tests de régression couvrant notamment la structure Media Library, les limites de sélection, la confidentialité des miniatures et dérivées, le stockage des secrets, la sécurité d’origine Piwigo et la validation des coordonnées GPS.

### Limites de cette alpha

Cette version n’a pas encore été validée de bout en bout sur un site Drupal réellement démarré et connecté à une instance Piwigo réelle. Une validation runtime reste donc nécessaire avant de la considérer comme stable, notamment pour le parcours complet de création de Media, le rendu avec de vrais thèmes d’administration et le comportement des photothèques publiques et privées en hébergement de production.

Ne sont notamment pas encore implémentés :

- recadrage / focal point non destructif ;
- pagination au-delà de la première page de résultats de la Media Library ;
- filtres avancés par tags ou dates ;
- publication sur Drupal.org.
