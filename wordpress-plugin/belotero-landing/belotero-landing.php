<?php
/**
 * Plugin Name:  BELOTERO® – Landing Page
 * Description:  Landing page BELOTERO® France (template de page autonome) et doc locator : import CSV de la liste des centres, géocodage par lots, API de lecture pour la carte.
 * Version:      2.0.0
 * Requires PHP: 7.4
 * Author:       Merz Aesthetics France
 * License:      GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BELOTERO_VERSION', '2.0.0' );
define( 'BELOTERO_DIR', plugin_dir_path( __FILE__ ) );
define( 'BELOTERO_URL', plugin_dir_url( __FILE__ ) );

const BELOTERO_TEMPLATE  = 'belotero-landing';
const BELOTERO_OPTION    = 'belotero_centers';        // liste importée (autoload = false)
const BELOTERO_STAMP     = 'belotero_centers_stamp';  // version de la liste, sert d'ETag
const BELOTERO_GEO_BATCH = 200;                       // adresses par appel de géocodage

/* =========================================================================
 * 1. Template de page
 *    Document HTML autonome, comme la landing RADIESSE. Il appelle wp_head(),
 *    wp_body_open() et wp_footer() : le bandeau cookies et la mesure
 *    d'audience du site mère s'y branchent normalement.
 * ========================================================================= */
add_filter( 'theme_page_templates', function ( $templates ) {
	$templates[ BELOTERO_TEMPLATE ] = 'BELOTERO Landing';
	return $templates;
} );

add_filter( 'template_include', function ( $template ) {
	if ( belotero_is_landing() ) {
		$file = BELOTERO_DIR . 'templates/page-belotero.php';
		if ( file_exists( $file ) ) return $file;
	}
	return $template;
} );

function belotero_is_landing() {
	if ( ! is_page() ) return false;
	return get_post_meta( get_the_ID(), '_wp_page_template', true ) === BELOTERO_TEMPLATE;
}

/* =========================================================================
 * 2. Isolation vis-à-vis du thème
 *    Sur RADIESSE, le CSS du thème s'appliquait à la landing et il a fallu
 *    près de 1 500 « !important » pour lui résister. Ici on retire plutôt les
 *    feuilles de style du thème sur cette page uniquement. Les feuilles des
 *    autres plugins (bandeau cookies…) sont conservées.
 *    Pour désactiver : add_filter( 'belotero_dequeue_theme_styles', '__return_false' );
 * ========================================================================= */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! belotero_is_landing() ) return;

	foreach ( [ 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles' ] as $h ) {
		wp_dequeue_style( $h );
	}

	if ( ! apply_filters( 'belotero_dequeue_theme_styles', true ) ) return;

	$theme_roots = array_unique( [ get_template_directory_uri(), get_stylesheet_directory_uri() ] );
	$styles      = wp_styles();
	foreach ( (array) $styles->queue as $handle ) {
		$src = isset( $styles->registered[ $handle ] ) ? (string) $styles->registered[ $handle ]->src : '';
		foreach ( $theme_roots as $root ) {
			if ( $src !== '' && strpos( $src, $root ) === 0 ) {
				wp_dequeue_style( $handle );
				break;
			}
		}
	}
}, 100 );

/**
 * Indique à la page où lire la liste des centres.
 * Quelques octets dans le <head>, pas la liste entière : elle n'est chargée
 * qu'à l'affichage de la carte.
 */
add_action( 'wp_head', function () {
	if ( ! belotero_is_landing() ) return;
	printf(
		"<script>window.BELOTERO_CENTERS_URL=%s;</script>\n",
		wp_json_encode( esc_url_raw( rest_url( 'belotero/v1/centers' ) ) )
	);
}, 1 );

/* =========================================================================
 * 3. Données des centres
 *    Liste importée par l'admin si elle existe, sinon la liste fournie avec
 *    le plugin : la carte fonctionne dès l'activation.
 * ========================================================================= */
function belotero_get_imported_centers() {
	$c = get_option( BELOTERO_OPTION );
	return is_array( $c ) ? $c : [];
}

function belotero_get_bundled_centers() {
	$file = BELOTERO_DIR . 'assets/data/belotero-centres.json';
	if ( ! is_readable( $file ) ) return [];
	$data = json_decode( (string) file_get_contents( $file ), true );
	return is_array( $data ) ? $data : [];
}

