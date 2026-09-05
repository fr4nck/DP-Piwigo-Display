# Audit transverse Piwigo / Drupal / OpenStreetMap — PMSL35

Date de l'audit : 2026-09-05  
Dépôt : `fr4nck/DP-Piwigo-Display`  
Nature : audit statique et documentation uniquement  
Décision : **GO SOUS CONDITIONS**

> **Limite de divulgation.** Ce dépôt est public. Deux constats upstream à portée sécurité ont été identifiés statiquement ; leurs détails techniques sont volontairement retenus jusqu'à divulgation privée et coordination avec Piwigo. Aucun PoC exploitable n'est publié ici.

## 1. Résumé exécutif

Le choix architectural déjà engagé dans Piwigo Display est le bon : **Piwigo reste la source de vérité des métadonnées GPS ; Drupal récupère les coordonnées, les valide et décide ensuite de leur exposition et de leur rendu cartographique**. Le flux cible reste : **Piwigo → GPS → validation Drupal → contrôle d'accès/cache → cartographie**. Il n'est ni nécessaire ni souhaitable de réutiliser le HTML, le JavaScript, les routes ou le Leaflet embarqué par `Piwigo/piwigo-openstreetmap`.

La PR #9 `hardening-geolocation-contract` a été mergée dans `main` le 4 septembre 2026. Le contrat présent dans `main` est cohérent avec l'audit : paire latitude/longitude obligatoire, valeurs numériques finies, bornes WGS84, zéro accepté, modèle Leaflet `[lat, lng]`, modèle GeoJSON `[longitude, latitude]`, absence de dépendance obligatoire à Geofield/Leaflet/Geofield Map.

Les points structurants sont les suivants :

