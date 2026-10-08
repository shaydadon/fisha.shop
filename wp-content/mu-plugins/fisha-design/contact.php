<?php
/**
 * Contact page — [fisha_contact]
 *
 * One idea: your message goes into the river and Fisha carries it.
 *  - hero: the river drawing swims in once, then stays still
 *  - "What's it about?" cards; picking one opens the form (Tattoo / commission goes to /request/)
 *  - on send, the card folds away, one of the hand-drawn loops (pebble or egg) plays, then the thank-you appears
 *  - direct ways to reach us (email, WhatsApp) + reply time
 * Submissions go through the REST API (POST fisha/v1/contact) and are kept under "Fisha leads".
 * Stickers: wp-content/uploads/fisha-contact/ (built by tools/build-loops.py; we use the still frames).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_is_contact_page() {
	if ( ! is_singular() ) return false;
	$post = get_post();
	return $post && has_shortcode( $post->post_content, 'fisha_contact' );
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! fisha_is_contact_page() ) return;
	wp_enqueue_style( 'fisha-contact', FISHA_DESIGN_URL . 'contact.css', array( 'fisha-design' ), fisha_asset_ver( 'contact.css' ) );
	wp_enqueue_script( 'fisha-contact', FISHA_DESIGN_URL . 'contact.js', array(), fisha_asset_ver( 'contact.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
} );

add_filter( 'body_class', function ( $c ) {
	if ( fisha_is_contact_page() ) $c[] = 'fisha-contact-page';
	return $c;
} );

// The hero carries the H1 — drop the theme's page-title block here.
add_filter( 'render_block_core/post-title', function ( $html, $block, $instance = null ) {
	if ( ! fisha_is_contact_page() ) return $html;
	$pid = ( $instance && isset( $instance->context['postId'] ) ) ? (int) $instance->context['postId'] : get_the_ID();
	return $pid === get_queried_object_id() ? '' : $html;
}, 10, 3 );

// Contact details (filterable, e.g. when the @fisha.shop address is ready).
function fisha_contact_details() {
	return apply_filters( 'fisha_contact_details', array(
		'email'    => 'ShakedZhaor@gmail.com',
		'whatsapp' => '972543131823',
		'phone'    => '+972 54-313-1823',
		'reply'    => 'We usually reply within two working days.',
	) );
}

function fisha_contact_topics() {
	return array(
		'order' => array( 'An order', 'Shipping, returns or a question about your order' ),
		'collab' => array( 'Collab or press', 'Shops, brands, exhibitions and interviews' ),
		'hello' => array( 'Just saying hi', 'Fisha sightings, kind words, anything else' ),
	);
}

function fisha_contact_stills() {
	$d = fisha_river_dir( 'fisha-contact' );
	$f = $d['path'] . 'manifest.json';
	if ( ! file_exists( $f ) ) return array();
	$v = '?v=' . filemtime( $f );
	$out = array();
	foreach ( json_decode( file_get_contents( $f ), true ) as $it ) {
		$it['src'] = $d['url'] . $it['still'] . $v;
		$out[ $it['slug'] ] = $it;
	}
	return $out;
}
function fisha_contact_img( $stills, $slug, $class, $eager = false, $decorative = true ) {
	if ( empty( $stills[ $slug ] ) ) return '';
	$s = $stills[ $slug ];
	return '<img class="' . esc_attr( $class ) . '" src="' . esc_url( $s['src'] ) . '" width="' . (int) $s['w'] . '" height="' . (int) $s['h'] . '" alt="' . ( $decorative ? '' : esc_attr( $s['alt'] ) ) . '"'
		. ( $decorative ? ' aria-hidden="true"' : '' ) . ' loading="' . ( $eager ? 'eager' : 'lazy' ) . '" decoding="async" draggable="false">';
}

/* ------------------------------------------------------------------ REST */

add_action( 'rest_api_init', function () {
	register_rest_route( 'fisha/v1', '/contact', array( 'methods' => 'POST', 'callback' => 'fisha_rest_contact', 'permission_callback' => '__return_true' ) );
} );

