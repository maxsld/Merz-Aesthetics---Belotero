<?php
/**
 * Plugin Name:  BELOTERO® – Doc locator
 * Description:  Gestion de la liste des centres BELOTERO® (import CSV, géocodage par lots, API de lecture pour la landing page).
 * Version:      1.0.0
 * Author:       Merz Aesthetics France
 * License:      GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BELOTERO_LOC_DIR', plugin_dir_path( __FILE__ ) );
define( 'BELOTERO_LOC_URL', plugin_dir_url( __FILE__ ) );

const BELOTERO_LOC_OPTION   = 'belotero_centers';       // tableau des centres (autoload = false)
const BELOTERO_LOC_STAMP    = 'belotero_centers_stamp'; // version du jeu de données, sert d'ETag
const BELOTERO_LOC_GEO_BATCH = 200;                     // lignes par appel de géocodage

/* =========================================================================
 * 1. Lecture — route REST consommée par la landing page
 *    GET /wp-json/belotero/v1/centers
 *    Le front ne la charge qu'à la première recherche : la page reste légère.
 * ========================================================================= */
add_action( 'rest_api_init', function () {
	register_rest_route( 'belotero/v1', '/centers', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) {
			$centers = belotero_loc_get_centers();
			$stamp   = get_option( BELOTERO_LOC_STAMP, '0' );
			$etag    = '"' . md5( $stamp . '-' . count( $centers ) ) . '"';

			// 304 si le navigateur a déjà la bonne version
			if ( trim( (string) $req->get_header( 'if_none_match' ) ) === $etag ) {
				return new WP_REST_Response( null, 304 );
			}

			$payload = array_map( function ( $c ) {
				$out = [
					'name'         => $c['name'] ?? '',
					'streetNumber' => $c['streetNumber'] ?? '',
					'street'       => $c['street'] ?? '',
					'zip'          => $c['zip'] ?? '',
					'city'         => $c['city'] ?? '',
					'lat'          => isset( $c['lat'] ) ? (float) $c['lat'] : null,
					'lng'          => isset( $c['lng'] ) ? (float) $c['lng'] : null,
				];
				if ( ! empty( $c['name2'] ) )   $out['name2']  = $c['name2'];
				if ( ! empty( $c['region'] ) )  $out['region'] = $c['region'];
				return $out;
			}, array_values( array_filter( $centers, fn( $c ) => ! empty( $c['lat'] ) && ! empty( $c['lng'] ) ) ) );

			$res = new WP_REST_Response( $payload, 200 );
			$res->header( 'ETag', $etag );
			$res->header( 'Cache-Control', 'public, max-age=3600' );
			return $res;
		},
	] );
} );

/**
 * Expose l'URL de l'API à la landing page (quelques octets, pas la liste entière).
 */
add_action( 'wp_head', function () {
	if ( ! belotero_loc_is_landing() ) return;
	printf(
		"<script>window.BELOTERO_CENTERS_URL=%s;</script>\n",
		wp_json_encode( esc_url_raw( rest_url( 'belotero/v1/centers' ) ) )
	);
}, 5 );

function belotero_loc_is_landing() {
	if ( ! is_page() ) return false;
	$tpl = get_post_meta( get_the_ID(), '_wp_page_template', true );
	return in_array( $tpl, [ 'belotero-landing', 'radiesse-landing' ], true )
		|| apply_filters( 'belotero_loc_is_landing', false );
}

function belotero_loc_get_centers() {
	$c = get_option( BELOTERO_LOC_OPTION );
	return is_array( $c ) ? $c : [];
}

/* =========================================================================
 * 2. Écriture — import CSV
 * ========================================================================= */

/**
 * Lit le CSV et retourne un tableau de centres. Ne géocode pas : le géocodage
 * est fait après, par lots, pour ne pas dépasser max_execution_time.
 */