- Piwigo 16.4.0 expose `latitude` et `longitude` dans `pwg.images.getInfo` de façon **implicite** : la méthode lit la ligne image complète et ne retire pas ces colonnes. Elle ne normalise toutefois pas leur type en nombre. Le client doit accepter une valeur numérique ou une chaîne numérique et la valider.
- `pwg.categories.getImages` et `pwg.images.search` ne renvoient pas les coordonnées GPS dans leur structure de sortie courante. Ils ne constituent donc pas une API batch de géolocalisation.
- `pwg.images.getInfo` ne prend qu'un `image_id` scalaire en 16.4.0. La demande upstream visant à accepter une liste d'identifiants est toujours ouverte (#981). Une carte de masse ne doit donc pas déclencher un `getInfo` par marqueur.
- `pwg.images.setInfo` **n'a pas de paramètres `latitude`/`longitude` dans le core**. L'écriture GPS effectuée par le plugin OpenStreetMap est une extension du contrat via un hook du plugin, et non un contrat core sur lequel Piwigo Display doit s'appuyer.
- Les contrôles Piwigo de visibilité/album/niveau sont appliqués à l'image avant que `getInfo` fournisse ses métadonnées. Il n'existe en revanche pas de permission séparée « GPS » : un principal Piwigo autorisé à lire l'image peut lire sa géolocalisation si elle existe.
- Le plugin officiel `piwigo-openstreetmap`, révision courante 16.b, conserve plusieurs défauts de logique autour de zéro, une source de données incohérente dans son hook `setInfo`, Leaflet 0.7.7 et plusieurs fournisseurs de tuiles historiques ou obsolètes.
- Deux constats du plugin relèvent d'une **divulgation responsable privée**. Un problème de sécurité upstream est confirmé statiquement ; un second sink de sécurité upstream a été identifié et son exploitabilité complète reste à confirmer. Les détails techniques sont volontairement retenus dans ce dépôt public.
- Les services OpenStreetMap doivent être distingués : le serveur de tuiles standard sert un fond de carte, Nominatim est un service de géocodage, l'API OSM est une API d'édition. Aucun des trois n'est une « API cartographique générique » interchangeable.

La future fonctionnalité cartographique est donc **GO SOUS CONDITIONS** : conserver la frontière Piwigo → validation Drupal → rendu ; ne pas dépendre de `piwigo-openstreetmap` ; éviter le N+1 ; réappliquer les contrôles d'accès Drupal ; traiter les coordonnées comme données potentiellement sensibles ; utiliser un Leaflet maintenu et un fournisseur de tuiles configurable et conforme.

## 2. Périmètre, versions et méthode

### Versions contrôlées

- Piwigo core : **16.4.0**, dernière version stable au moment de l'audit, sortie le 3 mai 2026.
- Comparaison historique : **Piwigo 2.9.5** pour le comportement de `pwg.images.getInfo` et `pwg.images.search`.
- Plugin `Piwigo/piwigo-openstreetmap` : révision distribuée **16.b**, sortie le 3 mars 2026 ; contrôle du code courant sur le dépôt officiel, dont les fichiers audités restent cohérents avec cette révision.
- Leaflet embarqué par le plugin : **0.7.7**.
- Piwigo Display : `main` après merge de la PR #9.

### Méthode

- lecture statique du code source officiel ;
- comparaison de contrats API et de versions ;
- lecture des politiques d'usage actuelles OSMF ;
- aucun test sur une instance tierce ;
- aucun scan, aucune charge, aucune tentative de contournement ;
- aucun PoC exploitable dans ce dépôt public ;
- qualification explicite entre défaut fonctionnel, code suspect et problème de sécurité confirmé statiquement.

## 3. Piwigo core : contrat GPS réel

### 3.1 `pwg.images.getInfo`

Dans Piwigo 16.4.0, `ws_images_getInfo()` exécute un `SELECT *` sur la table des images, applique le filtre `visible_images`, puis construit la réponse à partir de la ligne complète. Les champs explicitement retirés sont notamment `path` et `storage_category_id`; `latitude` et `longitude` ne sont pas retirés.

Conséquences :

- `latitude` et `longitude` sont bien disponibles pour une image lisible lorsqu'ils sont présents en base ;
- ce sont des champs **implicites de la ligne SQL**, pas des éléments d'un DTO GPS typé ;
- contrairement à `id`, `width`, `height`, `hit` et `filesize`, ils ne sont pas explicitement castés par `getInfo` ;
- un consommateur doit donc accepter `null` et des scalaires numériques potentiellement sérialisés comme chaînes, puis appliquer sa propre validation ;
- `image_id` reste scalaire en 16.4.0.

Pour la réponse, le code conserve aussi une compatibilité de forme : selon le format du service, la structure peut être directement la structure image ou être enveloppée sous `image`. Le `PiwigoClient::getImage()` du module sait déjà normaliser ces deux formes.

### 3.2 Permissions sur images privées et GPS

`getInfo` ne renvoie pas simplement une ligne par identifiant :

1. le filtre `visible_images` applique notamment le niveau de confidentialité de l'image par rapport au niveau de l'utilisateur courant ;
2. les albums associés sont filtrés par `forbidden_categories` ;
3. pour un utilisateur non administrateur, une image sans album associé lisible est refusée.

Il n'existe pas de permission GPS distincte. **La confidentialité GPS suit donc la confidentialité de l'image et du principal Piwigo qui interroge l'API.**

C'est un point important pour Drupal : un compte de service Piwigo disposant de droits larges peut légitimement récupérer les coordonnées d'images privées. Drupal ne doit jamais en déduire que le visiteur Drupal courant a le droit de voir ces coordonnées. Une future carte doit appliquer les droits Drupal/Piwigo attendus avant de sérialiser les marqueurs dans le HTML, le JSON ou une réponse AJAX.

### 3.3 `pwg.categories.getImages`

La méthode applique les restrictions de catégories/images et peut lire des lignes image complètes en SQL, mais la structure retournée est construite à partir d'une liste explicite de champs (`id`, dimensions, hit, fichier, nom, commentaire, dates, URLs et informations d'album). **`latitude` et `longitude` ne font pas partie de cette sortie.**

Conclusion : cette méthode est adaptée au listing d'images mais ne constitue pas un batch GPS.

### 3.4 `pwg.images.search`

Même constat : la recherche construit une sortie limitée et n'y insère pas `latitude`/`longitude`. **Elle ne permet pas d'obtenir en une requête les coordonnées des résultats.**

### 3.5 `pwg.images.setInfo`

Le contrat enregistré dans le core 16.4.0 contient les métadonnées habituelles de l'image, les catégories/tags, le niveau et les modes de mise à jour, mais **pas `latitude` ni `longitude`**. La méthode est enregistrée comme `admin_only` et `post_only`.

Le plugin OpenStreetMap ajoute son traitement via l'événement `ws_invoke_allowed`. Dans le core, les contraintes `post_only`, `admin_only`, l'autorisation API-key et la validation de signature sont évaluées avant ce hook. Le défaut du hook plugin décrit plus loin **ne doit donc pas être présenté comme un bypass d'authentification de `pwg.images.setInfo`**.

Pour Piwigo Display, une future écriture GPS ne doit pas dépendre de cette extension implicite sans contrat propre, versionné, testé et documenté.

### 3.6 Anciennes versions vs versions récentes

La comparaison avec Piwigo 2.9.5 montre le même principe général pour `getInfo` : lecture de la ligne image complète, application des restrictions puis retour des champs non retirés. Les coordonnées ne sont pas explicitement castées dans l'ancienne version non plus.

Aucune preuve d'un renommage `latitude`/`longitude` ou d'une inversion d'axes n'a été trouvée entre 2.9.5 et 16.4.0 dans ce chemin. La différence à absorber côté client est surtout la **forme de sérialisation historique/courante** et l'absence de garantie de type JSON numérique. Le choix du module de normaliser après réception est donc approprié.