function fisha_rest_contact( WP_REST_Request $r ) {
	$anchor = 'fisha-contact-form';
	if ( $err = fisha_lead_gate( $r, 'contact' ) ) {
		return 'spam' === $err ? fisha_lead_reply( $r, true, 'Thanks — your message is in the river.', $anchor ) : fisha_lead_reply( $r, false, $err, $anchor );
	}
	$topics = fisha_contact_topics();
	$f = array(
		'topic'   => sanitize_key( (string) $r->get_param( 'topic' ) ),
		'name'    => sanitize_text_field( (string) $r->get_param( 'name' ) ),
		'email'   => sanitize_email( (string) $r->get_param( 'email' ) ),
		'order'   => sanitize_text_field( (string) $r->get_param( 'order' ) ),
		'message' => sanitize_textarea_field( (string) $r->get_param( 'message' ) ),
	);
	$missing = array();
	if ( ! isset( $topics[ $f['topic'] ] ) ) $missing[] = 'what it’s about';
	if ( '' === $f['name'] ) $missing[] = 'your name';
	if ( ! is_email( $f['email'] ) ) $missing[] = 'a valid email';
	if ( mb_strlen( $f['message'] ) < 2 ) $missing[] = 'a message';
	if ( $missing ) return fisha_lead_reply( $r, false, 'Please add ' . implode( ', ', $missing ) . '.', $anchor );
	if ( 'order' !== $f['topic'] ) $f['order'] = '';

	$label = $topics[ $f['topic'] ][0];
	$id = fisha_lead_save( 'contact', $label . ' · ' . $f['name'], array(
		'name' => $f['name'], 'email' => $f['email'], 'type' => $f['topic'], 'order_no' => $f['order'], 'idea' => $f['message'],
		'source' => esc_url_raw( (string) $r->get_param( 'source' ) ), 'ip' => fisha_client_ip(),
	) );
	$body = "Topic: $label\nName: {$f['name']}\nEmail: {$f['email']}\n" . ( $f['order'] ? "Order: {$f['order']}\n" : '' ) . "\n{$f['message']}\n";
	if ( $id ) $body .= "\nIn wp-admin: " . admin_url( 'post.php?post=' . $id . '&action=edit' ) . "\n";
	wp_mail( get_option( 'admin_email' ), '[Fisha] ' . $label . ' — message from ' . $f['name'], $body, array( 'Reply-To: ' . $f['name'] . ' <' . $f['email'] . '>' ) );

	$first = strtok( $f['name'], ' ' );
	return fisha_lead_reply( $r, true, 'Thanks, ' . $first . '! Your message is in the river.', $anchor );
}

/* ------------------------------------------------------------- shortcode */

