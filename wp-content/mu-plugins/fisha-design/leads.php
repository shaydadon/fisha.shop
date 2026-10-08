<?php
/**
 * Fisha — leads: "next drop" sign-ups and commission / tattoo requests.
 *
 *   [fisha_drop_signup interest="originals" title="…" text="…"]
 *       Also shown automatically under the Originals / Collectibles shelves and
 *       on sold-out one-of-a-kind product pages.
 *   [fisha_request_form]  (the /request/ page; ?type=tattoo|artwork|clipper preselects)
 *
 * Both post to the REST API (works on LiteSpeed-cached pages — no nonces to go
 * stale), are protected by a honeypot, a time trap and a per-IP rate limit, and
 * are stored as private "Fisha leads" in wp-admin (with a CSV export for the
 * drop list). Every submission emails the shop admin.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

const FISHA_LEAD_MAX_FILES = 3;
const FISHA_LEAD_MAX_MB    = 8;

function fisha_request_types() {
	return array(
		'tattoo'  => 'A Fisha tattoo',
		'artwork' => 'A custom artwork',
		'clipper' => 'A custom Clipper',
		'other'   => 'Something else',
	);
}
function fisha_request_budgets() {
	return array( '' => 'Not sure yet', 'u500' => 'Under ₪500', '500-1500' => '₪500 – ₪1,500', '1500-3000' => '₪1,500 – ₪3,000', '3000+' => '₪3,000 +' );
}
function fisha_request_page_url( $type = '' ) {
	$p   = get_page_by_path( 'request' );
	$url = $p ? get_permalink( $p ) : home_url( '/request/' );
	return $type ? add_query_arg( 'type', $type, $url ) : $url;
}

/* ------------------------------------------------------------------ storage */

add_action( 'init', function () {
	register_post_type( 'fisha_lead', array(
		'labels'          => array(
			'name' => 'Fisha leads', 'singular_name' => 'Lead', 'menu_name' => 'Fisha leads',
			'all_items' => 'All leads', 'edit_item' => 'Lead', 'search_items' => 'Search leads', 'not_found' => 'No leads yet',
		),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => true,
		'show_in_rest'    => false,
		'menu_position'   => 58,
		'menu_icon'       => 'dashicons-email-alt',
		'supports'        => array( 'title' ),
		'capability_type' => 'post',
		'map_meta_cap'    => true,
		'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
	) );
} );

function fisha_client_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
}

/** Spam gates shared by both forms. Returns '' when OK, or an error message. */
function fisha_lead_gate( WP_REST_Request $r, $kind ) {
	if ( '' !== trim( (string) $r->get_param( 'website' ) ) ) return 'spam';            // honeypot
	$ms = (int) $r->get_param( 'fisha_ms' );                                              // time trap (filled by JS)
	if ( $ms && $ms < 2500 ) return 'spam';
	$key = 'fisha_rl_' . $kind . '_' . md5( fisha_client_ip() );
	$n   = (int) get_transient( $key );
	if ( $n >= ( 'drop' === $kind ? 6 : 4 ) ) return 'Too many tries in a short time — please wait a few minutes and try again.';
	set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
	return '';
}

/** JSON for fetch(); a redirect back to the page for the no-JS fallback. */
function fisha_lead_reply( WP_REST_Request $r, $ok, $message, $anchor ) {
	if ( $r->get_param( 'fisha_nojs' ) ) {
		$back = wp_get_referer() ?: home_url( '/' );
		$back = remove_query_arg( array( 'fisha_ok', 'fisha_err' ), $back );
		$back = add_query_arg( $ok ? array( 'fisha_ok' => $anchor ) : array( 'fisha_err' => rawurlencode( $message ) ), $back ) . '#' . $anchor;
		$res  = new WP_REST_Response( null, 303 );
		$res->header( 'Location', esc_url_raw( $back ) );
		return $res;
	}
	return new WP_REST_Response( array( 'ok' => $ok, 'message' => $message ), $ok ? 200 : 400 );
}