### 3.7 Batch et risque N+1

En 16.4.0 :

- `pwg.images.getInfo` accepte un seul identifiant ;
- `pwg.categories.getImages` et `pwg.images.search` n'exposent pas le GPS ;
- aucune API core vérifiée dans ce périmètre ne remplace un batch `getInfo` pour obtenir les coordonnées de nombreuses images.

L'issue upstream Piwigo #981, ouverte depuis 2019 et encore ouverte avec un jalon 17.0.0beta1, demande précisément de permettre plusieurs `image_id` à `pwg.images.getInfo`.

**Règle pour Piwigo Display :** ne pas concevoir une carte de 100/1000 images comme une boucle de 100/1000 appels `getInfo`. Prévoir une stratégie de cache/agrégation côté Drupal, un service batch explicite si Piwigo en fournit un dans une version future, ou une autre source de projection contrôlée. La disponibilité future d'un batch Piwigo doit être revalidée au moment de l'implémentation.

## 4. Plugin officiel `Piwigo/piwigo-openstreetmap`

### 4.1 Version et statut

Le gestionnaire d'extensions Piwigo indique la révision **16.b**, publiée le 3 mars 2026 et compatible Piwigo 16. La révision 16.a du 2 mars 2026 mentionne explicitement la correction d'une XSS sur le paramètre `zoom`; 16.b corrige ensuite un problème de zoom lié à cette modification.

Ce correctif historique est distinct des constats de sécurité retenus ci-dessous.

### 4.2 Tests latitude/longitude dupliqués et coordonnées zéro

Deux zones présentent le même motif logique :

- `include/functions.php::osm_items_have_latlon()` répète des contrôles portant sur `latitude` à la place d'un contrôle latitude + longitude ;
- `include/functions_map.php::osm_get_items()` vérifie bien la non-nullité des deux colonnes mais répète ensuite le test de non-zéro de latitude.

Impact :

- les points sur l'équateur (`latitude = 0`) sont rejetés ;
- le méridien de Greenwich (`longitude = 0`) n'est pas traité symétriquement ;
- `0,0` est rejeté alors qu'il s'agit d'une paire WGS84 valide ;
- selon le helper, une longitude absente peut ne pas être détectée correctement.

Qualification : **défaut fonctionnel confirmé**, pas une vulnérabilité.

### 4.3 Hook `pwg.images.setInfo`

`include/ws_functions.inc.php::osm_ws_images_setInfo()` :

- utilise `empty()` sur latitude et longitude, ce qui refuse les valeurs valides `0`, `0.0` et chaînes équivalentes ;
- vérifie la présence/valeur à partir de `$params` ;
- écrit ensuite latitude/longitude à partir de `$_POST` au lieu d'utiliser la source validée/normalisée `$params`.

Qualification : **défaut de contrat et de robustesse confirmé**. Le point `$params` versus `$_POST` constitue une mauvaise séparation des responsabilités et une source d'incohérence, mais le hook est déclenché après les contrôles `admin_only`/`post_only` du core pour `pwg.images.setInfo`; l'audit ne le qualifie donc pas de bypass d'authentification.

Correction upstream recommandée : une seule source de données, contrôles explicites de présence compatibles avec zéro, type numérique fini, bornes WGS84, et conservation des protections core.

### 4.4 Problème de sécurité upstream — détail retenu

**Qualification : problème de sécurité upstream confirmé statiquement.**

Un problème de sécurité upstream affectant un chemin de consultation du plugin a été identifié par audit statique. Les détails techniques sont volontairement retenus et doivent être transmis à Piwigo par son canal de sécurité privé avant toute publication coordonnée.

Aucun détail permettant de reconstruire le chemin concerné n'est publié dans ce dépôt. L'impact et la correction doivent être coordonnés avec l'équipe Piwigo.

### 4.5 Second constat de sécurité upstream — détail retenu

Un second constat upstream concerne le traitement de métadonnées dans le rendu cartographique. Un sink potentiellement exploitable a été identifié statiquement ; son exploitabilité complète reste à confirmer et les détails sont retenus pour divulgation privée.

Aucun nom de fonction, chaîne de transformation, sink précis, condition d'exploitation ou payload n'est publié ici.

### 4.6 Leaflet et dépendances JavaScript embarquées

Le fichier `leaflet/leaflet-src.js` déclare **Leaflet 0.7.7**. Cette version a été intégrée au plugin en 2016. Le site Leaflet publie actuellement **1.9.4 comme version stable** ; Leaflet 2 reste en préversion.

Le répertoire du plugin embarque également plusieurs extensions Leaflet historiques (`MarkerCluster`, MiniMap, EditInOSM, Elevation 0.0.2, GPX, iconLayers, etc.). L'audit ne déduit pas automatiquement une CVE de leur âge, mais constate :

