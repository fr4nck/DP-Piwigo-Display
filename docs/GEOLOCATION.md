# Géolocalisation : contrat Piwigo → Drupal → cartographie

## Principe

Piwigo reste la source de vérité pour les métadonnées de l'image, y compris `latitude` et `longitude` lorsque ces champs sont présents. Piwigo Display lit ces coordonnées via `pwg.images.getInfo` et les expose comme métadonnées Media Drupal validées.

Le module ne dépend pas du plugin Piwigo OpenStreetMap et ne réutilise ni son HTML, ni son JavaScript, ni sa version de Leaflet.

## Validation

Une paire GPS n'est utilisable que si les deux valeurs sont numériques et finies, avec :

- latitude comprise entre -90 et 90 inclus ;
- longitude comprise entre -180 et 180 inclus ;
- `0` accepté pour les deux axes.

Une paire incomplète ou invalide est traitée comme absente. Le module ne tente pas de la corriger silencieusement.

## Ordre des axes

Deux conventions différentes doivent rester explicitement séparées :

- Piwigo / Leaflet : `[latitude, longitude]` ;
- GeoJSON : `[longitude, latitude]`.

`GeoCoordinates::toLeaflet()` et `GeoCoordinates::toGeoJson()` centralisent cette conversion afin d'éviter une inversion silencieuse des axes.

## Intégration Drupal

Les métadonnées Media disponibles sont :

- `latitude` ;
- `longitude`.

Aucune dépendance à Geofield, Leaflet ou Geofield Map n'est imposée par le cœur de Piwigo Display. Une intégration cartographique future pourra mapper ces métadonnées vers un champ géographique Drupal, mais cette couche doit rester optionnelle.

## OpenStreetMap et services externes

L'affichage d'une carte ne doit jamais transmettre à un fournisseur de cartes :

- clé API Piwigo ;
- cookie de session Piwigo ;
- mot de passe de compte de service ;
- commentaire privé, nom de fichier privé ou autre métadonnée non nécessaire.

Les coordonnées elles-mêmes peuvent être sensibles. Une carte d'une photothèque privée doit donc être considérée comme une fonctionnalité distincte avec sa propre politique d'accès et de confidentialité.

Le géocodage externe n'est pas nécessaire pour afficher une image déjà géolocalisée. Un éventuel usage futur de Nominatim ou d'un autre géocodeur devra être explicite, configurable, limité et séparé du chemin normal Piwigo → Drupal.

## Performance

Les réponses de listes Piwigo ne doivent pas être supposées contenir les coordonnées, même lorsque la base Piwigo les possède. La première implémentation lit la géolocalisation lors de `pwg.images.getInfo`, déjà utilisé pour les métadonnées d'un Media individuel.

Une carte de masse ne devra pas déclencher un appel `getInfo` par marqueur. Si ce besoin apparaît, il faudra mettre en place une récupération batch/cachée dédiée avant d'activer une vue cartographique d'album à grande échelle.

## Écriture vers Piwigo

Piwigo Display ne modifie pas actuellement les coordonnées Piwigo. Toute future écriture GPS devra :

1. utiliser l'API Piwigo authentifiée et non une écriture directe en base ;
2. accepter les coordonnées égales à zéro ;
3. appliquer les mêmes bornes que la lecture ;
4. protéger l'action contre CSRF et contrôler les permissions Drupal ;
5. ne jamais mélanger paramètres API validés et variables globales HTTP brutes.