function fisha_lead_save( $kind, $title, $meta ) {
	$id = wp_insert_post( array( 'post_type' => 'fisha_lead', 'post_status' => 'private', 'post_title' => $title ), true );
	if ( is_wp_error( $id ) ) return 0;
	update_post_meta( $id, '_kind', $kind );
	foreach ( $meta as $k => $v ) update_post_meta( $id, '_' . $k, $v );
	return $id;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'fisha/v1', '/drop', array( 'methods' => 'POST', 'callback' => 'fisha_rest_drop', 'permission_callback' => '__return_true' ) );
	register_rest_route( 'fisha/v1', '/request', array( 'methods' => 'POST', 'callback' => 'fisha_rest_request', 'permission_callback' => '__return_true' ) );
} );

/* ------------------------------------------------------------- drop sign-up */

function fisha_rest_drop( WP_REST_Request $r ) {
	$anchor = 'fisha-drop';
	if ( $err = fisha_lead_gate( $r, 'drop' ) ) {
		// pretend success to bots, tell humans what happened
		return 'spam' === $err ? fisha_lead_reply( $r, true, 'You’re on the list.', $anchor ) : fisha_lead_reply( $r, false, $err, $anchor );
	}
	$email    = sanitize_email( (string) $r->get_param( 'email' ) );
	$interest = sanitize_key( (string) $r->get_param( 'interest' ) ) ?: 'all';
	if ( ! is_email( $email ) ) return fisha_lead_reply( $r, false, 'Please enter a valid email address.', $anchor );
	if ( ! $r->get_param( 'consent' ) ) return fisha_lead_reply( $r, false, 'Please tick the box so we’re allowed to email you.', $anchor );

	$msg = fisha_drop_add( $email, $interest, esc_url_raw( (string) $r->get_param( 'source' ) ) );
	return fisha_lead_reply( $r, true, $msg, $anchor );
}

/** Adds (or refreshes) a sign-up; one entry per email + interest. */
function fisha_drop_add( $email, $interest, $source = '' ) {
	$found = get_posts( array(
		'post_type' => 'fisha_lead', 'post_status' => 'private', 'numberposts' => 1, 'fields' => 'ids',
		'meta_query' => array( array( 'key' => '_kind', 'value' => 'drop' ), array( 'key' => '_email', 'value' => $email ), array( 'key' => '_interest', 'value' => $interest ) ),
	) );
	if ( $found ) return 'You’re already on the list — we’ll let you know.';
	$label = fisha_drop_interest_label( $interest );
	fisha_lead_save( 'drop', $email . ' · ' . $label, array(
		'email' => $email, 'interest' => $interest, 'source' => $source,
		'consent' => current_time( 'mysql' ), 'ip' => fisha_client_ip(),
	) );
	wp_mail( get_option( 'admin_email' ), '[Fisha] New drop sign-up: ' . $label, "$email wants to hear about new $label.\n\nFrom: $source\n\nAll sign-ups: " . admin_url( 'edit.php?post_type=fisha_lead&fisha_kind=drop' ) );
	return 'You’re on the list. We’ll email you when the next one drops.';
}

function fisha_drop_interest_label( $slug ) {
	if ( 'all' === $slug ) return 'Fisha drops';
	$t = get_term_by( 'slug', $slug, 'product_cat' );
	return $t ? $t->name : ucfirst( $slug );
}