/** Liste réellement affichée sur la carte. */
function belotero_get_centers() {
	$imported = belotero_get_imported_centers();
	return $imported ? $imported : belotero_get_bundled_centers();
}

function belotero_has_coords( $c ) {
	return isset( $c['lat'], $c['lng'] ) && $c['lat'] !== '' && $c['lng'] !== '';
}

function belotero_save_centers( array $centers ) {
	// autoload = false : la liste ne doit pas être chargée à chaque requête WP
	update_option( BELOTERO_OPTION, array_values( $centers ), false );
	update_option( BELOTERO_STAMP, (string) time(), false );
}

/* =========================================================================
 * 4. API de lecture — GET /wp-json/belotero/v1/centers
 * ========================================================================= */
add_action( 'rest_api_init', function () {
	register_rest_route( 'belotero/v1', '/centers', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) {
			$imported = belotero_get_imported_centers();
			$source   = $imported ? get_option( BELOTERO_STAMP, '0' ) : 'bundled-' . BELOTERO_VERSION;
			$centers  = belotero_get_centers();
			$etag     = '"' . md5( $source . '-' . count( $centers ) ) . '"';

			if ( trim( (string) $req->get_header( 'if_none_match' ) ) === $etag ) {
				return new WP_REST_Response( null, 304 );
			}

			// Seuls les champs affichés sont publiés : aucune colonne interne
			// (téléphone, code région, score de géocodage…) ne sort de la base.
			$payload = [];
			foreach ( $centers as $c ) {
				if ( ! belotero_has_coords( $c ) ) continue;
				$row = [
					'name'         => (string) ( $c['name'] ?? '' ),
					'streetNumber' => (string) ( $c['streetNumber'] ?? '' ),
					'street'       => (string) ( $c['street'] ?? '' ),
					'zip'          => (string) ( $c['zip'] ?? '' ),
					'city'         => (string) ( $c['city'] ?? '' ),
					'lat'          => (float) $c['lat'],
					'lng'          => (float) $c['lng'],
				];
				if ( ! empty( $c['name2'] ) ) $row['name2'] = (string) $c['name2'];
				$payload[] = $row;
			}

			$res = new WP_REST_Response( $payload, 200 );
			$res->header( 'ETag', $etag );
			$res->header( 'Cache-Control', 'public, max-age=3600' );
			return $res;
		},
	] );
} );

/* =========================================================================
 * 5. Export CSV
 *    Déclenché sur admin_init, AVANT tout affichage. Sur RADIESSE l'export
 *    tournait dans le rendu de la page admin : les en-têtes HTTP partaient
 *    trop tard et le fichier téléchargé était une page HTML avec les lignes
 *    enfouies en bas, impossible à rééditer ou réimporter.
 * ========================================================================= */
add_action( 'admin_init', function () {
	if ( ! isset( $_GET['page'], $_GET['belotero_export'] ) ) return;
	if ( $_GET['page'] !== 'belotero-centers' ) return;
	if ( ! current_user_can( 'manage_options' ) ) return;
	check_admin_referer( 'belotero_export' );

	$centers = belotero_get_centers();
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="belotero-centres-' . gmdate( 'Y-m-d' ) . '.csv"' );

	$out  = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // BOM : Excel lit alors l'UTF-8 correctement
	$cols = [ 'name', 'name2', 'streetNumber', 'street', 'zip', 'city', 'country', 'lat', 'lng' ];
	fputcsv( $out, $cols );
	foreach ( $centers as $c ) {
		fputcsv( $out, array_map( fn( $k ) => $c[ $k ] ?? ( $k === 'country' ? 'France' : '' ), $cols ) );
	}
	fclose( $out );
	exit;
} );

/* =========================================================================
 * 6. Import CSV
 *    Accepte tel quel un fichier sorti d'Excel ou de la base Merz : en-têtes
 *    reconnus par alias, séparateur virgule ou point-virgule, encodage UTF-8
 *    ou Windows-1252 (ce que produit « Enregistrer en CSV » dans un Excel
 *    français). Sur RADIESSE, un export Merz était rejeté faute d'en-têtes
 *    exacts.
 * ========================================================================= */