- dette de maintenance importante ;
- surface JavaScript tierce ancienne ;
- absence d'intérêt à transporter cette dette dans Piwigo Display.

Décision : **ne pas réutiliser ni copier ce bundle**. Pour Drupal, utiliser une version maintenue via les bibliothèques du site ou un module Drupal maintenu si le besoin fonctionnel le justifie.

### 4.7 Fournisseurs de tuiles, HTTPS et attribution

Le plugin contient encore plusieurs configurations historiques :

- OSM standard construit avec un schéma `http` ou `https` dépendant du schéma de la galerie et un hostname avec sous-domaine ;
- MapQuest avec anciens endpoints directs HTTP ; l'accès direct historique a été arrêté en juillet 2016 ;
- Stamen Toner via un ancien endpoint Fastly ; les anciens endpoints Stamen ont cessé de fonctionner le 31 octobre 2023 ;
- un ancien fond noir/blanc Wikimedia/Wmflabs connu comme indisponible depuis plusieurs années ;
- OSM France, HOT, OSM.de, Esri et couches custom qui ont chacun leurs propres conditions d'usage.

Pour le serveur de tuiles standard OSMF, la politique actuelle demande l'URL exacte `https://tile.openstreetmap.org/{z}/{x}/{y}.png`; le code actuel du plugin ne suit donc pas ce contrat moderne.

Autre défaut confirmé : `osmcopyright()` retourne immédiatement une attribution OSM avant le code censé enrichir l'attribution selon le fournisseur. Le code spécifique MapQuest/Stamen/Esri/custom situé après ce retour est inatteignable. L'attribution réellement affichée n'est donc pas suffisante pour tous les fournisseurs proposés.

Conclusion : le catalogue de fonds du plugin ne doit pas servir de référence de configuration à Piwigo Display.

## 5. Drupal / Piwigo Display

### 5.1 Architecture recommandée

Architecture de référence :

`Piwigo → GPS → validation Drupal → contrôle d'accès/cache → cartographie`

Flux d'implémentation :

`Piwigo API → métadonnées GPS → GeoCoordinates::fromPiwigo() → décision d'accès Drupal → sérialisation minimale → Leaflet/renderer`

Ce flux est préférable à toute réutilisation de `piwigo-openstreetmap` car il permet de contrôler dans Drupal :

- l'autorisation de voir le média et ses coordonnées ;
- la validation des types et bornes ;
- le cache ;
- l'encodage de sortie ;
- le choix et la version de Leaflet ;
- le fournisseur de tuiles et son attribution ;
- la politique de confidentialité ;
- la stratégie de batch.

### 5.2 Conventions d'axes

Contrat à conserver :

| Couche | Ordre |
|---|---|
| modèle interne | `latitude`, `longitude` nommés explicitement |
| Leaflet `LatLng` / tableaux | `[latitude, longitude]` / `[lat, lng]` |
| GeoJSON | `[longitude, latitude]` / `[lng, lat]` |

Ne jamais passer un tableau anonyme entre couches sans conversion nommée lorsque l'ordre peut changer.

### 5.3 Bornes WGS84 et zéro

Validation correcte :

- latitude finie entre `-90` et `90` inclus ;
- longitude finie entre `-180` et `180` inclus ;
- les deux composantes doivent être présentes ;
- `0` est une valeur valide ;
- `0,0` est une coordonnée valide sur le plan syntaxique et ne doit jamais être utilisé comme sentinelle « absent ».

`GeoCoordinates::fromPiwigo()` applique déjà ces règles.

### 5.4 Risque de fuite GPS vers des tiers

Une carte de média privé peut divulguer des informations de plusieurs manières :

- le JSON/HTML contenant les marqueurs peut être envoyé à un utilisateur Drupal non autorisé si le contrôle d'accès/cache est incorrect ;
- les requêtes de tuiles envoient au fournisseur l'adresse IP, le référent et les coordonnées de tuiles consultées, ce qui révèle approximativement la zone visualisée ;
- un reverse geocoding Nominatim enverrait **directement la coordonnée** au service tiers ;
- des SDKs cartographiques, analytics, géocodeurs ou plugins externes peuvent envoyer davantage de télémétrie selon leur politique.

Règles :

1. ne générer les marqueurs qu'après contrôle d'accès ;
2. propager les cache contexts/tags/max-age appropriés ;
3. ne pas mettre de coordonnées privées dans une réponse cacheable publiquement ;
4. ne pas appeler automatiquement Nominatim pour des coordonnées déjà connues ;
5. si des cartes privées doivent utiliser un fournisseur externe, rendre ce choix explicite et documenter la transmission de données ;
6. pour les cas sensibles, préférer un fond auto-hébergé ou un fournisseur disposant d'un contrat/confidentialité compatible.