add_shortcode( 'fisha_drop_signup', function ( $atts ) {
	static $n = 0; $n++;
	$a = shortcode_atts( array(
		'interest' => 'all',
		'eyebrow'  => 'One of a kind',
		'title'    => 'Hear about the next drop',
		'text'     => 'Every Fisha original and Clipper is made once. Leave your email and you’ll hear first when new pieces swim in — nothing else, and you can unsubscribe any time.',
		'compact'  => '',
	), $atts, 'fisha_drop_signup' );
	$id   = 'fisha-drop' . ( $n > 1 ? '-' . $n : '' );
	$ok   = isset( $_GET['fisha_ok'] ) && 'fisha-drop' === $_GET['fisha_ok'];
	$err  = isset( $_GET['fisha_err'] ) ? sanitize_text_field( wp_unslash( $_GET['fisha_err'] ) ) : '';
	fisha_leads_assets();
	ob_start(); ?>
<section class="fd-card<?php echo $a['compact'] ? ' fd-card--compact' : ''; ?>" id="<?php echo esc_attr( $id ); ?>" aria-labelledby="<?php echo esc_attr( $id ); ?>-h">
	<span class="fd-card__tape" aria-hidden="true"></span>
	<div class="fd-card__copy">
		<p class="fd-eyebrow"><?php echo esc_html( $a['eyebrow'] ); ?></p>
		<h2 id="<?php echo esc_attr( $id ); ?>-h" class="fd-title"><?php echo esc_html( $a['title'] ); ?></h2>
		<p class="fd-text"><?php echo esc_html( $a['text'] ); ?></p>
	</div>
	<form class="fd-form fisha-lead-form" method="post" action="<?php echo esc_url( rest_url( 'fisha/v1/drop' ) ); ?>" novalidate>
		<input type="hidden" name="interest" value="<?php echo esc_attr( sanitize_key( $a['interest'] ) ); ?>">
		<input type="hidden" name="source" value="">
		<input type="hidden" name="fisha_nojs" value="1">
		<input type="hidden" name="fisha_ms" value="">
		<p class="fisha-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
		<div class="fd-row">
			<label class="fd-field">
				<span class="screen-reader-text">Email address</span>
				<input type="email" name="email" required autocomplete="email" inputmode="email" placeholder="Your email address">
			</label>
			<button type="submit" class="fd-submit">Notify me</button>
		</div>
		<label class="fd-consent">
			<input type="checkbox" name="consent" value="1" required>
			<span>Yes, email me about new Fisha pieces. I can unsubscribe any time.</span>
		</label>
		<p class="fisha-lead-msg<?php echo $ok ? ' is-ok' : ( $err ? ' is-err' : '' ); ?>" role="status" aria-live="polite"><?php
			echo $ok ? 'You’re on the list. We’ll email you when the next one drops.' : esc_html( $err ); ?></p>
	</form>
</section>
<?php
	return ob_get_clean();
} );

/** Interest slug for a category: its top-level parent (Clippers → collectibles). */
function fisha_drop_interest_for_term( $term ) {
	while ( $term && $term->parent ) $term = get_term( $term->parent, 'product_cat' );
	return $term && ! is_wp_error( $term ) ? $term->slug : 'all';
}
function fisha_drop_cats() {
	return apply_filters( 'fisha_drop_cats', array( 'originals', 'collectibles' ) );
}

// Under the Originals / Collectibles shelves (before "Happy clients").
add_action( 'fisha_after_shelf', function ( $term ) {
	$interest = fisha_drop_interest_for_term( $term );
	if ( ! in_array( $interest, fisha_drop_cats(), true ) ) return;
	$text = 'originals' === $interest
		? 'Each original is painted once, and when it’s gone it’s gone. Leave your email and you’ll hear first when new originals are ready.'
		: 'Every Clipper and collectible is hand-painted, one of a kind. Leave your email and you’ll hear first when new ones drop.';
	echo do_shortcode( '[fisha_drop_signup interest="' . esc_attr( $interest ) . '" text="' . esc_attr( $text ) . '"]' );
} );

// Sold-out one-of-a-kind product page: offer the list instead of a dead end.
add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! $product || $product->is_in_stock() ) return;
	$interest = 'all';
	foreach ( wc_get_product_term_ids( $product->get_id(), 'product_cat' ) as $tid ) {
		$i = fisha_drop_interest_for_term( get_term( $tid, 'product_cat' ) );
		if ( in_array( $i, fisha_drop_cats(), true ) ) { $interest = $i; break; }
	}
	echo do_shortcode( '[fisha_drop_signup compact="1" interest="' . esc_attr( $interest ) . '" eyebrow="Sold · found its home" title="Want the next one?" text="This piece has already found its people. Leave your email and you’ll hear first when new ones drop."]' );
}, 32 );