function belotero_loc_parse_csv( $filepath ) {
	$handle = fopen( $filepath, 'r' );
	if ( ! $handle ) return new WP_Error( 'csv_open', "Impossible d'ouvrir le fichier." );

	$first = fgets( $handle );
	rewind( $handle );
	$sep = ( substr_count( $first, ';' ) > substr_count( $first, ',' ) ) ? ';' : ',';

	$headers  = null;
	$required = [ 'name', 'zip', 'city' ];
	$allowed  = [ 'name', 'name2', 'streetNumber', 'street', 'zip', 'city', 'region', 'country', 'lat', 'lng' ];
	$centers  = [];
	$line     = 0;

	while ( ( $row = fgetcsv( $handle, 4000, $sep ) ) !== false ) {
		$line++;
		if ( count( $row ) === 1 && trim( (string) $row[0] ) === '' ) continue;

		if ( $headers === null ) {
			$headers    = array_map( 'trim', $row );
			$headers[0] = ltrim( $headers[0], "\xEF\xBB\xBF" ); // BOM Excel
			foreach ( $required as $col ) {
				if ( ! in_array( $col, $headers, true ) ) {
					fclose( $handle );
					return new WP_Error( 'csv_headers',
						sprintf( 'Colonne obligatoire manquante : "%s". Colonnes trouvées : %s',
							$col, implode( ', ', $headers ) ) );
				}
			}
			continue;
		}

		// Tolère les lignes plus courtes/longues que l'en-tête
		$row = array_pad( array_slice( $row, 0, count( $headers ) ), count( $headers ), '' );
		$raw = array_combine( $headers, array_map( 'trim', $row ) );

		$c = [];
		foreach ( $allowed as $col ) {
			if ( isset( $raw[ $col ] ) && $raw[ $col ] !== '' && $raw[ $col ] !== '#' ) {
				$c[ $col ] = $raw[ $col ];
			}
		}
		if ( empty( $c['name'] ) ) continue;

		$c['country'] = $c['country'] ?? 'France';
		if ( isset( $c['lat'] ) ) $c['lat'] = (float) str_replace( ',', '.', $c['lat'] );
		if ( isset( $c['lng'] ) ) $c['lng'] = (float) str_replace( ',', '.', $c['lng'] );
		if ( empty( $c['lat'] ) || empty( $c['lng'] ) ) { unset( $c['lat'], $c['lng'] ); }

		$centers[] = $c;
	}
	fclose( $handle );

	if ( empty( $centers ) ) return new WP_Error( 'csv_empty', 'Aucun centre trouvé dans le fichier.' );

	// Dédoublonnage sur nom + rue + code postal
	$seen = $unique = [];
	foreach ( $centers as $c ) {
		$k = mb_strtoupper( ( $c['name'] ?? '' ) . '|' . ( $c['streetNumber'] ?? '' ) . ' ' . ( $c['street'] ?? '' ) . '|' . ( $c['zip'] ?? '' ) );
		if ( isset( $seen[ $k ] ) ) continue;
		$seen[ $k ] = true;
		$unique[]   = $c;
	}
	return $unique;
}

/* =========================================================================
 * 3. Géocodage par lots
 *    France : API Adresse (data.gouv.fr) — endpoint CSV, un appel pour 200
 *    adresses, gratuit et sans clé. Hors France : Nominatim ligne par ligne.
 * ========================================================================= */

/**
 * Géocode le prochain lot d'adresses sans coordonnées.
 * @return array{done:int,left:int}
 */
function belotero_loc_geocode_batch() {
	$centers = belotero_loc_get_centers();
	$todo    = [];
	foreach ( $centers as $i => $c ) {
		if ( empty( $c['lat'] ) || empty( $c['lng'] ) ) $todo[ $i ] = $c;
		if ( count( $todo ) >= BELOTERO_LOC_GEO_BATCH ) break;
	}
	if ( ! $todo ) return [ 'done' => 0, 'left' => 0 ];

	$fr = $other = [];
	foreach ( $todo as $i => $c ) {
		$country = mb_strtolower( $c['country'] ?? 'france' );
		if ( $country === 'france' || $country === 'fr' ) $fr[ $i ] = $c;
		else $other[ $i ] = $c;
	}

	$done = 0;
	if ( $fr )    $done += belotero_loc_geocode_ban( $centers, $fr );
	foreach ( $other as $i => $c ) {
		$coords = belotero_loc_geocode_nominatim( $c );
		if ( $coords ) { $centers[ $i ]['lat'] = $coords['lat']; $centers[ $i ]['lng'] = $coords['lng']; $done++; }
		else           { $centers[ $i ]['geo_failed'] = true; }
		usleep( 1100000 ); // Nominatim : 1 req/s max
	}

	belotero_loc_save_centers( $centers );

	$left = 0;
	foreach ( $centers as $c ) {
		if ( ( empty( $c['lat'] ) || empty( $c['lng'] ) ) && empty( $c['geo_failed'] ) ) $left++;
	}
	return [ 'done' => $done, 'left' => $left ];
}