### 5.5 Geofield / Leaflet Drupal / Geofield Map

Ils sont **optionnels**, pas des prérequis à la cartographie Piwigo Display.

Le module actuel ne déclare que les dépendances Drupal core `media`, `media_library` et `image`, et son `composer.json` n'impose aucun de ces modules géographiques.

- **Geofield** : utile si Drupal doit persister des géométries, indexer/requêter spatialement ou exposer ces données comme champs Drupal. Inutile si l'on affiche simplement un point lu depuis Piwigo sans stockage local durable.
- **Leaflet (Drupal)** : option pertinente si l'on veut bénéficier de son intégration Drupal, de renderers/Views et d'un cycle de maintenance suivi. Ce n'est pas la seule manière saine de charger Leaflet.
- **Geofield Map** : utile surtout avec Geofield pour widgets, formatters et Views cartographiques plus riches. Surdimensionné pour une première carte de points provenant directement de Piwigo.

Au moment de l'audit, les branches stables récentes de ces projets sont maintenues et couvertes par la politique de sécurité Drupal. Il n'y a néanmoins aucune raison d'introduire une dépendance avant que le besoin fonctionnel correspondant soit réel.

## 6. OpenStreetMap : trois services à ne pas confondre

| Service | Rôle | À utiliser pour Piwigo Display ? |
|---|---|---|
| serveur de tuiles standard OSMF | images raster du fond de carte | oui éventuellement, sous réserve de respecter la politique et sans SLA |
| Nominatim public | géocodage / recherche d'adresses / reverse geocoding | non pour l'affichage GPS courant ; seulement fonctionnalité explicite future |
| API OSM | édition des données OpenStreetMap | non ; hors besoin de Piwigo Display |

### 6.1 Serveur de tuiles standard OSMF

Politique actuelle principale :

- HTTPS ; URL exacte `https://tile.openstreetmap.org/{z}/{x}/{y}.png` ;
- attribution OpenStreetMap visible ;
- User-Agent identifiable pour les clients non navigateur ; les navigateurs utilisent leur UA habituel ;
- `Referer` correct pour les sites web et non supprimé par un proxy ;
- respect des entêtes de cache, ou au moins 7 jours si le client ne peut pas les interpréter ;
- interdiction du préchargement massif, du téléchargement offline et des stratégies contournant le cache ;
- service best-effort sans SLA ; capacité de changer de fournisseur recommandée ;
- ne pas soumettre de données personnelles ou confidentielles aux services OSMF.

Pour une intégration Drupal : fournir le tile URL en configuration, conserver l'attribution, ne pas proxifier sans nécessité, et si un proxy serveur est utilisé, lui donner un UA stable et respecter le cache.

### 6.2 Nominatim public

Politique actuelle principale :

- maximum absolu de **1 requête/seconde** ;
- User-Agent ou Referer valide identifiant l'application ;
- attribution ;
- cache des résultats lorsque possible ;
- usage déclenché par l'utilisateur et modéré ;
- **autocomplete côté client strictement interdit** sur l'instance publique ;
- bulk/systematic queries fortement restreints ;
- ne pas envoyer de données personnelles/confidentielles.

Piwigo fournit déjà latitude/longitude : **aucun appel Nominatim n'est nécessaire pour afficher les marqueurs**. Un reverse geocoding futur doit être une fonctionnalité séparée, opt-in, configurable, avec cache et avertissement de confidentialité.

### 6.3 API d'édition OSM

L'OSMF précise que cette API sert à **éditer** les données OSM, pas à afficher une carte ni à réaliser une intégration read-only. Les exigences incluent attribution, User-Agent identifiable, Referer si connu et limite de threads de téléchargement ; les gros consommateurs doivent utiliser des dumps/alternatives.

Piwigo Display n'a pas de raison de l'appeler.

### 6.4 Confidentialité OSMF

La politique OSMF indique que ses services peuvent journaliser notamment adresse IP, navigateur/application, système, référent, date/heure et ressources consultées ; les tuiles sont distribuées par un réseau mondial de caches/CDN. Nominatim, l'API d'édition et les tuiles standard sont des services OSMF distincts. Les autres couches/fournisseurs visibles dans l'écosystème OpenStreetMap ne sont pas nécessairement opérés par l'OSMF et ont leurs propres politiques.

## 7. Matrice d'audit

La colonne `fichier/ligne` utilise la fonction ou le bloc logique lorsque les numéros de ligne sont susceptibles de bouger. Pour les constats sécurité retenus, la localisation détaillée est volontairement omise dans ce dépôt public.