/* ----------------------------------------------------- commission / tattoo */

function fisha_rest_request( WP_REST_Request $r ) {
	$anchor = 'fisha-request';
	if ( $err = fisha_lead_gate( $r, 'request' ) ) {
		return 'spam' === $err ? fisha_lead_reply( $r, true, 'Thanks — your request is in.', $anchor ) : fisha_lead_reply( $r, false, $err, $anchor );
	}
	$types   = fisha_request_types();
	$budgets = fisha_request_budgets();
	$f = array(
		'name'      => sanitize_text_field( (string) $r->get_param( 'name' ) ),
		'email'     => sanitize_email( (string) $r->get_param( 'email' ) ),
		'phone'     => preg_replace( '/[^0-9+\-\s()]/', '', (string) $r->get_param( 'phone' ) ),
		'type'      => sanitize_key( (string) $r->get_param( 'type' ) ),
		'idea'      => sanitize_textarea_field( (string) $r->get_param( 'idea' ) ),
		'size'      => sanitize_text_field( (string) $r->get_param( 'size' ) ),
		'placement' => sanitize_text_field( (string) $r->get_param( 'placement' ) ),
		'budget'    => sanitize_key( (string) $r->get_param( 'budget' ) ),
		'timing'    => sanitize_text_field( (string) $r->get_param( 'timing' ) ),
	);
	$errors = array();
	if ( '' === $f['name'] ) $errors[] = 'your name';
	if ( ! is_email( $f['email'] ) ) $errors[] = 'a valid email';
	if ( ! isset( $types[ $f['type'] ] ) ) $errors[] = 'what you’d like';
	if ( mb_strlen( $f['idea'] ) < 10 ) $errors[] = 'a few words about your idea';
	if ( ! $r->get_param( 'consent' ) ) $errors[] = 'your OK for us to reply';
	if ( $errors ) return fisha_lead_reply( $r, false, 'Please add ' . implode( ', ', $errors ) . '.', $anchor );
	if ( ! isset( $budgets[ $f['budget'] ] ) ) $f['budget'] = '';
	if ( 'tattoo' !== $f['type'] ) $f['placement'] = '';

	// reference images
	$files = fisha_request_files( $r->get_file_params() );
	if ( is_string( $files ) ) return fisha_lead_reply( $r, false, $files, $anchor );

	$id = fisha_lead_save( 'request', $types[ $f['type'] ] . ' · ' . $f['name'], $f + array(
		'files' => $files, 'source' => esc_url_raw( (string) $r->get_param( 'source' ) ),
		'consent' => current_time( 'mysql' ), 'ip' => fisha_client_ip(),
	) );
	if ( $r->get_param( 'drops' ) ) fisha_drop_add( $f['email'], 'all', 'request form' );

	// emails
	$lines = array(
		'What'      => $types[ $f['type'] ],
		'Name'      => $f['name'],
		'Email'     => $f['email'],
		'Phone'     => $f['phone'],
		'Size'      => $f['size'],
		'Placement' => $f['placement'],
		'Budget'    => $f['budget'] ? $budgets[ $f['budget'] ] : 'Not sure yet',
		'Timing'    => $f['timing'],
	);
	$body = '';
	foreach ( $lines as $k => $v ) if ( '' !== $v ) $body .= "$k: $v\n";
	$body .= "\nIdea:\n" . $f['idea'] . "\n";
	if ( $files ) $body .= "\nReference images:\n" . implode( "\n", $files ) . "\n";
	if ( $id ) $body .= "\nIn wp-admin: " . admin_url( 'post.php?post=' . $id . '&action=edit' ) . "\n";
	wp_mail( get_option( 'admin_email' ), '[Fisha] New request: ' . $types[ $f['type'] ] . ' from ' . $f['name'], $body, array( 'Reply-To: ' . $f['name'] . ' <' . $f['email'] . '>' ) );
	wp_mail( $f['email'], 'We got your Fisha request',
		"Hi {$f['name']},\n\nThanks for your request — it’s safely in the river. Fisha reads every one personally and usually replies within a few days.\n\nWhat you sent:\n\n$body\n— Fisha\n" . home_url( '/' ) );

	return fisha_lead_reply( $r, true, 'Thanks, ' . $f['name'] . '! Your request is in. Fisha reads every one personally and usually replies within a few days — we’ve emailed you a copy.', $anchor );
}

