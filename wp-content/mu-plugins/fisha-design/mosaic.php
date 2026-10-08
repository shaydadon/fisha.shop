<?php
/**
 * River — sketch mosaic (pinboard).
 * Usage in a page:  [fisha_mosaic] Hebrew intro text… [/fisha_mosaic]
 * Sketches come from wp-content/uploads/fisha-river/manifest.json (built from river-source by tools/build-mosaic.py).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_is_mosaic_page() {
	if ( ! is_singular() ) return false;
	$post = get_post();
	return $post && has_shortcode( $post->post_content, 'fisha_mosaic' );
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! fisha_is_mosaic_page() ) return;
	wp_enqueue_style( 'fisha-river-font', 'https://fonts.googleapis.com/css2?family=Varela+Round&display=swap', array(), null );
	wp_enqueue_style( 'fisha-river', FISHA_DESIGN_URL . 'river.css', array(), fisha_asset_ver( 'river.css' ) ); // shared viewer + intro styles
	wp_enqueue_style( 'fisha-mosaic', FISHA_DESIGN_URL . 'mosaic.css', array( 'fisha-river' ), fisha_asset_ver( 'mosaic.css' ) );
	wp_enqueue_script( 'fisha-mosaic', FISHA_DESIGN_URL . 'mosaic.js', array(), fisha_asset_ver( 'mosaic.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
} );

add_filter( 'body_class', function ( $c ) {
	if ( fisha_is_mosaic_page() ) $c[] = 'fisha-river-page';
	return $c;
} );

// The mosaic hero carries the H1 — drop the theme's page-title block on River.
add_filter( 'render_block_core/post-title', function ( $html, $block, $instance = null ) {
	if ( ! fisha_is_mosaic_page() ) return $html;
	$pid = ( $instance && isset( $instance->context['postId'] ) ) ? (int) $instance->context['postId'] : get_the_ID();
	return $pid === get_queried_object_id() ? '' : $html;
}, 10, 3 );

add_shortcode( 'fisha_mosaic', function ( $atts, $content = '' ) {
	$d = fisha_river_dir( 'fisha-river' );
	$f = $d['path'] . 'manifest.json';
	$m = file_exists( $f ) ? json_decode( file_get_contents( $f ), true ) : array( 'images' => array() );
	$v = '?v=' . ( file_exists( $f ) ? filemtime( $f ) : 0 );
	$tilts = array( -1.6, 1.2, -0.7, 1.8, -1.2, 0.6, 1.4, -1.9, 0.9, -0.4 );
	$tapes = array( 'orange', 'blue', 'beige' );
	ob_start(); ?>
<section class="fisha-mosaic" aria-label="River — Fisha sketches">
	<header class="fisha-mosaic__hero">
		<p class="fisha-mosaic__eyebrow">From the sketchbook</p>
		<h1 class="fisha-mosaic__title"><?php echo esc_html( get_the_title() ); ?></h1>
		<?php if ( trim( (string) $content ) ) : ?>
		<?php $is_he = (bool) preg_match( '/\p{Hebrew}/u', $content ); ?>
		<div class="fisha-river__intro" dir="<?php echo $is_he ? 'rtl' : 'ltr'; ?>" lang="<?php echo $is_he ? 'he' : 'en'; ?>"><?php echo wpautop( wp_kses_post( trim( $content ) ) ); ?></div>
		<?php endif; ?>
		<p class="fisha-mosaic__hint">Tap any sketch to see it up close.</p>
	</header>
	<div class="fisha-mosaic__board">
		<div class="fisha-mosaic__grid">
			<?php foreach ( $m['images'] as $i => $img ) :
				$tilt = $tilts[ $i % count( $tilts ) ];
				$tape = $tapes[ ( $i * 7 ) % 3 ];
				$tx   = ( ( $i * 37 ) % 40 ) - 20; // tape offset from centre, %
				$ta   = ( ( $i * 53 ) % 14 ) - 7;  // tape angle
			?>
			<figure class="fisha-mosaic__item" data-index="<?php echo (int) $i; ?>" style="--tilt:<?php echo $tilt; ?>deg;--tape-x:<?php echo $tx; ?>%;--tape-a:<?php echo $ta; ?>deg">
				<button type="button" class="fisha-mosaic__open" data-full="<?php echo esc_url( $d['url'] . $img['full'] . $v ); ?>" data-fw="<?php echo (int) $img['fw']; ?>" data-fh="<?php echo (int) $img['fh']; ?>" aria-label="<?php echo esc_attr( sprintf( 'Open sketch %d', $i + 1 ) ); ?>">
					<span class="fisha-mosaic__tape fisha-mosaic__tape--<?php echo esc_attr( $tape ); ?>" aria-hidden="true"></span>
					<img src="<?php echo esc_url( $d['url'] . $img['tile'] . $v ); ?>" width="<?php echo (int) $img['w']; ?>" height="<?php echo (int) $img['h']; ?>" alt="" loading="<?php echo $i < 8 ? 'eager' : 'lazy'; ?>" decoding="async" draggable="false">
				</button>
			</figure>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php
	return ob_get_clean();
} );

/**
 * Homepage teaser for River: [fisha_river_teaser]
 * Attributes: eyebrow, title, text, cta, url, slugs (comma list of sketch slugs, in order).
 */
