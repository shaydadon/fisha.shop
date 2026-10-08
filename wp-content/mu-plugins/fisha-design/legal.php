<?php
/**
 * Legal pages (Terms & Conditions, policies) — [fisha_legal title="…" intro="…"] ## Question / answer … [/fisha_legal]
 * Content: blocks separated by lines starting with "## " (the question/section title); the lines under it are the answer.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_is_legal_page() {
	if ( ! is_singular() ) return false;
	$post = get_post();
	return $post && has_shortcode( $post->post_content, 'fisha_legal' );
}

add_action( 'wp_enqueue_scripts', function () {
	if ( fisha_is_legal_page() ) wp_enqueue_style( 'fisha-legal', FISHA_DESIGN_URL . 'legal.css', array( 'fisha-design' ), fisha_asset_ver( 'legal.css' ) );
} );
add_filter( 'body_class', function ( $c ) { if ( fisha_is_legal_page() ) $c[] = 'fisha-legal-page'; return $c; } );

// The hero carries the H1 — drop the theme's page-title block.
add_filter( 'render_block_core/post-title', function ( $html, $block, $instance = null ) {
	if ( ! fisha_is_legal_page() ) return $html;
	$pid = ( $instance && isset( $instance->context['postId'] ) ) ? (int) $instance->context['postId'] : get_the_ID();
	return $pid === get_queried_object_id() ? '' : $html;
}, 10, 3 );

add_shortcode( 'fisha_legal', function ( $atts, $content = '' ) {
	$a = shortcode_atts( array(
		'eyebrow' => 'Fisha Shop',
		'title'   => get_the_title(),
		'intro'   => '',
		'cta'     => 'Contact us',
		'url'     => '/contact/',
	), $atts, 'fisha_legal' );

	// parse "## Title" sections
	$sections = array(); $cur = null;
	foreach ( preg_split( '/\R/', trim( wp_strip_all_tags( str_replace( array( '<br />', '<br>', '</p>' ), "\n", $content ) ) ) ) as $line ) {
		$line = trim( html_entity_decode( $line ) );
		if ( '' === $line ) continue;
		if ( 0 === strpos( $line, '## ' ) ) { if ( $cur ) $sections[] = $cur; $cur = array( 'q' => substr( $line, 3 ), 'a' => array() ); }
		elseif ( $cur ) $cur['a'][] = $line;
	}
	if ( $cur ) $sections[] = $cur;

	$updated = get_the_modified_date( 'j F Y' );
	ob_start(); ?>
<div class="fisha-legal">
	<header class="fl-hero">
		<p class="fl-eyebrow"><?php echo esc_html( $a['eyebrow'] ); ?></p>
		<h1 class="fl-title"><?php echo esc_html( $a['title'] ); ?></h1>
		<?php if ( $a['intro'] ) : ?><p class="fl-intro"><?php echo esc_html( $a['intro'] ); ?></p><?php endif; ?>
		<p class="fl-updated">Last updated <time datetime="<?php echo esc_attr( get_the_modified_date( 'Y-m-d' ) ); ?>"><?php echo esc_html( $updated ); ?></time></p>
	</header>

	<div class="fl-body">
		<?php if ( count( $sections ) > 2 ) : ?>
		<nav class="fl-toc" aria-label="On this page">
			<p class="fl-toc__label">On this page</p>
			<ol>
				<?php foreach ( $sections as $i => $s ) : ?>
					<li><a href="#fl-<?php echo (int) $i + 1; ?>"><?php echo esc_html( $s['q'] ); ?></a></li>
				<?php endforeach; ?>
			</ol>
		</nav>
		<?php endif; ?>

		<div class="fl-sections">
			<?php foreach ( $sections as $i => $s ) : ?>
			<section class="fl-section" id="fl-<?php echo (int) $i + 1; ?>" aria-labelledby="fl-h-<?php echo (int) $i + 1; ?>">
				<span class="fl-num" aria-hidden="true"><?php echo esc_html( str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span>
				<div>
					<h2 id="fl-h-<?php echo (int) $i + 1; ?>"><?php echo esc_html( $s['q'] ); ?></h2>
					<?php foreach ( $s['a'] as $p ) : ?><p><?php echo esc_html( $p ); ?></p><?php endforeach; ?>
				</div>
			</section>
			<?php endforeach; ?>

			<aside class="fl-help">
				<img src="<?php echo esc_url( FISHA_DESIGN_URL . 'img/fisha-body.png' ); ?>" alt="" aria-hidden="true" width="96" height="68">
				<div>
					<p class="fl-help__title">Still have a question?</p>
					<p>We’re happy to help with orders, returns and anything else.</p>
				</div>
				<?php echo do_shortcode( '[fisha_cta text="' . esc_attr( $a['cta'] ) . '" url="' . esc_attr( $a['url'] ) . '"]' ); ?>
			</aside>
		</div>
	</div>
</div>
<?php
	return ob_get_clean();
} );