add_shortcode( 'fisha_contact', function ( $atts ) {
	$a = shortcode_atts( array(
		'form'    => '', // legacy (Contact Form 7 id) — no longer used
		'eyebrow' => 'Contact',
		'title'   => 'Say hello to Fisha',
		'text'    => 'Drop a line into the river and Fisha will carry it to us. Every message gets read and answered.',
		'note'    => 'Born on Tel Aviv streets, swimming your way.',
	), $atts, 'fisha_contact' );
	$st     = fisha_contact_stills();
	$d      = fisha_contact_details();
	$topics = fisha_contact_topics();
	$pre    = isset( $_GET['topic'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['topic'] ) ) ) : '';
	$sel    = isset( $topics[ $pre ] ) ? $pre : ( false !== strpos( $pre, 'collab' ) ? 'collab' : ( false !== strpos( $pre, 'order' ) ? 'order' : '' ) );
	$ok     = isset( $_GET['fisha_ok'] ) && 'fisha-contact-form' === $_GET['fisha_ok'];
	$err    = isset( $_GET['fisha_err'] ) ? sanitize_text_field( wp_unslash( $_GET['fisha_err'] ) ) : '';
	$wa     = 'https://wa.me/' . rawurlencode( $d['whatsapp'] ) . '?text=' . rawurlencode( 'Hi Fisha! ' );
	ob_start(); ?>
<div class="fisha-contact">
	<header class="fc-hero">
		<p class="fc-eyebrow"><?php echo esc_html( $a['eyebrow'] ); ?></p>
		<h1 class="fc-title"><?php echo esc_html( $a['title'] ); ?></h1>
		<p class="fc-lead"><?php echo esc_html( $a['text'] ); ?></p>
		<div class="fc-river"><?php echo fisha_contact_img( $st, 'loop-fisha', 'fc-river__img', true, false ); ?></div>
	</header>

	<section class="fc-stage" aria-label="Send us a message">
		<div class="fc-flight" id="fisha-contact-form">
			<div class="fc-card">
				<span class="fc-card__tape" aria-hidden="true"></span>
				<form class="fc-form" method="post" action="<?php echo esc_url( rest_url( 'fisha/v1/contact' ) ); ?>" novalidate<?php echo $sel ? ' data-topic="' . esc_attr( $sel ) . '"' : ''; ?>>
					<input type="hidden" name="source" value="">
					<input type="hidden" name="fisha_nojs" value="1">
					<input type="hidden" name="fisha_ms" value="">
					<p class="fisha-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>

					<fieldset class="fc-topics">
						<legend class="fc-card__title">What’s it about?</legend>
						<?php foreach ( $topics as $k => $t ) : ?>
						<label class="fc-topic">
							<input type="radio" name="topic" value="<?php echo esc_attr( $k ); ?>" required<?php checked( $sel, $k ); ?>>
							<span class="fc-topic__box"><strong><?php echo esc_html( $t[0] ); ?></strong><span><?php echo esc_html( $t[1] ); ?></span></span>
						</label>
						<?php endforeach; ?>
						<a class="fc-topic fc-topic--link" href="<?php echo esc_url( function_exists( 'fisha_request_page_url' ) ? fisha_request_page_url( 'tattoo' ) : home_url( '/request/' ) ); ?>">
							<span class="fc-topic__box"><strong>Tattoo or commission <span aria-hidden="true">↗</span></strong><span>Has its own form, with room for reference images</span></span>
						</a>
					</fieldset>

					<div class="fc-body">
						<p class="fc-card__sub">Fields marked <span class="fc-req" aria-hidden="true">*</span><span class="screen-reader-text">with an asterisk</span> are required.</p>
						<div class="fc-grid">
							<label class="fc-field"><span>Name <span class="fc-req" aria-hidden="true">*</span></span>
								<input type="text" name="name" required autocomplete="name" placeholder="What’s your name?"></label>
							<label class="fc-field"><span>Email <span class="fc-req" aria-hidden="true">*</span></span>
								<input type="email" name="email" required autocomplete="email" inputmode="email" placeholder="you@example.com"></label>
							<label class="fc-field fc-field--full fc-field--order"><span>Order number <em>(if you have it)</em></span>
								<input type="text" name="order" inputmode="numeric" placeholder="e.g. 1042"></label>
							<label class="fc-field fc-field--full"><span>Message <span class="fc-req" aria-hidden="true">*</span></span>
								<textarea name="message" required rows="6" placeholder="Write your message…"></textarea></label>
						</div>
						<div class="fc-submit">
							<button type="submit" class="fc-send">Send into the river</button>
							<p class="fisha-lead-msg<?php echo $err ? ' is-err' : ''; ?>" role="status" aria-live="polite"><?php echo esc_html( $err ); ?></p>
						</div>
					</div>
				</form>
			</div>
		</div>

		<?php
		$loops = array();
		foreach ( array( 'loop-pebble', 'loop-egg' ) as $slug ) {
			if ( empty( $st[ $slug ] ) ) continue;
			$l = $st[ $slug ];
			$loops[] = array( 'src' => str_replace( $l['still'], $l['file'], $l['src'] ), 'still' => $l['src'], 'w' => (int) $l['w'], 'h' => (int) $l['h'], 'ms' => (int) $l['ms'] );
		}
		?>
		<div class="fc-thanks"<?php echo $ok ? '' : ' hidden'; ?> tabindex="-1" data-loops="<?php echo esc_attr( wp_json_encode( $loops, JSON_UNESCAPED_SLASHES ) ); ?>">
			<div class="fc-thanks__art"><?php echo fisha_contact_img( $st, 'loop-egg', 'fc-thanks__img' ); ?></div>
			<p class="fc-thanks__sending" aria-hidden="true">Swimming your message down the river…</p>
			<div class="fc-thanks__done">
			<h2 class="fc-thanks__title">Your message is in the river</h2>
			<p class="fc-thanks__text"><span class="fc-thanks__who"></span><?php echo esc_html( $d['reply'] ); ?> Keep an eye on your inbox.</p>
			<button type="button" class="fc-again">Send another message</button>
			</div>
		</div>
	</section>

	<section class="fc-direct" aria-labelledby="fc-direct-h">
		<h2 id="fc-direct-h" class="fc-direct__title">Rather write directly?</h2>
		<ul class="fc-direct__list">
			<li><a href="mailto:<?php echo esc_attr( $d['email'] ); ?>"><span class="fc-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path d="M3 6h18v12H3z M3 7l9 6 9-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg></span><span><strong>Email</strong><?php echo esc_html( $d['email'] ); ?></span></a></li>
			<li><a href="<?php echo esc_url( $wa ); ?>" target="_blank" rel="noopener"><span class="fc-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path d="M4 20l1.3-3.9A8 8 0 1 1 8 19.2z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 9.5c.3 2 1.8 3.8 4 4.6l1.2-1.1 1.8.8c-.2 1-1 1.7-2 1.7-3.4-.4-6-3-6.4-6.3 0-1 .7-1.8 1.7-2l.8 1.8z" fill="currentColor"/></svg></span><span><strong>WhatsApp</strong><?php echo esc_html( $d['phone'] ); ?><span class="screen-reader-text"> (opens WhatsApp)</span></span></a></li>
		</ul>
		<p class="fc-direct__reply"><?php echo esc_html( $d['reply'] ); ?></p>
	</section>

	<p class="fc-note"><?php echo esc_html( $a['note'] ); ?></p>
</div>
<?php
	return ob_get_clean();
} );
