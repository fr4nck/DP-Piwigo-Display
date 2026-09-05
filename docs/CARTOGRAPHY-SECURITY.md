# Cartographie : frontière de sécurité

## Objet

Piwigo Display peut exposer des coordonnées GPS validées provenant de Piwigo, mais son cœur ne doit pas devenir un client direct d'un fournisseur cartographique externe.

La cartographie future reste une couche optionnelle au-dessus du contrat de géolocalisation décrit dans `docs/GEOLOCATION.md`.

## Frontière imposée au cœur du module

Le cœur de Piwigo Display ne doit pas :

- dépendre du plugin Piwigo `piwigo-openstreetmap` ;
- charger Leaflet depuis un CDN public ;
- appeler directement le serveur public `tile.openstreetmap.org` ;
- appeler directement le service public Nominatim ;
- transmettre une clé API Piwigo, un cookie de session Piwigo, un mot de passe de compte de service ou toute autre information d'authentification à un fournisseur cartographique ;
- envoyer automatiquement des commentaires, noms de fichiers ou autres métadonnées privées à un service de carte ou de géocodage.

Un test de régression CI vérifie l'absence de ces couplages dans le code du cœur.

## Coordonnées privées

Des coordonnées GPS peuvent elles-mêmes être sensibles. Le fait qu'un utilisateur Drupal puisse lire un Media ne signifie pas qu'elles doivent automatiquement être envoyées à un service tiers.

Toute future vue cartographique destinée à une photothèque Piwigo authentifiée devra donc :

1. appliquer les permissions Drupal avant de construire les marqueurs ;
2. ne transmettre au navigateur que les données strictement nécessaires à l'affichage ;
3. ne jamais intégrer les identifiants Piwigo dans les URLs de tuiles ou dans le JavaScript client ;
4. rendre le fournisseur de tuiles configurable ;
5. permettre de désactiver complètement les appels externes ;
6. conserver l'attribution exigée par le fournisseur de tuiles.

## Leaflet / Geofield

Si une intégration cartographique est ajoutée, elle devra utiliser les bibliothèques Drupal maintenues ou une bibliothèque locale versionnée. Le cœur Piwigo Display ne doit pas charger une version arbitraire de Leaflet depuis Internet.

Geofield, Leaflet et Geofield Map doivent rester des intégrations optionnelles. La présence de coordonnées Piwigo ne doit pas imposer ces modules à une installation qui utilise seulement Media Library.

## Nominatim

Aucun géocodage n'est nécessaire pour afficher une photo qui possède déjà latitude et longitude. Nominatim ne doit donc pas être appelé dans le chemin normal Piwigo → Drupal.

Un éventuel géocodage futur devra être une fonctionnalité distincte, explicitement activée et conforme à la politique du service choisi. Il ne devra jamais servir de mécanisme implicite pour enrichir les médias Piwigo.

## Données de masse

Une vue cartographique d'album ne doit pas effectuer un `pwg.images.getInfo` par marqueur. Avant d'ajouter une carte de masse, il faudra disposer d'un mécanisme batch ou d'un cache dédié qui respecte les permissions de l'identité Piwigo configurée.

## Principe d'architecture

Le contrat retenu est :

`Piwigo → métadonnées GPS validées → Drupal → intégration cartographique optionnelle`

et non :

`Drupal → plugin Piwigo OpenStreetMap → JavaScript/Leaflet embarqué → fournisseur OSM`.