/** Validates and stores up to 3 images. Returns a list of URLs, or an error string. */
function fisha_request_files( $params ) {
	if ( empty( $params['refs'] ) || ! is_array( $params['refs']['name'] ) ) return array();
	$up = $params['refs'];
	$n  = count( array_filter( $up['name'] ) );
	if ( ! $n ) return array();
	if ( $n > FISHA_LEAD_MAX_FILES ) return 'Please attach up to ' . FISHA_LEAD_MAX_FILES . ' images.';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$mimes = array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif' );
	$dir_filter = function ( $d ) {
		$d['subdir'] = '/fisha-requests' . $d['subdir'];
		$d['path']   = $d['basedir'] . $d['subdir'];
		$d['url']    = $d['baseurl'] . $d['subdir'];
		return $d;
	};
	add_filter( 'upload_dir', $dir_filter );
	$urls = array();
	foreach ( $up['name'] as $i => $name ) {
		if ( '' === $name ) continue;
		if ( UPLOAD_ERR_OK !== (int) $up['error'][ $i ] ) { $urls = 'One of the images didn’t upload — please try again.'; break; }
		if ( $up['size'][ $i ] > FISHA_LEAD_MAX_MB * MB_IN_BYTES ) { $urls = 'Each image can be up to ' . FISHA_LEAD_MAX_MB . ' MB.'; break; }
		$file = array( 'name' => wp_generate_password( 12, false ) . '-' . sanitize_file_name( $name ), 'type' => $up['type'][ $i ], 'tmp_name' => $up['tmp_name'][ $i ], 'error' => 0, 'size' => $up['size'][ $i ] );
		$res  = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => $mimes ) );
		if ( empty( $res['url'] ) ) { $urls = 'Images need to be JPG, PNG, WEBP or HEIC.'; break; }
		$urls[] = $res['url'];
	}
	remove_filter( 'upload_dir', $dir_filter );
	// no directory listings
	$base = wp_get_upload_dir()['basedir'] . '/fisha-requests';
	if ( is_dir( $base ) && ! file_exists( $base . '/index.php' ) ) {
		@file_put_contents( $base . '/index.php', "<?php // silence\n" );
		@file_put_contents( $base . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar)$\">\nRequire all denied\n</FilesMatch>\n" );
	}
	return $urls;
}