/**
 * Un seul appel HTTP pour tout le lot, via l'endpoint CSV de l'API Adresse.
 * $centers est modifié par référence.
 */
function belotero_loc_geocode_ban( array &$centers, array $batch ) {
	// Construit le CSV à envoyer
	$csv = "idx,adresse,cp,ville\n";
	foreach ( $batch as $i => $c ) {
		$addr = trim( ( $c['streetNumber'] ?? '' ) . ' ' . ( $c['street'] ?? '' ) );
		$city = preg_replace( '/\s*cedex.*$/i', '', $c['city'] ?? '' );
		$csv .= sprintf( "%d,%s,%s,%s\n", $i,
			belotero_loc_csv_cell( $addr ),
			belotero_loc_csv_cell( $c['zip'] ?? '' ),
			belotero_loc_csv_cell( trim( $city ) ) );
	}

	$boundary = wp_generate_password( 24, false );
	$body     = '';
	foreach ( [ 'columns' => [ 'adresse', 'ville' ], 'postcode' => [ 'cp' ] ] as $field => $values ) {
		foreach ( $values as $v ) {
			$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$field\"\r\n\r\n$v\r\n";
		}
	}
	$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"data\"; filename=\"a.csv\"\r\n"
		   . "Content-Type: text/csv\r\n\r\n$csv\r\n--$boundary--\r\n";

	$res = wp_remote_post( 'https://api-adresse.data.gouv.fr/search/csv/', [
		'timeout' => 60,
		'headers' => [ 'Content-Type' => "multipart/form-data; boundary=$boundary" ],
		'body'    => $body,
	] );
	if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
		return 0; // on retentera au prochain lot
	}

	$lines = preg_split( '/\r\n|\n/', trim( wp_remote_retrieve_body( $res ) ) );
	$head  = str_getcsv( array_shift( $lines ) );
	$col   = array_flip( $head );
	if ( ! isset( $col['latitude'], $col['longitude'], $col['idx'] ) ) return 0;

	$done = 0;
	foreach ( $lines as $line ) {
		if ( $line === '' ) continue;
		$r   = str_getcsv( $line );
		$idx = (int) ( $r[ $col['idx'] ] ?? -1 );
		$lat = $r[ $col['latitude'] ]  ?? '';
		$lng = $r[ $col['longitude'] ] ?? '';
		if ( ! isset( $centers[ $idx ] ) ) continue;

		if ( $lat !== '' && $lng !== '' ) {
			$centers[ $idx ]['lat'] = (float) $lat;
			$centers[ $idx ]['lng'] = (float) $lng;
			if ( isset( $col['result_score'] ) ) $centers[ $idx ]['geo_score'] = (float) $r[ $col['result_score'] ];
			if ( isset( $col['result_type'] ) )  $centers[ $idx ]['geo_type']  = $r[ $col['result_type'] ];
			unset( $centers[ $idx ]['geo_failed'] );
			$done++;
		} else {
			$centers[ $idx ]['geo_failed'] = true;
		}
	}
	return $done;
}

function belotero_loc_csv_cell( $v ) {
	$v = str_replace( [ "\r", "\n" ], ' ', (string) $v );
	return ( strpbrk( $v, ",\"" ) !== false ) ? '"' . str_replace( '"', '""', $v ) . '"' : $v;
}

function belotero_loc_geocode_nominatim( $c ) {
	$address = implode( ' ', array_filter( [
		$c['streetNumber'] ?? '', $c['street'] ?? '', $c['zip'] ?? '', $c['city'] ?? '', $c['country'] ?? '',
	] ) );
	$res = wp_remote_get(
		'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=' . rawurlencode( $address ),
		[ 'headers' => [ 'User-Agent' => 'BeloteroLocator/1.0 (merzaesthetics.fr)' ], 'timeout' => 10 ]
	);
	if ( is_wp_error( $res ) ) return null;
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $body[0] ) ) return null;
	return [ 'lat' => (float) $body[0]['lat'], 'lng' => (float) $body[0]['lon'] ];
}