/** « Code Postal », « code_postal », « CODEPOSTAL » → « codepostal ». */
function belotero_normalize_key( $h ) {
	$h = ltrim( trim( (string) $h ), "\xEF\xBB\xBF" );
	if ( function_exists( 'remove_accents' ) ) $h = remove_accents( $h );
	return preg_replace( '/[^a-z0-9]/', '', strtolower( $h ) );
}

/**
 * En-têtes acceptés pour chaque champ, déjà normalisés. Couvre les deux
 * formats Merz rencontrés : l'export SAP (« Name », « Location », « Postal
 * Code », « Street Name ») et la liste validée (« Sold-To Name », « Adresse »,
 * « CP », « Ville »), ainsi que celui de la landing RADIESSE.
 */
function belotero_header_aliases() {
	return [
		'name'         => [ 'name', 'nom', 'centre', 'center', 'soldtoname', 'soldto', 'namedocloc', 'nomdocloc', 'raisonsociale', 'praticien' ],
		'name2'        => [ 'name2', 'nom2', 'complement', 'complementnom' ],
		'street'       => [ 'street', 'streetname', 'rue', 'adresse', 'address', 'adresse1' ],
		'streetNumber' => [ 'streetnumber', 'numero', 'numerorue', 'housenumber', 'no' ],
		'zip'          => [ 'zip', 'zipcode', 'postalcode', 'codepostal', 'cp' ],
		'city'         => [ 'city', 'ville', 'town', 'commune', 'location' ],
		'country'      => [ 'country', 'pays' ],
		'lat'          => [ 'lat', 'latitude' ],
		'lng'          => [ 'lng', 'lon', 'long', 'longitude' ],
	];
}

/**
 * Associe chaque champ à une colonne. Si plusieurs colonnes conviennent
 * (« ZIP » et « Postal Code » dans le même fichier), on garde celle qui
 * contient vraiment des codes postaux à 5 chiffres.
 */
function belotero_map_columns( array $headers, array $rows ) {
	$map = [];
	foreach ( belotero_header_aliases() as $field => $aliases ) {
		$candidates = [];
		foreach ( $headers as $i => $h ) {
			if ( in_array( belotero_normalize_key( $h ), $aliases, true ) ) $candidates[] = $i;
		}
		if ( ! $candidates ) continue;
		if ( count( $candidates ) === 1 ) { $map[ $field ] = $candidates[0]; continue; }

		$best = $candidates[0]; $bestScore = -1;
		foreach ( $candidates as $i ) {
			$score = 0;
			foreach ( $rows as $r ) {
				$v = isset( $r[ $i ] ) ? trim( (string) $r[ $i ] ) : '';
				if ( $v === '' || $v === '#' ) continue;
				$score++;
				if ( $field === 'zip' && preg_match( '/^\d{5}$/', $v ) ) $score += 2;
			}
			if ( $score > $bestScore ) { $bestScore = $score; $best = $i; }
		}
		$map[ $field ] = $best;
	}
	return $map;
}

/** Lit le fichier, corrige l'encodage et rend [ en-têtes, lignes ]. */
function belotero_read_csv( $filepath ) {
	$raw = @file_get_contents( $filepath );
	if ( $raw === false ) return new WP_Error( 'csv_open', "Impossible d'ouvrir le fichier." );

	$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
	if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $raw, 'UTF-8' ) ) {
		// Excel français enregistre ses CSV en Windows-1252 : sans conversion,
		// « Céline » deviendrait « C?line » sur la carte.
		$raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
	}

	$first = strtok( $raw, "\n" );
	$sep   = ( substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ) ? ';' : ',';

	$fh = fopen( 'php://temp', 'r+' );
	fwrite( $fh, $raw );
	rewind( $fh );

	$headers = null;
	$rows    = [];
	while ( ( $row = fgetcsv( $fh, 0, $sep ) ) !== false ) {
		if ( ! array_filter( $row, fn( $v ) => trim( (string) $v ) !== '' ) ) continue;
		if ( $headers === null ) { $headers = array_map( 'trim', $row ); continue; }
		$rows[] = $row;
	}
	fclose( $fh );

	if ( $headers === null ) return new WP_Error( 'csv_empty', 'Fichier vide : aucune ligne d\'en-tête.' );
	return [ $headers, $rows ];
}

