# BELOTERO® — Doc locator

Backend de la carte « Trouver un centre » de la landing page BELOTERO®.
Même principe que `radiesse-landing`, adapté à un volume de données 20× supérieur
(279 centres contre 26).

## Différences avec le plugin RADIESSE

| | RADIESSE | BELOTERO |
|---|---|---|
| Transport vers le front | liste inline dans `wp_head` | route REST `/wp-json/belotero/v1/centers`, chargée à la 1re recherche |
| Géocodage | Nominatim, 1 requête par ligne, bloquant à l'import | API Adresse (data.gouv.fr), 1 requête par lot de 200, en AJAX après l'import |
| Stockage | `wp_options` | `wp_options` avec `autoload = false` |

Ces trois changements sont imposés par le volume : 279 centres inline, c'est ~40 Ko
ajoutés au HTML de chaque visite ; 279 appels Nominatim à 1 req/s, c'est près de 5 min de
géocodage synchrone, bien au-delà de `max_execution_time`.

## Mise en service

1. Copier `belotero-landing/` dans `wp-content/plugins/`, activer le plugin.
2. Menu **BELOTERO Centres** → importer `data/belotero-centres-import.csv`
   (les 279 lignes sont déjà géocodées : rien d'autre à faire).
3. Vérifier que `GET /wp-json/belotero/v1/centers` renvoie bien 279 objets.

La landing page lit `window.BELOTERO_CENTERS_URL`, injecté par le plugin. Hors
WordPress, `script.js` retombe sur le fichier statique `data/belotero-centres.json`.

## Mettre à jour la liste

Ré-importer un CSV remplace toute la liste. Colonnes :

```
name, name2, streetNumber, street, zip, city, region, country, lat, lng
```

Seules `name`, `zip`, `city` sont obligatoires. Les lignes sans `lat`/`lng` sont
géocodées après l'import, par lots, via le bouton « Géocoder les adresses restantes ».
Le bouton est reprenable : s'il est interrompu, le relancer repart là où il s'était arrêté.

## Source des données

Liste validée par Merz : `Belotero liste doclocator-1.xlsx`, onglet *LISTE FINALE*,
colonnes `Sold-To Name, Adresse, CP, Ville` (279 centres, octobre 2026). Elle
remplace l'export SAP initial de 505 lignes.

Le champ `Adresse` est tronqué à 30 caractères par SAP et mélange numéro et
libellé de rue : la conversion sépare le numéro, normalise la casse (tout est en
capitales dans le fichier), puis géocode par lot en trois passes — adresse + code
postal, puis texte libre (pour les codes CEDEX, non géographiques), puis commune.