| composant | fichier/ligne | constat | impact | reproductibilité | sévérité | certitude | correction proposée |
|---|---|---|---|---|---|---|---|
| Piwigo core 16.4 | `include/ws_functions/pwg.images.php::ws_images_getInfo()` | `latitude`/`longitude` passent implicitement via la ligne image complète ; pas de cast numérique dédié | contrat fragile si un client suppose des nombres JSON natifs | statique | faible | élevée | valider/caster côté consommateur ; documenter le contrat |
| Piwigo core 16.4 | `ws_images_getInfo()` + filtres utilisateur | GPS suit les droits de lecture de l'image ; pas d'ACL GPS distincte | fuite possible si Drupal réutilise un compte de service plus privilégié que le visiteur | statique | élevée côté intégrateur | élevée | réappliquer contrôle d'accès Drupal avant sérialisation |
| Piwigo core 16.4 | `pwg.categories.getImages` | sortie sans latitude/longitude | impossible d'utiliser ce listing comme batch GPS | statique | moyenne/perf | élevée | prévoir agrégation/cache ou futur batch |
| Piwigo core 16.4 | `pwg.images.search` | sortie sans latitude/longitude | même risque de N+1 après recherche | statique | moyenne/perf | élevée | idem |
| Piwigo core 16.4 | `ws.php` enregistrement `pwg.images.setInfo` | core sans paramètres latitude/longitude ; admin + POST | l'écriture GPS du plugin n'est pas un contrat core | statique | moyenne/intégration | élevée | ne pas dépendre de ce contrat implicite |
| Piwigo core 2.9.5 → 16.4 | `ws_images_getInfo()` | noms de champs GPS stables observés ; valeurs non castées explicitement dans les deux versions | nécessité de normalisation côté client | comparaison statique | faible | moyenne à élevée | conserver une couche de normalisation |
| Piwigo core | issue #981 / signature `getInfo` | pas de batch `getInfo` disponible en 16.4 | N+1 pour carte de masse | API + issue upstream | moyenne | élevée | interdire boucle par marqueur ; revalider Piwigo 17+ |
| piwigo-openstreetmap | `include/functions.php::osm_items_have_latlon()` | tests latitude dupliqués ; longitude insuffisamment contrôlée | faux positifs/faux négatifs GPS | statique | faible | élevée | tester séparément latitude et longitude |
| piwigo-openstreetmap | `include/functions_map.php::osm_get_items()` | test non-zéro latitude dupliqué | rejet de l'équateur ; comportement asymétrique du méridien zéro | statique | faible | élevée | tester présence et bornes, pas vérité/non-zéro |
| piwigo-openstreetmap | `include/ws_functions.inc.php::osm_ws_images_setInfo()` | `empty()` rejette zéro | impossible d'écrire certaines coordonnées valides | statique | faible | élevée | présence explicite + numeric/finite/bounds |
| piwigo-openstreetmap | même hook | contrôle `$params`, écriture depuis `$_POST` | divergence entre données validées et données persistées | statique | moyenne | élevée | une seule source normalisée ; ne pas lire les globals bruts |
| piwigo-openstreetmap | détail retenu | problème de sécurité upstream confirmé statiquement | impact à coordonner avec Piwigo | privé | élevée | élevée | divulgation responsable |
| piwigo-openstreetmap | détail retenu | sink de sécurité upstream identifié | exploitabilité à confirmer | privé | à confirmer | moyenne | divulgation responsable |
| piwigo-openstreetmap | `leaflet/leaflet-src.js` | Leaflet 0.7.7 | dette sécurité/compatibilité et maintenance | statique | moyenne | élevée | passer à une version maintenue ; ne pas copier dans Drupal |
| piwigo-openstreetmap | `leaflet/*` | plusieurs extensions JS historiques embarquées | surface de dépendances ancienne | statique | moyenne | élevée | inventaire/versionnage/upgrade upstream ; ne pas absorber |
| piwigo-openstreetmap | `include/functions_map.php` baselayers | OSM standard peut être HTTP et utilise ancien modèle de sous-domaines | non-conformité à la politique actuelle OSMF | statique + politique | moyenne | élevée | HTTPS + URL OSMF actuelle exacte |
| piwigo-openstreetmap | même bloc | MapQuest direct et ancien Stamen/Fastly sont obsolètes | cartes cassées / dépendances non supportées | statique + annonces fournisseurs | moyenne | élevée | retirer/remplacer et rendre les fournisseurs configurables |
| piwigo-openstreetmap | `osmcopyright()` | retour anticipé rend le code d'attribution fournisseur inatteignable | attribution incorrecte pour plusieurs fonds | statique | moyenne conformité | élevée | attribution par fournisseur, visible et actuelle |
| Piwigo Display | `src/Value/GeoCoordinates.php` | WGS84 strict, paire complète, zéro accepté, axes Leaflet/GeoJSON explicites | réduit inversions et sentinelles erronées | tests/code | info/positif | élevée | conserver ce contrat |
| Piwigo Display | `piwigo_display.info.yml` / `composer.json` | aucune dépendance Geofield/Leaflet/Geofield Map | architecture légère et découplée | statique | info/positif | élevée | garder optionnel tant que besoin absent |
| Piwigo Display futur | couche de rendu/caching | un compte Piwigo privilégié peut fournir GPS privé à Drupal | fuite de localisation si payload/cache mal segmenté | revue d'architecture | élevée | élevée | access checks + cache contexts/tags + payload minimal |
| Piwigo Display futur | fournisseur de tuiles/géocodeur | service tiers apprend IP/référent/zone ; géocodeur reçoit la coordonnée exacte | confidentialité | revue d'architecture + politiques | élevée selon données | élevée | opt-in/configuration/privacy ; pas de reverse geocode implicite |
| OSMF tiles | politique tuiles | HTTPS, attribution, UA/referer selon client, cache, pas de prefetch/offline | blocage/service non conforme si ignoré | politique publique | moyenne | élevée | conformité explicite et fournisseur switchable |
| Nominatim public | politique Nominatim | 1 req/s max, identification, cache, pas d'autocomplete | blocage + confidentialité | politique publique | moyenne | élevée | ne pas appeler pour afficher du GPS ; intégration séparée si besoin |
| OSM editing API | politique API | API réservée à l'édition, pas au rendu | mauvaise architecture si utilisée | politique publique | faible | élevée | ne pas utiliser dans Piwigo Display |