add_shortcode( 'fisha_river_teaser', function ( $atts ) {
	$atts = shortcode_atts( array(
		'eyebrow' => 'From the sketchbook',
		'title'   => 'River — Original Sketches & Illustrations',
		'text'    => 'A flowing collection of sketches, drawings and illustrations. Wander through the creative process and watch ideas take shape.',
		'cta'     => 'Explore the River',
		'url'     => '/river/',
		'slugs'   => '00-0002-scan-16,artboard-2,fisha-watercolor,fisha-rivar-0001-scan-8,sprea,00-0005-fisha-tallks',
	), $atts, 'fisha_river_teaser' );
	$alts = array(
		'00-0002-scan-16'          => 'Watercolor sketch of Fisha fish swimming above river stones',
		'artboard-2'               => 'Ink and watercolor drawing of a pile of yellow Fisha fish among stones',
		'fisha-watercolor'         => 'Watercolor illustration of stacked pink Fisha fish',
		'fisha-rivar-0001-scan-8'  => 'Black ink drawing of a spotted Fisha fish',
		'sprea'                    => 'Orange watercolor of Fisha glowing on paper',
		'00-0005-fisha-tallks'     => 'Ink sketch of Fisha fish talking in speech bubbles',
	);
	$d = fisha_river_dir( 'fisha-river' );
	$f = $d['path'] . 'manifest.json';
	if ( ! file_exists( $f ) ) return '';
	$by = array();
	foreach ( json_decode( file_get_contents( $f ), true )['images'] as $img ) $by[ $img['slug'] ] = $img;
	$v = '?v=' . filemtime( $f );
	$url = esc_url( home_url( $atts['url'] ) );
	$tilts = array( -1.8, 1.4, -0.8, 1.9, -1.3, 0.9 );
	$tapes = array( 'orange', 'blue', 'beige' );
	ob_start(); ?>
<section class="fisha-teaser" aria-labelledby="fisha-teaser-title">
	<?php if ( $atts['eyebrow'] ) : ?><p class="fisha-teaser__eyebrow"><?php echo esc_html( $atts['eyebrow'] ); ?></p><?php endif; ?>
	<h2 id="fisha-teaser-title" class="fisha-teaser__title"><?php echo esc_html( $atts['title'] ); ?></h2>
	<?php if ( $atts['text'] ) : ?><p class="fisha-teaser__text"><?php echo esc_html( $atts['text'] ); ?></p><?php endif; ?>
	<div class="fisha-teaser__board">
		<ul class="fisha-teaser__grid">
			<?php $i = 0; foreach ( array_map( 'trim', explode( ',', $atts['slugs'] ) ) as $slug ) :
				if ( empty( $by[ $slug ] ) ) continue;
				$img = $by[ $slug ]; ?>
			<li class="fisha-teaser__item" style="--tilt:<?php echo $tilts[ $i % 6 ]; ?>deg">
				<a class="fisha-teaser__card" href="<?php echo $url; ?>">
					<span class="fisha-teaser__tape fisha-mosaic__tape--<?php echo $tapes[ $i % 3 ]; ?>" aria-hidden="true"></span>
					<img src="<?php echo esc_url( $d['url'] . $img['tile'] . $v ); ?>" width="<?php echo (int) $img['w']; ?>" height="<?php echo (int) $img['h']; ?>" alt="<?php echo esc_attr( $alts[ $slug ] ?? 'Original Fisha sketch' ); ?>" loading="lazy" decoding="async">
				</a>
			</li>
			<?php $i++; endforeach; ?>
		</ul>
	</div>
	<a class="fisha-teaser__cta" href="<?php echo $url; ?>"><?php echo esc_html( $atts['cta'] ); ?> <span aria-hidden="true">→</span></a>
</section>
<?php
	return ob_get_clean();
} );