/**
 * Casse normale pour les rues et les villes, SEULEMENT si la valeur est
 * entièrement en capitales (« 6 SQUARE PETRARQUE » → « 6 Square Petrarque »).
 * Mêmes règles que tools/casse.py, qui produit la liste fournie avec le plugin.
 */
function belotero_titlecase( $s ) {
	// « BIARRITZ. » : ponctuation parasite en fin de champ dans le fichier Merz
	$s = trim( rtrim( trim( preg_replace( '/\s+/u', ' ', (string) $s ) ), '.,;:' ) );
	if ( $s === '' || preg_match( '/[a-zà-ÿ]/u', $s ) ) return $s;

	$small    = [ 'de', 'du', 'des', 'la', 'le', 'les', 'et', 'sur', 'sous', 'en', 'aux', 'au', 'a', 'd', 'l' ];
	$acronyms = [ 'RD', 'RN', 'CD', 'VC', 'ZA', 'ZI', 'ZAC', 'ZAE' ];
	$cap      = fn( $w ) => mb_strtoupper( mb_substr( $w, 0, 1 ) ) . mb_strtolower( mb_substr( $w, 1 ) );
	$first    = true;
	$out      = '';
	foreach ( preg_split( '/(\s+|-)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE ) as $tok ) {
		if ( trim( $tok ) === '' || $tok === '-' ) { $out .= $tok; continue; }
		$roman = preg_match( '/^(?=[IVX]{2,}$)X{0,3}(?:IX|IV|V?I{0,3})$/i', $tok ); // Édouard VII, Louis XIV
		if ( preg_match( '/^[A-Za-z]{1,3}\d+[A-Za-z]?$/', $tok ) || in_array( mb_strtoupper( $tok ), $acronyms, true ) || $roman ) {
			$out .= mb_strtoupper( $tok );
		} elseif ( preg_match( "/^(.*?)(['’])(.*)$/u", $tok, $m ) ) {
			$out .= ( $first ? $cap( $m[1] ) : mb_strtolower( $m[1] ) ) . $m[2] . $cap( $m[3] );
		} elseif ( ! $first && in_array( mb_strtolower( $tok ), $small, true ) ) {
			$out .= mb_strtolower( $tok );
		} else {
			$out .= $cap( $tok );
		}
		$first = false;
	}
	return $out;
}

function belotero_parse_csv( $filepath ) {
	$read = belotero_read_csv( $filepath );
	if ( is_wp_error( $read ) ) return $read;
	[ $headers, $rows ] = $read;

	$map     = belotero_map_columns( $headers, $rows );
	$missing = array_diff( [ 'name', 'zip', 'city' ], array_keys( $map ) );
	if ( $missing ) {
		$aliases = belotero_header_aliases();
		$hints   = array_map( fn( $f ) => sprintf( '« %s » (ou : %s)', $f, implode( ', ', array_slice( $aliases[ $f ], 1, 4 ) ) ), $missing );
		return new WP_Error( 'csv_headers',
			'Colonne(s) introuvable(s) : ' . implode( ' ; ', $hints ) . '. Colonnes du fichier : ' . implode( ', ', $headers ) );
	}

	$centers = [];
	$seen    = [];
	foreach ( $rows as $row ) {
		$c = [];
		foreach ( $map as $field => $i ) {
			$v = isset( $row[ $i ] ) ? trim( (string) $row[ $i ] ) : '';
			if ( $v !== '' && $v !== '#' ) $c[ $field ] = $v;
		}
		if ( empty( $c['name'] ) ) continue;

		// Code postal : Excel perd le 0 initial (« 6130 » pour 06130).
		if ( isset( $c['zip'] ) && preg_match( '/^\d{4}$/', $c['zip'] ) ) $c['zip'] = '0' . $c['zip'];

		// Les exports Merz collent le numéro au libellé (« 893 AV DU MARÉCHAL
		// JUIN ») : on l'isole pour l'affichage et pour l'itinéraire.
		if ( empty( $c['streetNumber'] ) && ! empty( $c['street'] )
			&& preg_match( '/^\s*(\d+\s*(?:bis|ter|quater|[a-z])?)\s+(.+)$/iu', $c['street'], $m ) ) {
			$c['streetNumber'] = strtoupper( trim( $m[1] ) );
			$c['street']       = trim( $m[2] );
			// « 23 23 PLACE SÉBASTOPOL » : numéro répété dans le libellé
			if ( preg_match( '/^(\d+)\s+(.+)$/u', $c['street'], $m2 ) && $m2[1] === $c['streetNumber'] ) {
				$c['street'] = $m2[2];
			}
		}

		// Noms laissés tels que fournis : sigles (SELARL, SAS, CH) et marques
		// seraient faussés par une remise en casse. Rues et villes, elles, sont
		// remises en casse normale si elles arrivent entièrement en capitales.
		$c['name'] = trim( preg_replace( '/\s+/u', ' ', $c['name'] ) );
		foreach ( [ 'street', 'city' ] as $k ) {
			if ( isset( $c[ $k ] ) ) $c[ $k ] = belotero_titlecase( $c[ $k ] );
		}

		$c['country'] = $c['country'] ?? 'France';
		foreach ( [ 'lat', 'lng' ] as $k ) {
			if ( isset( $c[ $k ] ) ) $c[ $k ] = (float) str_replace( ',', '.', $c[ $k ] );
		}
		if ( empty( $c['lat'] ) || empty( $c['lng'] ) ) unset( $c['lat'], $c['lng'] );

		$key = mb_strtoupper( $c['name'] . '|' . ( $c['streetNumber'] ?? '' ) . ' ' . ( $c['street'] ?? '' ) . '|' . ( $c['zip'] ?? '' ) );
		if ( isset( $seen[ $key ] ) ) continue;
		$seen[ $key ] = true;
		$centers[]    = $c;
	}

	if ( ! $centers ) return new WP_Error( 'csv_empty', 'Aucun centre trouvé dans le fichier (colonne du nom vide).' );
	return $centers;
}

/* =========================================================================
 * 7. Géocodage par lots
 *    Lancé en AJAX après l'import, par lots de 200 : un import de quelques
 *    centaines de lignes ne dépasse jamais max_execution_time. France : API
 *    Adresse (data.gouv.fr), un appel par lot. Ailleurs : Nominatim.
 * ========================================================================= */
function belotero_geocode_batch() {
	$centers = belotero_get_imported_centers();
	$todo    = [];
	foreach ( $centers as $i => $c ) {
		if ( ! belotero_has_coords( $c ) && empty( $c['geo_failed'] ) ) $todo[ $i ] = $c;
		if ( count( $todo ) >= BELOTERO_GEO_BATCH ) break;
	}
	if ( ! $todo ) return [ 'done' => 0, 'left' => 0 ];

	$fr = $other = [];
	foreach ( $todo as $i => $c ) {
		$country = mb_strtolower( $c['country'] ?? 'france' );
		if ( in_array( $country, [ 'france', 'fr' ], true ) ) $fr[ $i ] = $c; else $other[ $i ] = $c;
	}

	$done = 0;
	if ( $fr ) {
		// 1re passe : adresse contrainte par le code postal
		$done += belotero_geocode_ban( $centers, $fr, true );
		// 2e passe sans le code postal, pour les CEDEX (non géographiques)
		$retry = array_filter( $fr, fn( $c, $i ) => ! belotero_has_coords( $centers[ $i ] ), ARRAY_FILTER_USE_BOTH );
		if ( $retry ) $done += belotero_geocode_ban( $centers, $retry, false );
		// 3e passe : la commune seule, pour les adresses sans numéro (« Galerie
		// Passage Royal »). Un centre placé au centre de sa ville reste trouvable
		// par une recherche sur cette ville ; un centre écarté ne l'est plus.
		$retry = array_filter( $fr, fn( $c, $i ) => ! belotero_has_coords( $centers[ $i ] ), ARRAY_FILTER_USE_BOTH );
		if ( $retry ) {
			$town = array_map( fn( $c ) => array_merge( $c, [ 'streetNumber' => '', 'street' => '' ] ), $retry );
			$done += belotero_geocode_ban( $centers, $town, true );
		}
		foreach ( $fr as $i => $c ) {
			if ( ! belotero_has_coords( $centers[ $i ] ) ) $centers[ $i ]['geo_failed'] = true;
		}
	}
	foreach ( $other as $i => $c ) {
		$coords = belotero_geocode_nominatim( $c );
		if ( $coords ) { $centers[ $i ] = array_merge( $centers[ $i ], $coords ); $done++; }
		else           { $centers[ $i ]['geo_failed'] = true; }
		usleep( 1100000 ); // Nominatim : 1 requête par seconde au maximum
	}

	belotero_save_centers( $centers );

	$left = count( array_filter( $centers, fn( $c ) => ! belotero_has_coords( $c ) && empty( $c['geo_failed'] ) ) );
	return [ 'done' => $done, 'left' => $left ];
}

function belotero_geocode_ban( array &$centers, array $batch, $with_zip ) {
	$cell = function ( $v ) {
		$v = str_replace( [ "\r", "\n" ], ' ', (string) $v );
		return strpbrk( $v, ",\"" ) !== false ? '"' . str_replace( '"', '""', $v ) . '"' : $v;
	};
	$csv = "idx,adresse,cp,ville\n";
	foreach ( $batch as $i => $c ) {
		$csv .= implode( ',', [
			(int) $i,
			$cell( trim( ( $c['streetNumber'] ?? '' ) . ' ' . ( $c['street'] ?? '' ) ) ),
			$cell( $c['zip'] ?? '' ),
			$cell( trim( preg_replace( '/\s*cedex.*$/i', '', $c['city'] ?? '' ) ) ),
		] ) . "\n";
	}

	$fields = [ 'columns' => [ 'adresse', 'ville' ] ];
	if ( $with_zip ) $fields['postcode'] = [ 'cp' ];

	$boundary = wp_generate_password( 24, false );
	$body     = '';
	foreach ( $fields as $name => $values ) {
		foreach ( $values as $v ) {
			$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$v\r\n";
		}
	}
	$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"data\"; filename=\"centres.csv\"\r\n"
		. "Content-Type: text/csv\r\n\r\n$csv\r\n--$boundary--\r\n";

	$res = wp_remote_post( 'https://api-adresse.data.gouv.fr/search/csv/', [
		'timeout' => 60,
		'headers' => [ 'Content-Type' => "multipart/form-data; boundary=$boundary" ],
		'body'    => $body,
	] );
	if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) return 0;

	$lines = preg_split( '/\r\n|\n/', trim( wp_remote_retrieve_body( $res ) ) );
	$col   = array_flip( str_getcsv( (string) array_shift( $lines ) ) );
	if ( ! isset( $col['idx'], $col['latitude'], $col['longitude'] ) ) return 0;

	$done = 0;
	foreach ( $lines as $line ) {
		if ( $line === '' ) continue;
		$r   = str_getcsv( $line );
		$idx = (int) ( $r[ $col['idx'] ] ?? -1 );
		if ( ! isset( $centers[ $idx ] ) ) continue;
		$lat = $r[ $col['latitude'] ] ?? '';
		$lng = $r[ $col['longitude'] ] ?? '';
		if ( $lat === '' || $lng === '' ) continue;
		$centers[ $idx ]['lat'] = (float) $lat;
		$centers[ $idx ]['lng'] = (float) $lng;
		unset( $centers[ $idx ]['geo_failed'] );
		$done++;
	}
	return $done;
}