## 8. Corrections qui concernent **notre module**

### À conserver dès maintenant

- `GeoCoordinates` comme frontière de validation ;
- zéro accepté ;
- bornes WGS84 strictes ;
- méthodes de conversion Leaflet/GeoJSON explicites ;
- absence de dépendance runtime à `piwigo-openstreetmap` ;
- absence de dépendance obligatoire Geofield/Leaflet/Geofield Map.

### À exiger avant une carte de production

1. **Accès** : ne sérialiser une coordonnée que si le média est visible pour l'utilisateur Drupal courant ou selon une politique explicitement définie.
2. **Cache** : faire varier/segmenter toute réponse contenant du GPS selon les mêmes dimensions d'accès que le média ; interdire un cache public partagé pour des points privés.
3. **Batch** : ne jamais appeler `pwg.images.getInfo` une fois par marqueur. Définir une couche d'agrégation/cache et un budget de requêtes.
4. **Rendu** : utiliser `textContent`/API DOM sûres ou une sanitisation Drupal explicite pour les popups ; aucune concaténation de HTML non fiable.
5. **Leaflet** : utiliser une version maintenue ; ne pas vendoriser 0.7.7 depuis le plugin Piwigo.
6. **Tuiles** : fournisseur configurable, HTTPS, attribution correcte, politique d'usage documentée ; prévoir la possibilité de changer de fournisseur sans modifier le modèle GPS.
7. **Vie privée** : ne pas déclencher automatiquement de Nominatim/reverse geocoding pour une image déjà géotaguée ; avertir/configurer tout service tiers.
8. **Écriture GPS éventuelle** : traiter comme une fonctionnalité séparée avec permission Drupal, méthode non-safe, CSRF, validation de paire et de bornes, et contrat Piwigo explicitement vérifié pour la version cible.

## 9. Corrections exclusivement **amont Piwigo OpenStreetMap**

Ces éléments ne doivent pas être « absorbés » par Piwigo Display et ne justifient pas une réécriture du plugin dans notre dépôt :

- corriger les contrôles latitude/longitude dupliqués ;
- remplacer les usages de `empty()` qui invalident zéro ;
- corriger la divergence `$params` / `$_POST` du hook `setInfo` ;
- traiter les deux constats de sécurité upstream par divulgation privée coordonnée, sans publier leurs détails techniques avant accord/correctif ;
- mettre à niveau Leaflet et les extensions embarquées ;
- retirer/remplacer les fournisseurs de tuiles obsolètes ;
- forcer HTTPS et suivre les URL/politiques actuelles ;
- réparer l'attribution par fournisseur.

Notre module doit **éviter** ces dépendances et ne dépendre d'aucune correction upstream pour fonctionner correctement.

## 10. Divulgation responsable

Deux constats de sécurité upstream existent et ne sont pas détaillés dans ce dépôt public.

- Le canal officiel de signalement privé est `security@piwigo.org`.
- Aucun test n'a été effectué sur une instance Piwigo tierce.
- Aucun PoC public n'est fourni.
- Aucun détail technique supplémentaire ne doit être publié avant correction et coordination avec l'équipe Piwigo.
- La publication coordonnée doit attendre le traitement du signalement et un délai de mise à jour approprié.

## 11. Conditions de GO pour la future cartographie

