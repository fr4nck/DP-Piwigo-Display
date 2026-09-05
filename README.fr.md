# Piwigo Display pour Drupal

Module Drupal permettant d'utiliser Piwigo comme photothèque/DAM depuis la Media Library de Drupal.

## Statut

`0.1.0-alpha1` — première alpha publique pour Drupal 10.3+ et Drupal 11.

Cette alpha dispose d'une couverture importante de tests de régression et de compatibilité avec de vrais paquets Drupal 10/11 installés par Composer. En revanche, **le parcours complet n'a pas encore été validé de bout en bout sur un site Drupal réellement démarré, connecté à une instance Piwigo réelle**. Elle est donc destinée à l'évaluation et aux tests d'intégration avant déploiement en production.

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

## Secrets

Les identifiants saisis depuis l'administration de Piwigo Display sont stockés dans l'état local de Drupal. Ils ne figurent donc pas dans les exports de configuration. Cet état local n'est toutefois pas chiffré : les sauvegardes de la base de données peuvent contenir ces secrets et doivent être protégées en conséquence.

Pour des identifiants de production pilotés par le déploiement, `settings.php` reste la méthode recommandée :

```php
$settings['piwigo_display.base_url'] = 'https://photos.example.org';
$settings['piwigo_display.api_key'] = 'pkid-…:…';
$settings['piwigo_display.legacy_username'] = 'drupal-service';
$settings['piwigo_display.legacy_password'] = '…';
```

Les valeurs définies dans `settings.php` sont prioritaires. Ne jamais versionner une clé API ou un mot de passe. Les connexions authentifiées exigent HTTPS.

Les installations de développement existantes qui avaient encore `api_key` ou `legacy_password` dans la configuration sont migrées automatiquement vers l'état local par la mise à jour du module, puis ces anciennes valeurs sont supprimées de la configuration exportable.

## Miniatures privées et dérivées protégées

Une clé API Piwigo authentifie l'API Web. Certaines installations protègent aussi les URL binaires des dérivées. La Media Library distingue donc deux cas :

- Piwigo public/anonyme : les miniatures peuvent être mises en cache sous `public://piwigo_display/thumbnails` ;
- Piwigo authentifié : la miniature est récupérée côté serveur et transmise en mémoire par la route Drupal protégée, sans écrire ses octets dans `public://`.

La mise à jour `10002` purge également les anciennes miniatures publiques éventuellement créées par les builds de développement précédents.

Le rendu frontend applique désormais la même frontière de confiance. Pour une photothèque Piwigo publique, le formatter peut utiliser directement les URL de dérivées. Pour une connexion Piwigo authentifiée, il génère une route Drupal liée à l'entité Media : Drupal vérifie l'accès `media.view`, récupère la dérivée côté serveur et la transmet sans stockage binaire persistant. Les identifiants et cookies Piwigo ne sont jamais envoyés au navigateur.

Les URL de miniatures privées de la Media Library sont en outre signées par Drupal afin de limiter l'énumération arbitraire des identifiants d'images Piwigo.

## Géolocalisation et cartographie

Piwigo Display sait exposer les coordonnées `latitude` et `longitude` validées provenant de `pwg.images.getInfo`. Les bornes WGS84 sont contrôlées, zéro est une valeur valide, et l'ordre des axes est explicite pour Leaflet (`[lat, lng]`) et GeoJSON (`[lng, lat]`).

Le cœur du module ne dépend pas du plugin Piwigo OpenStreetMap, n'appelle pas directement les services publics de tuiles OSM ou Nominatim et ne charge pas Leaflet depuis un CDN public. Une éventuelle cartographie restera une intégration Drupal optionnelle.

## Limites de cette alpha

Ne sont notamment pas encore implémentés :

- recadrage/focal point non destructif ;
- pagination au-delà de la première page de résultats Media Library ;
- filtres avancés par tags/dates ;
- interface cartographique optionnelle ;
- stratégie batch GPS pour les cartes de masse ;
- automatisation de packaging/publication Drupal.org.

## Recadrage

Le recadrage prévu pour la suite sera non destructif : Piwigo conserve l'original ; Drupal mémorise le cadrage et ne fabrique/cache que la dérivée nécessaire au site.

## Licence

Copyright © 2026 fr4nck.

GPL-2.0-or-later, conformément aux exigences de licence de Drupal pour les modules distribués.