function belotero_geocode_nominatim( $c ) {
	$q   = implode( ' ', array_filter( [ $c['streetNumber'] ?? '', $c['street'] ?? '', $c['zip'] ?? '', $c['city'] ?? '', $c['country'] ?? '' ] ) );
	$res = wp_remote_get( 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=' . rawurlencode( $q ), [
		'headers' => [ 'User-Agent' => 'BeloteroLanding/' . BELOTERO_VERSION . ' (merzaesthetics.fr)' ],
		'timeout' => 10,
	] );
	if ( is_wp_error( $res ) ) return null;
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $body[0] ) ) return null;
	return [ 'lat' => (float) $body[0]['lat'], 'lng' => (float) $body[0]['lon'] ];
}

add_action( 'wp_ajax_belotero_geocode_batch', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );
	check_ajax_referer( 'belotero_geocode' );
	wp_send_json_success( belotero_geocode_batch() );
} );

/* =========================================================================
 * 8. Écran d'administration
 * ========================================================================= */
add_action( 'admin_menu', function () {
	add_menu_page( 'BELOTERO® — Centres', 'BELOTERO Centres', 'manage_options',
		'belotero-centers', 'belotero_admin_page', 'dashicons-location-alt', 30 );
} );

function belotero_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	$notices = [];

	if ( isset( $_POST['belotero_import'] ) ) {
		check_admin_referer( 'belotero_centers' );
		if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
			$notices[] = [ 'error', 'Aucun fichier sélectionné.' ];
		} else {
			$parsed = belotero_parse_csv( $_FILES['csv_file']['tmp_name'] );
			if ( is_wp_error( $parsed ) ) {
				$notices[] = [ 'error', $parsed->get_error_message() ];
			} else {
				belotero_save_centers( $parsed );
				$geo = count( array_filter( $parsed, 'belotero_has_coords' ) );
				$notices[] = [ 'success', sprintf( '%d centre(s) importé(s), dont %d déjà localisé(s).%s',
					count( $parsed ), $geo, $geo < count( $parsed ) ? ' Lancez la localisation des adresses ci-dessous.' : '' ) ];
			}
		}
	}

	if ( isset( $_POST['belotero_reset'] ) ) {
		check_admin_referer( 'belotero_centers' );
		delete_option( BELOTERO_OPTION );
		delete_option( BELOTERO_STAMP );
		$notices[] = [ 'success', 'Liste importée supprimée : la carte affiche à nouveau la liste fournie avec le plugin.' ];
	}

	$imported = belotero_get_imported_centers();
	$centers  = belotero_get_centers();
	$total    = count( $centers );
	$located  = count( array_filter( $centers, 'belotero_has_coords' ) );
	$failed   = array_values( array_filter( $centers, fn( $c ) => ! empty( $c['geo_failed'] ) ) );
	$pending  = $total - $located - count( $failed );
	$box      = 'background:#fff;border:1px solid #c3c4c7;padding:20px 24px;max-width:780px;margin-top:20px;';
	?>
	<div class="wrap">
		<h1>BELOTERO® — Centres</h1>

		<?php foreach ( $notices as [ $type, $msg ] ) : ?>
			<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endforeach; ?>

		<div style="<?php echo esc_attr( $box ); ?>">
			<h2 style="margin-top:0;">Liste affichée sur la carte</h2>
			<?php
			$parts = [ sprintf( '<strong>%d</strong> %s sur la carte', $located, $located > 1 ? 'centres' : 'centre' ) ];
			if ( $pending ) $parts[] = sprintf( '<strong>%d</strong> en attente de localisation', $pending );
			if ( $failed )  $parts[] = sprintf( '<strong style="color:#b32d2e">%d</strong> non localisable%s', count( $failed ), count( $failed ) > 1 ? 's' : '' );
			?>
			<p>
				<?php echo $imported ? 'Liste <strong>importée</strong>' : 'Liste <strong>fournie avec le plugin</strong> (aucun import pour l\'instant)'; ?>
				— <?php echo wp_kses( implode( ', ', $parts ), [ 'strong' => [ 'style' => [] ] ] ); ?>.
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=belotero-centers&belotero_export=1' ), 'belotero_export' ) ); ?>">Télécharger la liste (CSV)</a>
				<?php if ( $pending ) : ?>
					<button type="button" class="button button-primary" id="belotero-geocode"><?php echo $pending > 1 ? 'Localiser les ' . (int) $pending . ' adresses' : 'Localiser l\'adresse'; ?></button>
				<?php endif; ?>
			</p>
			<p id="belotero-geocode-status" style="display:none;"></p>

			<?php if ( $failed ) : ?>
				<p style="color:#b32d2e;margin-bottom:6px;"><strong>Adresses non localisables</strong> — absentes de la carte, à corriger dans le fichier puis à réimporter :</p>
				<ul style="list-style:disc;margin-left:20px;">
					<?php foreach ( array_slice( $failed, 0, 50 ) as $c ) : ?>
						<li><?php echo esc_html( sprintf( '%s — %s %s, %s %s', $c['name'], $c['streetNumber'] ?? '', $c['street'] ?? '', $c['zip'] ?? '', $c['city'] ?? '' ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div style="<?php echo esc_attr( $box ); ?>">
			<h2 style="margin-top:0;">Importer une nouvelle liste</h2>
			<p>Fichier <strong>CSV</strong> (dans Excel : <em>Fichier › Enregistrer sous › CSV</em>). L'import <strong>remplace</strong> toute la liste.</p>
			<p>Les en-têtes des fichiers Merz sont reconnus tels quels, sans renommage :
				<code>Sold-To Name</code>, <code>Adresse</code>, <code>CP</code>, <code>Ville</code> —
				ou <code>Name</code>, <code>Street Name</code>, <code>Postal Code</code>, <code>Location</code>.
				Séparateur virgule ou point-virgule, encodage UTF-8 ou celui d'Excel. Le numéro de rue est séparé
				automatiquement du libellé (« 893 AV DU MARÉCHAL JUIN »).</p>
			<p>Les coordonnées GPS sont calculées après l'import. Si le fichier contient déjà des colonnes
				<code>lat</code> et <code>lng</code>, elles sont reprises telles quelles.</p>
			<p><a class="button" download href="<?php echo esc_url( BELOTERO_URL . 'assets/centres-template.csv' ); ?>">Télécharger un modèle</a></p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'belotero_centers' ); ?>
				<p><input type="file" name="csv_file" accept=".csv,text/csv" required></p>
				<p><button class="button button-primary" name="belotero_import">Importer</button></p>
			</form>
		</div>

		<?php if ( $imported ) : ?>
		<div style="<?php echo esc_attr( $box ); ?>">
			<h2 style="margin-top:0;">Revenir à la liste fournie avec le plugin</h2>
			<form method="post"><?php wp_nonce_field( 'belotero_centers' ); ?>
				<button class="button" name="belotero_reset"
					onclick="return confirm('Supprimer la liste importée (<?php echo (int) count( $imported ); ?> centres) ?')">Supprimer la liste importée</button>
			</form>
		</div>
		<?php endif; ?>

		<h2>Aperçu (50 premiers)</h2>
		<table class="widefat striped" style="max-width:1100px;">
			<thead><tr><th>Nom</th><th>Adresse</th><th>Ville</th><th>Position</th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( $centers, 0, 50 ) as $c ) : ?>
				<tr>
					<td><?php echo esc_html( $c['name'] ?? '' ); ?><?php if ( ! empty( $c['name2'] ) ) : ?><br><small><?php echo esc_html( $c['name2'] ); ?></small><?php endif; ?></td>
					<td><?php echo esc_html( trim( ( $c['streetNumber'] ?? '' ) . ' ' . ( $c['street'] ?? '' ) ) ); ?></td>
					<td><?php echo esc_html( trim( ( $c['zip'] ?? '' ) . ' ' . ( $c['city'] ?? '' ) ) ); ?></td>
					<td><?php echo belotero_has_coords( $c )
						? esc_html( round( (float) $c['lat'], 5 ) . ', ' . round( (float) $c['lng'], 5 ) )
						: '<span style="color:#b32d2e">' . ( empty( $c['geo_failed'] ) ? 'en attente' : 'non localisable' ) . '</span>'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<script>
	(function () {
		var btn = document.getElementById('belotero-geocode');
		if (!btn) return;
		var out = document.getElementById('belotero-geocode-status');
		btn.addEventListener('click', function () {
			btn.disabled = true;
			out.style.display = '';
			(function run() {
				out.textContent = 'Localisation en cours…';
				var body = new FormData();
				body.append('action', 'belotero_geocode_batch');
				body.append('_wpnonce', <?php echo wp_json_encode( wp_create_nonce( 'belotero_geocode' ) ); ?>);
				fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (!res || !res.success) throw new Error();
						if (res.data.left) { out.textContent = res.data.left + ' adresse(s) restante(s)…'; run(); }
						else { out.textContent = 'Terminé. Rechargement…'; setTimeout(function () { location.reload(); }, 600); }
					})
					.catch(function () {
						out.textContent = 'Erreur pendant la localisation. Relancez : le traitement reprend là où il s’est arrêté.';
						btn.disabled = false;
					});
			})();
		});
	})();
	</script>
	<?php
}