add_shortcode( 'fisha_request_form', function ( $atts ) {
	$a = shortcode_atts( array(
		'eyebrow' => 'Commissions & tattoos',
		'title'   => 'Ask for a Fisha made for you',
		'text'    => 'A Fisha on your skin, a painting for your wall or a Clipper in your colours — tell us the idea and we’ll take it from there. Every request is read personally.',
	), $atts, 'fisha_request_form' );
	$types = fisha_request_types();
	$sel   = isset( $_GET['type'] ) && isset( $types[ $_GET['type'] ] ) ? sanitize_key( $_GET['type'] ) : '';
	$ok    = isset( $_GET['fisha_ok'] ) && 'fisha-request' === $_GET['fisha_ok'];
	$err   = isset( $_GET['fisha_err'] ) ? sanitize_text_field( wp_unslash( $_GET['fisha_err'] ) ) : '';
	fisha_leads_assets();
	ob_start(); ?>
<div class="fisha-request">
	<header class="fr-hero">
		<p class="fr-eyebrow"><?php echo esc_html( $a['eyebrow'] ); ?></p>
		<h1 class="fr-title"><?php echo esc_html( $a['title'] ); ?></h1>
		<p class="fr-lead"><?php echo esc_html( $a['text'] ); ?></p>
		<ol class="fr-steps" aria-label="How it works">
			<li><strong>1</strong><span>Tell us your idea</span></li>
			<li><strong>2</strong><span>Fisha replies with thoughts and a price</span></li>
			<li><strong>3</strong><span>We draw it, you approve, it’s yours</span></li>
		</ol>
	</header>

	<section class="fr-card" id="fisha-request" aria-labelledby="fr-card-h">
		<span class="fd-card__tape" aria-hidden="true"></span>
		<h2 id="fr-card-h" class="fr-card__title">Your request</h2>
		<p class="fr-card__sub">Fields marked <span class="fr-req" aria-hidden="true">*</span><span class="screen-reader-text">with an asterisk</span> are required.</p>

		<form class="fr-form fisha-lead-form" method="post" action="<?php echo esc_url( rest_url( 'fisha/v1/request' ) ); ?>" enctype="multipart/form-data" novalidate>
			<input type="hidden" name="source" value="">
			<input type="hidden" name="fisha_nojs" value="1">
			<input type="hidden" name="fisha_ms" value="">
			<p class="fisha-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>

			<fieldset class="fr-types">
				<legend>What would you like? <span class="fr-req" aria-hidden="true">*</span></legend>
				<div class="fr-chips">
					<?php foreach ( $types as $k => $label ) : ?>
					<label class="fr-chip"><input type="radio" name="type" value="<?php echo esc_attr( $k ); ?>" required<?php checked( $sel, $k ); ?>><span><?php echo esc_html( $label ); ?></span></label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<div class="fr-grid">
				<label class="fr-field"><span>Name <span class="fr-req" aria-hidden="true">*</span></span>
					<input type="text" name="name" required autocomplete="name"></label>
				<label class="fr-field"><span>Email <span class="fr-req" aria-hidden="true">*</span></span>
					<input type="email" name="email" required autocomplete="email" inputmode="email"></label>
				<label class="fr-field fr-field--full"><span>Phone / WhatsApp <em>(optional)</em></span>
					<input type="tel" name="phone" autocomplete="tel" inputmode="tel"></label>
				<label class="fr-field fr-field--full"><span>Tell us your idea <span class="fr-req" aria-hidden="true">*</span></span>
					<textarea name="idea" required rows="6" placeholder="What should the Fisha be doing? Colours, mood, a story behind it…"></textarea></label>
				<label class="fr-field"><span>Size <em>(optional)</em></span>
					<input type="text" name="size" placeholder="e.g. palm-sized, A3, 50×70 cm"></label>
				<label class="fr-field fr-field--placement"><span>Placement on the body <em>(tattoos)</em></span>
					<input type="text" name="placement" placeholder="e.g. inner forearm, ankle"></label>
				<label class="fr-field"><span>Budget <em>(optional)</em></span>
					<select name="budget"><?php foreach ( fisha_request_budgets() as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<label class="fr-field"><span>When do you need it? <em>(optional)</em></span>
					<input type="text" name="timing" placeholder="e.g. a birthday in March, no rush"></label>
				<div class="fr-field fr-field--full fr-files">
					<label for="fr-refs"><span>Reference images <em>(optional, up to <?php echo (int) FISHA_LEAD_MAX_FILES; ?>, <?php echo (int) FISHA_LEAD_MAX_MB; ?> MB each)</em></span></label>
					<input id="fr-refs" type="file" name="refs[]" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic" multiple>
					<p class="fr-hint">A photo of the spot, a sketch, a colour you love — anything that helps.</p>
				</div>
			</div>

			<label class="fd-consent"><input type="checkbox" name="consent" value="1" required>
				<span>I agree that Fisha may contact me about this request. <span class="fr-req" aria-hidden="true">*</span></span></label>
			<label class="fd-consent"><input type="checkbox" name="drops" value="1">
				<span>Also email me when new Fisha pieces drop.</span></label>

			<div class="fr-submit">
				<button type="submit" class="fd-submit">Send my request</button>
				<p class="fisha-lead-msg<?php echo $ok ? ' is-ok' : ( $err ? ' is-err' : '' ); ?>" role="status" aria-live="polite"><?php
					echo $ok ? 'Thanks! Your request is in — we’ve emailed you a copy.' : esc_html( $err ); ?></p>
			</div>
		</form>
	</section>
</div>
<?php
	return ob_get_clean();
} );

/* ------------------------------------------------------------ assets & page */

function fisha_leads_assets() {
	wp_enqueue_style( 'fisha-leads', FISHA_DESIGN_URL . 'leads.css', array(), fisha_asset_ver( 'leads.css' ) );
	wp_enqueue_script( 'fisha-leads', FISHA_DESIGN_URL . 'leads.js', array(), fisha_asset_ver( 'leads.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
}
// Shortcodes in page content render after <head>; enqueue early where we know they'll appear.
add_action( 'wp_enqueue_scripts', function () {
	$post = is_singular() ? get_post() : null;
	if ( ( $post && ( has_shortcode( $post->post_content, 'fisha_request_form' ) || has_shortcode( $post->post_content, 'fisha_drop_signup' ) ) )
		|| ( is_product() && ( $p = wc_get_product( get_queried_object_id() ) ) && ! $p->is_in_stock() ) || is_product_category( fisha_drop_cats() ) || ( is_product_category() && fisha_drop_interest_for_term( get_queried_object() ) !== 'all' ) ) {
		fisha_leads_assets();
	}
}, 20 );

function fisha_is_request_page() {
	if ( ! is_singular() ) return false;
	$post = get_post();
	return $post && has_shortcode( $post->post_content, 'fisha_request_form' );
}
add_filter( 'body_class', function ( $c ) { if ( fisha_is_request_page() ) $c[] = 'fisha-request-page'; return $c; } );
// The hero carries the H1.
add_filter( 'render_block_core/post-title', function ( $html, $block, $instance = null ) {
	if ( ! fisha_is_request_page() ) return $html;
	$pid = ( $instance && isset( $instance->context['postId'] ) ) ? (int) $instance->context['postId'] : get_the_ID();
	return $pid === get_queried_object_id() ? '' : $html;
}, 10, 3 );
// The request page shows per-visitor messages (?fisha_ok) — never serve it from the page cache with those.
add_action( 'template_redirect', function () {
	if ( isset( $_GET['fisha_ok'] ) || isset( $_GET['fisha_err'] ) ) do_action( 'litespeed_control_set_nocache', 'fisha lead message' );
} );

/* --------------------------------------------------------------------- admin */

add_filter( 'manage_fisha_lead_posts_columns', function () {
	return array( 'cb' => '<input type="checkbox">', 'title' => 'Lead', 'fisha_kind' => 'Type', 'fisha_email' => 'Email', 'date' => 'Received' );
} );
add_action( 'manage_fisha_lead_posts_custom_column', function ( $col, $id ) {
	if ( 'fisha_kind' === $col ) { $k = get_post_meta( $id, '_kind', true ); echo 'drop' === $k ? 'Drop list' : ( 'contact' === $k ? 'Message' : 'Request' ); }
	if ( 'fisha_email' === $col ) { $e = get_post_meta( $id, '_email', true ); echo '<a href="mailto:' . esc_attr( $e ) . '">' . esc_html( $e ) . '</a>'; }
}, 10, 2 );
add_filter( 'views_edit-fisha_lead', function ( $views ) {
	$base = admin_url( 'edit.php?post_type=fisha_lead' );
	$cur  = isset( $_GET['fisha_kind'] ) ? sanitize_key( $_GET['fisha_kind'] ) : '';
	$views['fisha_requests'] = '<a href="' . esc_url( add_query_arg( 'fisha_kind', 'request', $base ) ) . '"' . ( 'request' === $cur ? ' class="current"' : '' ) . '>Requests</a>';
	$views['fisha_msgs']     = '<a href="' . esc_url( add_query_arg( 'fisha_kind', 'contact', $base ) ) . '"' . ( 'contact' === $cur ? ' class="current"' : '' ) . '>Messages</a>';
	$views['fisha_drops']    = '<a href="' . esc_url( add_query_arg( 'fisha_kind', 'drop', $base ) ) . '"' . ( 'drop' === $cur ? ' class="current"' : '' ) . '>Drop list</a>';
	$views['fisha_csv']      = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fisha_drop_csv' ), 'fisha_drop_csv' ) ) . '">⬇ Export drop list (CSV)</a>';
	return $views;
} );
add_action( 'pre_get_posts', function ( $q ) {
	if ( ! is_admin() || ! $q->is_main_query() || 'fisha_lead' !== $q->get( 'post_type' ) ) return;
	$q->set( 'post_status', array( 'private' ) );
	if ( ! empty( $_GET['fisha_kind'] ) ) $q->set( 'meta_query', array( array( 'key' => '_kind', 'value' => sanitize_key( $_GET['fisha_kind'] ) ) ) );
} );
add_action( 'admin_post_fisha_drop_csv', function () {
	if ( ! current_user_can( 'edit_posts' ) || ! check_admin_referer( 'fisha_drop_csv' ) ) wp_die( 'Not allowed' );
	$ids = get_posts( array( 'post_type' => 'fisha_lead', 'post_status' => 'private', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_kind', 'meta_value' => 'drop' ) );
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=fisha-drop-list-' . gmdate( 'Y-m-d' ) . '.csv' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" );
	fputcsv( $out, array( 'email', 'interest', 'signed_up', 'consent', 'source' ) );
	foreach ( $ids as $id ) fputcsv( $out, array( get_post_meta( $id, '_email', true ), fisha_drop_interest_label( get_post_meta( $id, '_interest', true ) ), get_the_date( 'Y-m-d H:i', $id ), get_post_meta( $id, '_consent', true ), get_post_meta( $id, '_source', true ) ) );
	exit;
} );
add_action( 'add_meta_boxes_fisha_lead', function () {
	add_meta_box( 'fisha_lead_details', 'Details', function ( $post ) {
		$m = array();
		foreach ( array( 'kind', 'type', 'name', 'email', 'phone', 'order_no', 'interest', 'size', 'placement', 'budget', 'timing', 'source', 'consent' ) as $k ) {
			$v = get_post_meta( $post->ID, '_' . $k, true );
			if ( '' !== $v && null !== $v ) $m[ $k ] = $v;
		}
		if ( isset( $m['type'] ) ) $m['type'] = fisha_request_types()[ $m['type'] ] ?? ( function_exists( 'fisha_contact_topics' ) && isset( fisha_contact_topics()[ $m['type'] ] ) ? fisha_contact_topics()[ $m['type'] ][0] : $m['type'] );
		if ( isset( $m['budget'] ) ) $m['budget'] = fisha_request_budgets()[ $m['budget'] ] ?? $m['budget'];
		if ( isset( $m['interest'] ) ) $m['interest'] = fisha_drop_interest_label( $m['interest'] );
		echo '<table class="widefat striped"><tbody>';
		foreach ( $m as $k => $v ) echo '<tr><th style="width:140px">' . esc_html( ucfirst( $k ) ) . '</th><td>' . ( 'email' === $k ? '<a href="mailto:' . esc_attr( $v ) . '">' . esc_html( $v ) . '</a>' : esc_html( $v ) ) . '</td></tr>';
		$idea = get_post_meta( $post->ID, '_idea', true );
		if ( $idea ) echo '<tr><th>Idea</th><td>' . nl2br( esc_html( $idea ) ) . '</td></tr>';
		$files = (array) get_post_meta( $post->ID, '_files', true );
		if ( array_filter( $files ) ) {
			echo '<tr><th>Images</th><td>';
			foreach ( array_filter( $files ) as $u ) echo '<a href="' . esc_url( $u ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $u ) . '" alt="" style="max-width:180px;max-height:180px;margin:0 8px 8px 0;border-radius:6px;object-fit:cover"></a>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}, 'fisha_lead', 'normal', 'high' );
} );
