<?php
/**
 * Contact page — [fisha_contact form="<CF7 form id>"]
 * The CF7 form sits on a paper card; the hand-drawn loops (wp-content/uploads/fisha-contact/, built by
 * tools/build-loops.py) float around it as transparent animated WebP stickers — images, not videos.
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

// CF7: our form template has its own markup, no auto <p>/<br>.
add_filter( 'wpcf7_autop_or_not', '__return_false' );

function fisha_contact_loops() {
	$d = fisha_river_dir( 'fisha-contact' );
	$f = $d['path'] . 'manifest.json';
	if ( ! file_exists( $f ) ) return array();
	$v = '?v=' . filemtime( $f );
	$out = array();
	foreach ( json_decode( file_get_contents( $f ), true ) as $it ) {
		$it['src']   = $d['url'] . $it['file'] . $v;
		$it['still'] = $d['url'] . $it['still'] . $v;
		$out[ $it['slug'] ] = $it;
	}
	return $out;
}

/** One looping sticker. Reduced-motion visitors get the still first frame. */
function fisha_contact_loop( $loops, $slug, $class = '', $eager = false ) {
	if ( empty( $loops[ $slug ] ) ) return '';
	$l = $loops[ $slug ];
	return '<figure class="fc-loop fc-loop--' . esc_attr( str_replace( 'loop-', '', $slug ) ) . ' ' . esc_attr( $class ) . '">'
		. '<picture><source media="(prefers-reduced-motion: reduce)" srcset="' . esc_url( $l['still'] ) . '">'
		. '<img src="' . esc_url( $l['src'] ) . '" width="' . (int) $l['w'] . '" height="' . (int) $l['h'] . '" alt="' . esc_attr( $l['alt'] ) . '" loading="' . ( $eager ? 'eager' : 'lazy' ) . '" decoding="async" draggable="false">'
		. '</picture></figure>';
}

add_shortcode( 'fisha_contact', function ( $atts ) {
	$a = shortcode_atts( array(
		'form'    => '',
		'eyebrow' => 'Contact',
		'title'   => 'Say hello to Fisha',
		'text'    => 'Questions, collabs, tattoo ideas or Fisha sightings? Drop a line into the river — every message gets read and answered.',
		'note'    => 'Born on Tel Aviv streets, swimming your way.',
	), $atts, 'fisha_contact' );
	$loops = fisha_contact_loops();
	$form  = $a['form'] ? do_shortcode( '[contact-form-7 id="' . absint( $a['form'] ) . '" html_class="fc-form"]' ) : '';
	ob_start(); ?>
<div class="fisha-contact">
	<section class="fc-hero" aria-labelledby="fc-title">
		<p class="fc-eyebrow"><?php echo esc_html( $a['eyebrow'] ); ?></p>
		<h1 id="fc-title" class="fc-title"><?php echo esc_html( $a['title'] ); ?></h1>
		<p class="fc-lead"><?php echo esc_html( $a['text'] ); ?></p>
		<?php echo fisha_contact_loop( $loops, 'loop-jump', 'fc-hero__river', true ); ?>
	</section>

	<section class="fc-stage" aria-label="Contact form">
		<div class="fc-side fc-side--left">
			<?php echo fisha_contact_loop( $loops, 'loop-fisha', 'fc-float fc-float--a', true ); ?>
			<?php echo fisha_contact_loop( $loops, 'loop-pebble', 'fc-float fc-float--b' ); ?>
		</div>

		<div class="fc-card">
			<span class="fc-card__tape" aria-hidden="true"></span>
			<h2 class="fc-card__title">Send a message</h2>
			<p class="fc-card__sub">Fields marked <span class="fc-req" aria-hidden="true">*</span><span class="screen-reader-text">with an asterisk</span> are required.</p>
			<?php echo $form ? $form : '<p>Form coming soon.</p>'; ?>
		</div>

		<div class="fc-side fc-side--right">
			<?php echo fisha_contact_loop( $loops, 'loop-stone', 'fc-float fc-float--c', true ); ?>
			<?php echo fisha_contact_loop( $loops, 'loop-egg', 'fc-float fc-float--d' ); ?>
		</div>
	</section>

	<p class="fc-note"><?php echo esc_html( $a['note'] ); ?></p>
</div>
<?php
	return ob_get_clean();
} );
