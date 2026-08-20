# Piwigo Display pour Drupal

Module en cours de développement pour utiliser Piwigo comme photothèque/DAM depuis la Media Library de Drupal.

## Objectif de la V0.1

Depuis l'interface Drupal, un.e gestionnaire de contenu peut :

- parcourir les albums Piwigo ;
- lancer une recherche globale ;
- prévisualiser les images ;
- en sélectionner plusieurs ;
- créer les entités Media Drupal correspondantes sans télécharger puis réuploader les fichiers sur son poste ;
- afficher ensuite une dérivée Piwigo dans Drupal.

La valeur canonique enregistrée dans Drupal est l'identifiant de l'image Piwigo. Piwigo reste la source de vérité.

## Compatibilité visée

- Drupal 10.3+ ;
- Drupal 11 ;
- Piwigo public sans authentification ;
- Piwigo 16+ avec clé API personnelle (`X-PIWIGO-API` à partir de Piwigo 16.1) ;
- compatibilité optionnelle par compte de service identifiant/mot de passe pour les Piwigo antérieurs ou les URL binaires protégées.

## Installation rapide

1. Copier `piwigo_display` dans `web/modules/custom/`.
2. Activer le module.
3. Aller dans `Configuration > Média > Piwigo Display`.
4. Configurer l'URL Piwigo et, si nécessaire, une clé API dédiée.
5. Enregistrer puis tester la connexion.
6. Créer un type de média Drupal utilisant la source **Piwigo image**.
7. Ouvrir la Media Library : la source propose recherche, albums, prévisualisation et sélection multiple.

## Secrets en production

À privilégier dans `settings.php` :

```php
$settings['piwigo_display.base_url'] = 'https://photos.example.org';
$settings['piwigo_display.api_key'] = 'pkid-…:…';
$settings['piwigo_display.legacy_username'] = 'drupal-service';
$settings['piwigo_display.legacy_password'] = '…';
```

Les connexions authentifiées exigent HTTPS.

## Limite connue de cette première version

Une clé API Piwigo authentifie l'API Web. Certaines installations peuvent en plus protéger les URL binaires des dérivées. La V0.1 met déjà les miniatures de la Media Library en cache côté serveur et transmet la clé API lors de leur récupération, et peut ouvrir une session Piwigo avec un compte de service optionnel. Un mode proxy dédié reste prévu pour les configurations les plus verrouillées.

## Recadrage

Le recadrage prévu pour la suite sera non destructif : Piwigo conserve l'original ; Drupal mémorise le cadrage et ne fabrique/cache que la dérivée nécessaire au site.

## Licence

Copyright © 2026 fr4nck.

GPL-2.0-or-later, conformément aux exigences de licence de Drupal pour les modules distribués.