La cartographie peut être ajoutée à Piwigo Display lorsque les conditions suivantes sont satisfaites :

- [ ] aucune dépendance runtime ou copie de code depuis `piwigo-openstreetmap` ;
- [ ] source GPS = Piwigo core/API, puis validation `GeoCoordinates` ;
- [ ] contrôle d'accès Drupal avant création du payload de marqueurs ;
- [ ] stratégie cache/batch démontrant l'absence de N+1 `getInfo` ;
- [ ] Leaflet maintenu et géré comme dépendance Drupal/front séparée ;
- [ ] popups et labels encodés selon leur contexte, sans HTML non fiable concaténé ;
- [ ] fournisseur de tuiles configurable, HTTPS et attribution correcte ;
- [ ] conformité spécifique au fournisseur vérifiée au moment du déploiement ;
- [ ] aucune utilisation implicite de Nominatim pour des coordonnées déjà disponibles ;
- [ ] décision explicite sur la confidentialité des cartes de médias privés ;
- [ ] tests couvrant équateur, méridien zéro, `0,0`, bornes extrêmes, valeurs invalides/incomplètes, axes Leaflet et GeoJSON ;
- [ ] revalidation du contrat Piwigo si la version minimale supportée change, notamment si un batch GPS apparaît en Piwigo 17+.

## 12. Conclusion

# GO SOUS CONDITIONS

La future carte Piwigo Display est techniquement saine **si elle reste indépendante du plugin Piwigo OpenStreetMap** et traite Drupal comme frontière de validation, d'autorisation, de cache, d'encodage et de choix du fournisseur cartographique.

Le plugin officiel est utile comme source d'enseignements, pas comme dépendance : il contient des hypothèses historiques sur zéro, un contrat d'écriture GPS hors core, un stack Leaflet ancien, des fournisseurs de tuiles obsolètes et deux constats à traiter par divulgation responsable privée.

Le risque principal pour notre module n'est pas une inversion d'axes — le contrat actuel la prévient — mais **la combinaison compte de service Piwigo privilégié + cache/payload Drupal + service cartographique tiers**. C'est cette frontière qu'il faut concevoir et tester avant d'afficher des médias privés sur une carte.

## 13. Sources contrôlées

### Piwigo

- Piwigo 16.4.0 release note : https://piwigo.org/release-16.4.0
- API registrations : https://github.com/Piwigo/Piwigo/blob/16.4.0/ws.php
- `pwg.images` implementation : https://github.com/Piwigo/Piwigo/blob/16.4.0/include/ws_functions/pwg.images.php
- `pwg.categories` implementation : https://github.com/Piwigo/Piwigo/blob/16.4.0/include/ws_functions/pwg.categories.php
- Web-service invocation guards : https://github.com/Piwigo/Piwigo/blob/16.4.0/include/ws_core.inc.php
- Historical comparison 2.9.5 : https://github.com/Piwigo/Piwigo/blob/2.9.5/include/ws_functions/pwg.images.php
- Batch `getInfo` request #981 : https://github.com/Piwigo/Piwigo/issues/981
- Security policy : https://github.com/Piwigo/Piwigo/blob/master/SECURITY.md

### Piwigo OpenStreetMap

- Extension 16.b : https://piwigo.org/ext/index.php?eid=701
- Repository : https://github.com/Piwigo/piwigo-openstreetmap
- GPS helper : https://github.com/Piwigo/piwigo-openstreetmap/blob/master/include/functions.php
- Functional map/tile helpers : https://github.com/Piwigo/piwigo-openstreetmap/blob/master/include/functions_map.php
- Web-service GPS hook : https://github.com/Piwigo/piwigo-openstreetmap/blob/master/include/ws_functions.inc.php
- Leaflet embedded : https://github.com/Piwigo/piwigo-openstreetmap/blob/master/leaflet/leaflet-src.js
- Leaflet stable releases : https://leafletjs.com/download.html
- MapQuest legacy direct tiles retirement : https://lists.openstreetmap.org/pipermail/tile-serving/2016-June/003928.html
- Stamen legacy tiles retirement : https://maps.stamen.com/stadia-partnership/

### OpenStreetMap Foundation

- Tile Usage Policy : https://operations.osmfoundation.org/policies/tiles/
- Nominatim Usage Policy : https://operations.osmfoundation.org/policies/nominatim/
- Editing API Usage Policy : https://operations.osmfoundation.org/policies/api/
- Privacy Policy : https://osmfoundation.org/wiki/Privacy_Policy
- Services/tile privacy FAQ : https://osmfoundation.org/wiki/Services_and_tile_users_privacy_FAQ

### Drupal

- Geofield : https://www.drupal.org/project/geofield
- Leaflet : https://www.drupal.org/project/leaflet
- Geofield Map : https://www.drupal.org/project/geofield_map