<?php
/**
 * River — Fisha sketchbook gallery.
 * Usage in a page:  [fisha_river] Hebrew intro text… [/fisha_river]
 * Images + music come from wp-content/uploads/fisha-foundation/ (built from foundation-source by tools/build-river.py) (built by tools/build-river.py).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_river_dir( $folder = 'fisha-foundation' ) {
	$u = wp_upload_dir( null, false );
	return array( 'path' => trailingslashit( $u['basedir'] ) . $folder . '/', 'url' => trailingslashit( $u['baseurl'] ) . $folder . '/' );
}

function fisha_river_manifest( $file = 'manifest.json' ) {
	$d = fisha_river_dir();
	$f = $d['path'] . $file;
	if ( ! file_exists( $f ) ) return array( 'images' => array(), 'music' => null, 'ver' => 0 );
	$m = json_decode( file_get_contents( $f ), true );
	$m = is_array( $m ) ? $m : array();
	$m['images'] = $m['images'] ?? array();
	$m['music']  = $m['music'] ?? null;
	$m['ver']    = filemtime( $f );
	return $m;
}

function fisha_is_river_page() {
	if ( ! is_singular() ) return false;
	$post = get_post();
	return $post && has_shortcode( $post->post_content, 'fisha_river' );
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! fisha_is_river_page() ) return;
	wp_enqueue_style( 'fisha-river-font', 'https://fonts.googleapis.com/css2?family=Varela+Round&display=swap', array(), null );
	wp_enqueue_style( 'fisha-river', FISHA_DESIGN_URL . 'river.css', array(), fisha_asset_ver( 'river.css' ) );
	wp_enqueue_script( 'fisha-river', FISHA_DESIGN_URL . 'river.js', array(), fisha_asset_ver( 'river.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
} );

add_filter( 'body_class', function ( $c ) {
	if ( fisha_is_river_page() ) $c[] = 'fisha-river-page';
	return $c;
} );

add_shortcode( 'fisha_river', function ( $atts, $content = '' ) {
	$atts = shortcode_atts( array( 'version' => '', 'direction' => 'left', 'fish' => '', 'music' => '' ), $atts, 'fisha_river' );
	$hd   = ( 'hd' === $atts['version'] );
	$m    = fisha_river_manifest( $hd ? 'manifest-hd.json' : 'manifest.json' );
	$capf = fisha_river_dir()['path'] . 'captions.json';
	$caps = file_exists( $capf ) ? (array) json_decode( file_get_contents( $capf ), true ) : array();
	$d = fisha_river_dir();
	$v = '?v=' . $m['ver'];
	ob_start(); ?>
<section class="fisha-river<?php echo $hd ? ' fisha-river--hd' : ''; ?>" aria-label="River — Fisha sketchbook" data-direction="<?php echo 'right' === $atts['direction'] ? 'right' : 'left'; ?>" data-fish="<?php echo 'with' === $atts['fish'] ? 'with' : 'against'; ?>"<?php if ( 'always' === $atts['music'] ) echo ' data-music="always"'; ?><?php if ( $hd ) echo ' data-osd="' . esc_url( FISHA_DESIGN_URL . 'vendor/openseadragon.min.js?v=4.1.0' ) . '"'; ?>>
	<?php if ( trim( (string) $content ) ) : ?>
	<div class="fisha-river__intro" dir="rtl" lang="he"><?php echo wpautop( wp_kses_post( trim( $content ) ) ); ?></div>
	<?php endif; ?>

	<?php if ( $m['images'] ) : ?>
	<div class="fisha-river__stage">
		<div class="fisha-river__track" tabindex="0" role="region" aria-roledescription="carousel" aria-label="Artwork — drag, swipe or use the arrows; hover to pause">
			<?php foreach ( $m['images'] as $i => $img ) : ?>
			<?php $cap = $caps[ preg_replace( '/-(view|hd)\.jpg$/', '', $img['view'] ) ] ?? null; ?>
			<figure class="fisha-river__slide" data-index="<?php echo (int) $i; ?>">
				<div class="fisha-river__frame">
				<img src="<?php echo esc_url( $d['url'] . $img['view'] . $v ); ?>" width="<?php echo (int) $img['w']; ?>" height="<?php echo (int) $img['h']; ?>" alt="<?php echo esc_attr( $img['alt'] ); ?>" loading="<?php echo $i < 3 ? 'eager' : 'lazy'; ?>" decoding="async" draggable="false"
					<?php if ( $hd ) : ?>data-dzi="<?php echo esc_url( $d['url'] . $img['dzi'] . $v ); ?>"<?php else : ?>data-zoom="<?php echo esc_url( $d['url'] . $img['zoom'] . $v ); ?>"<?php endif; ?> data-zw="<?php echo (int) $img['zw']; ?>" data-zh="<?php echo (int) $img['zh']; ?>">
				<button type="button" class="fisha-river__zoom" aria-label="<?php echo esc_attr( 'Zoom in: ' . $img['alt'] ); ?>">
					<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6"/><path d="M15 15l5 5M10.5 8v5M8 10.5h5"/></svg>
				</button>
				</div>
				<?php if ( $cap && ( ! empty( $cap['title'] ) || ! empty( $cap['text'] ) ) ) : ?>
				<figcaption class="fisha-river__caption">
					<?php if ( ! empty( $cap['title'] ) ) : ?><strong class="fisha-river__caption-title"><?php echo esc_html( $cap['title'] ); ?></strong><?php endif; ?>
					<?php if ( ! empty( $cap['text'] ) ) : ?><span class="fisha-river__caption-text"><?php echo esc_html( $cap['text'] ); ?></span><?php endif; ?>
				</figcaption>
				<?php endif; ?>
			</figure>
			<?php endforeach; ?>
		</div>
		<div class="fisha-river__stream" aria-hidden="true">
			<svg class="fisha-river__water" viewBox="0 0 2400 40" preserveAspectRatio="none"><path class="fisha-river__wave" d="M0 20 C 100 6, 200 34, 300 20 S 500 6, 600 20 S 800 34, 900 20 S 1100 6, 1200 20 S 1400 34, 1500 20 S 1700 6, 1800 20 S 2000 34, 2100 20 S 2300 6, 2400 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" vector-effect="non-scaling-stroke"/></svg>
			<img class="fisha-river__swimmer" src="<?php echo esc_url( FISHA_DESIGN_URL . 'img/fisha-swimmer.png?v=' . fisha_asset_ver( 'img/fisha-swimmer.png' ) ); ?>" alt="" width="200" height="128" draggable="false">
		</div>
		<div class="fisha-river__controls">
			<button type="button" class="fisha-river__btn" data-river="prev" aria-label="Previous artwork"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></button>
			<button type="button" class="fisha-river__btn" data-river="play" aria-label="Pause the river" aria-pressed="false"><svg class="i-pause" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><rect x="7" y="5" width="3.2" height="14" rx="1"/><rect x="13.8" y="5" width="3.2" height="14" rx="1"/></svg><svg class="i-play" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13a.8.8 0 0 0 1.2.7l10.4-6.5a.8.8 0 0 0 0-1.4L9.2 4.8A.8.8 0 0 0 8 5.5z"/></svg></button>
			<button type="button" class="fisha-river__btn" data-river="next" aria-label="Next artwork"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg></button>
		</div>
	</div>
	<?php else : ?>
	<p class="fisha-river__empty">Artwork coming soon.</p>
	<?php endif; ?>

	<?php if ( $m['music'] ) : ?>
	<audio class="fisha-river__audio" src="<?php echo esc_url( $d['url'] . $m['music'] . $v ); ?>" loop preload="none"></audio>
	<button type="button" class="fisha-river__sound is-off" aria-pressed="false" aria-label="Play music">
		<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
			<path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/>
			<path class="w" d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11"/>
			<path class="x" d="M16 9.5l5 5M21 9.5l-5 5"/>
		</svg>
		<span class="fisha-river__sound-label">Music</span>
	</button>
	<?php endif; ?>
</section>
<?php
	return ob_get_clean();
} );