function belotero_loc_save_centers( array $centers ) {
	// autoload = false : 505 centres ne doivent pas être chargés à chaque requête WP
	update_option( BELOTERO_LOC_OPTION, array_values( $centers ), false );
	update_option( BELOTERO_LOC_STAMP, (string) time(), false );
}

/* =========================================================================
 * 4. Écran d'administration
 * ========================================================================= */
add_action( 'admin_menu', function () {
	add_menu_page( 'BELOTERO® — Centres', 'BELOTERO Centres', 'manage_options',
		'belotero-centers', 'belotero_loc_admin_page', 'dashicons-location-alt', 30 );
} );

// Géocodage par lots déclenché en AJAX depuis l'écran d'admin
add_action( 'wp_ajax_belotero_geocode_batch', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );
	check_ajax_referer( 'belotero_geocode' );
	wp_send_json_success( belotero_loc_geocode_batch() );
} );

function belotero_loc_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	$notice = '';

	if ( isset( $_POST['belotero_import'] ) ) {
		check_admin_referer( 'belotero_centers' );
		if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
			$notice = [ 'error', 'Aucun fichier sélectionné.' ];
		} else {
			$parsed = belotero_loc_parse_csv( $_FILES['csv_file']['tmp_name'] );
			if ( is_wp_error( $parsed ) ) {
				$notice = [ 'error', $parsed->get_error_message() ];
			} else {
				belotero_loc_save_centers( $parsed );
				$geo    = count( array_filter( $parsed, fn( $c ) => ! empty( $c['lat'] ) ) );
				$notice = [ 'success', sprintf(
					'%d centre(s) importé(s), dont %d déjà géocodé(s). %s',
					count( $parsed ), $geo,
					$geo < count( $parsed ) ? 'Lancez le géocodage ci-dessous.' : '' ) ];
			}
		}
	}

	if ( isset( $_POST['belotero_reset'] ) ) {
		check_admin_referer( 'belotero_centers' );
		delete_option( BELOTERO_LOC_OPTION );
		delete_option( BELOTERO_LOC_STAMP );
		$notice = [ 'success', 'Liste supprimée.' ];
	}

	if ( isset( $_GET['belotero_export'] ) && check_admin_referer( 'belotero_export' ) ) {
		belotero_loc_export_csv();
		exit;
	}

	$centers = belotero_loc_get_centers();
	$total   = count( $centers );
	$geo     = count( array_filter( $centers, fn( $c ) => ! empty( $c['lat'] ) && ! empty( $c['lng'] ) ) );
	$failed  = count( array_filter( $centers, fn( $c ) => ! empty( $c['geo_failed'] ) ) );
	$pending = $total - $geo - $failed;
	?>
	<div class="wrap">
		<h1>BELOTERO® — Centres</h1>

		<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible">
				<p><?php echo esc_html( $notice[1] ); ?></p></div>
		<?php endif; ?>

		<div class="card" style="max-width:760px;">
			<h2>État</h2>
			<?php if ( ! $total ) : ?>
				<p>Aucun centre importé. La carte de la landing page restera vide.</p>
			<?php else : ?>
				<p>
					<strong><?php echo (int) $total; ?></strong> centres —
					<strong><?php echo (int) $geo; ?></strong> géocodés
					<?php if ( $pending ) : ?>, <strong><?php echo (int) $pending; ?></strong> en attente<?php endif; ?>
					<?php if ( $failed ) : ?>, <strong><?php echo (int) $failed; ?></strong> en échec<?php endif; ?>
				</p>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url(
						admin_url( 'admin.php?page=belotero-centers&belotero_export=1' ), 'belotero_export' ) ); ?>">
						Exporter en CSV</a>
					<?php if ( $pending ) : ?>
						<button type="button" class="button button-primary" id="belotero-geocode">
							Géocoder les <?php echo (int) $pending; ?> adresses restantes</button>
					<?php endif; ?>
				</p>
				<p id="belotero-geocode-status" style="display:none;"></p>
			<?php endif; ?>
		</div>

		<div class="card" style="max-width:760px;">
			<h2>Importer un CSV</h2>
			<p>Colonnes reconnues (UTF-8, séparateur <code>,</code> ou <code>;</code>) :</p>
			<p><code>name, name2, streetNumber, street, zip, city, region, country, lat, lng</code></p>
			<p><em>Obligatoires : <code>name</code>, <code>zip</code>, <code>city</code>.
			Si <code>lat</code>/<code>lng</code> sont fournis, aucun géocodage n'est fait.</em></p>
			<p><a class="button" download href="<?php echo esc_url( BELOTERO_LOC_URL . 'assets/centres-template.csv' ); ?>">
				Télécharger le modèle</a></p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'belotero_centers' ); ?>
				<p><input type="file" name="csv_file" accept=".csv,text/csv" required></p>
				<p><button class="button button-primary" name="belotero_import">Importer (remplace la liste)</button></p>
			</form>
		</div>

		<?php if ( $total ) : ?>
		<div class="card" style="max-width:760px;">
			<h2>Réinitialiser</h2>
			<form method="post"><?php wp_nonce_field( 'belotero_centers' ); ?>
				<button class="button" name="belotero_reset"
					onclick="return confirm('Supprimer les <?php echo (int) $total; ?> centres ?')">Tout supprimer</button>
			</form>
		</div>

		<h2>Aperçu (50 premiers)</h2>
		<table class="widefat striped">
			<thead><tr><th>Nom</th><th>Adresse</th><th>Ville</th><th>GPS</th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( $centers, 0, 50 ) as $c ) : ?>
				<tr>
					<td><?php echo esc_html( $c['name'] ); ?>
						<?php if ( ! empty( $c['name2'] ) ) : ?>
							<br><small><?php echo esc_html( $c['name2'] ); ?></small><?php endif; ?></td>
					<td><?php echo esc_html( trim( ( $c['streetNumber'] ?? '' ) . ' ' . ( $c['street'] ?? '' ) ) ); ?></td>
					<td><?php echo esc_html( ( $c['zip'] ?? '' ) . ' ' . ( $c['city'] ?? '' ) ); ?></td>
					<td><?php echo ! empty( $c['lat'] )
						? esc_html( round( $c['lat'], 5 ) . ', ' . round( $c['lng'], 5 ) )
						: '<span style="color:#b32d2e">non géocodé</span>'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>

	<script>
	(function () {
		var btn = document.getElementById('belotero-geocode');
		if (!btn) return;
		var out = document.getElementById('belotero-geocode-status');
		btn.addEventListener('click', function () {
			btn.disabled = true;
			out.style.display = '';
			var run = function () {
				out.textContent = 'Géocodage en cours…';
				var body = new FormData();
				body.append('action', 'belotero_geocode_batch');
				body.append('_wpnonce', <?php echo wp_json_encode( wp_create_nonce( 'belotero_geocode' ) ); ?>);
				fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (!res || !res.success) throw new Error();
						var left = res.data.left;
						out.textContent = left
							? left + ' adresse(s) restante(s)…'
							: 'Géocodage terminé. Rechargement…';
						if (left) { run(); } else { setTimeout(function () { location.reload(); }, 800); }
					})
					.catch(function () {
						out.textContent = 'Erreur pendant le géocodage. Relancez pour reprendre où ça s’est arrêté.';
						btn.disabled = false;
					});
			};
			run();
		});
	})();
	</script>
	<?php
}

function belotero_loc_export_csv() {
	$centers = belotero_loc_get_centers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="belotero-centres.csv"' );
	$out  = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // BOM, pour qu'Excel lise l'UTF-8
	$cols = [ 'name', 'name2', 'streetNumber', 'street', 'zip', 'city', 'region', 'country', 'lat', 'lng' ];
	fputcsv( $out, $cols );
	foreach ( $centers as $c ) {
		fputcsv( $out, array_map( fn( $k ) => $c[ $k ] ?? '', $cols ) );
	}
	fclose( $out );
}
