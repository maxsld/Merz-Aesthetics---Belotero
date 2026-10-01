# BELOTERO® — Landing page et doc locator

Plugin WordPress de la landing BELOTERO® France : template de page autonome
(même principe que la landing RADIESSE) et doc locator avec import CSV de la
liste des centres.

## Installation

1. Envoyer le dossier `belotero-landing/` dans le dossier des plugins
   (`app/plugins/` sur l'installation Bedrock de merzaesthetics.fr), l'activer.
2. Créer une page, choisir le modèle **BELOTERO Landing**, publier.

La carte fonctionne immédiatement : le plugin embarque la liste validée par Merz
(279 centres, octobre 2026, déjà géolocalisés). Un import depuis l'admin la
remplace ; « Supprimer la liste importée » y ramène.

## Mettre à jour la liste des centres

Menu **BELOTERO Centres** :

1. Ouvrir le fichier Merz dans Excel, *Fichier › Enregistrer sous › CSV*.
2. Importer le fichier. Aucun renommage de colonne n'est nécessaire : `Sold-To
   Name`, `Adresse`, `CP`, `Ville` (ou `Name`, `Street Name`, `Postal Code`,
   `Location`) sont reconnus. Séparateur `,` ou `;`, encodage UTF-8 ou celui
   d'Excel (Windows-1252) — les accents sont conservés.
3. Cliquer sur **Localiser les adresses** : géocodage par lots via l'API
   Adresse (data.gouv.fr), quelques secondes pour 300 centres. Les adresses
   introuvables sont listées nommément et n'apparaissent pas sur la carte.

**Télécharger la liste (CSV)** exporte la liste en cours, coordonnées
comprises : le fichier se réimporte tel quel, sans relancer de géocodage.

## Ce qui a été repris des corrections de la landing RADIESSE

| Problème rencontré sur RADIESSE | Ici |
|---|---|
| Bandeau cookies (Osano) bloquant la carte même après acceptation | Pas de blocage par consentement sur la carte |
| Doc locator plafonné à 10 résultats (« Paris » : 10 centres sur 48) | Plafond à 50 (« Paris » : 49 centres) |
| Code postal résolu au centre du département | Géocodage de la saisie via l'API Adresse, puis Nominatim, tables départementales en dernier recours |
| Export CSV qui téléchargeait une page HTML | Export déclenché sur `admin_init`, avant tout affichage |
| Import refusant les fichiers Merz (en-têtes exacts exigés) | En-têtes reconnus par alias, encodage et séparateur détectés |
| Adresses non géocodables affichées comme marqueurs cassés | Écartées de la carte et listées dans l'admin |
| Colonnes internes publiées dans la page | Seuls nom, adresse et coordonnées sortent de l'API |
| Favicon lié mais absent | Jeu d'icônes Merz Aesthetics embarqué |
| Vidéo hero ne démarrant pas toujours en production | Muet forcé, relance de la lecture à chaque étape du chargement |
| Template maintenu à la main en parallèle du site | Template **généré** depuis le site (voir ci-dessous) |
| CSS du thème qui s'appliquait à la page (~1 500 `!important`) | Feuilles du thème retirées sur cette page uniquement |
| Fichiers inutilisés et archive de 34 Mo dans le dépôt | Seuls les fichiers référencés sont embarqués ; l'archive n'est pas versionnée |

## Pas de ressource tierce au chargement

Leaflet, les polices (Aeonik Pro, Playfair Display) et les icônes sont hébergés
dans le plugin : aucune IP visiteur n'est transmise à un CDN ni à Google Fonts.
Les seuls appels externes sont la vidéo (Vimeo), les tuiles de la carte
(OpenStreetMap) et, lors d'une recherche, l'API Adresse.

## Intégration au site mère

- Le template appelle `wp_head()`, `wp_body_open()` et `wp_footer()` : le
  bandeau Osano et Matomo Tag Manager s'y branchent. Le clic sur « Trouver un
  centre » envoie l'événement Matomo `doclocator_open`, comme sur RADIESSE.
- La page a son propre header et son propre footer, avec les liens légaux de
  merzaesthetics.fr — vérifié sur merzaesthetics.fr/radiesse, le site mère
  n'injecte ni l'un ni l'autre dans ces pages.
- Les URL de la donnée structurée (JSON-LD) suivent l'adresse réelle de la page.
- Si le retrait des feuilles du thème pose problème :
  `add_filter( 'belotero_dequeue_theme_styles', '__return_false' );`

## Développement

Le template, `belotero.css` et `belotero.js` sont **générés** à partir du site
statique (`index.html`, `style.css`, `script.js`). Ne pas les modifier ici :

```
python3 build-plugin.py          # régénère le plugin
python3 build-plugin.py --zip    # + dist/belotero-landing.zip à installer
```

Nouvelle liste Merz : `python3 tools/build-centres.py "liste.xlsx"`, puis
`python3 build-plugin.py`.
