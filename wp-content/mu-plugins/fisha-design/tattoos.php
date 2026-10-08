<?php
/**
 * Fisha Tattood — tattoo gallery page.
 * Usage in a page:  [fisha_tattoos]
 * Media: wp-content/uploads/fisha-tattoos/manifest.json (built from /tattoos by tools/build-tattoos.py).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function fisha_is_tattoos_page() {
	if ( ! is_singular() ) return false;
	$post = get_post();
	return $post && has_shortcode( $post->post_content, 'fisha_tattoos' );
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! fisha_is_tattoos_page() ) return;
	wp_enqueue_style( 'fisha-tattoos', FISHA_DESIGN_URL . 'tattoos.css', array( 'fisha-design' ), fisha_asset_ver( 'tattoos.css' ) );
	wp_enqueue_script( 'fisha-tattoos', FISHA_DESIGN_URL . 'tattoos.js', array(), fisha_asset_ver( 'tattoos.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
} );

add_filter( 'body_class', function ( $c ) {
	if ( fisha_is_tattoos_page() ) $c[] = 'fisha-tattoos-page';
	return $c;
} );

// The page hero carries its own H1, so drop the theme's page-title block on this page (one H1 only).
add_filter( 'render_block_core/post-title', function ( $html, $block, $instance = null ) {
	if ( ! fisha_is_tattoos_page() ) return $html;
	$pid = ( $instance && isset( $instance->context['postId'] ) ) ? (int) $instance->context['postId'] : get_the_ID();
	return $pid === get_queried_object_id() ? '' : $html;
}, 10, 3 );

function fisha_tattoos_alts() {
	return array(
		'img-7232' => 'Fisha fish and lotus sleeve tattoo on an upper arm, side view',
		'img-7007' => 'Fine-line Fisha fish tattoo on a calf',
		'img-8512' => 'Colour forearm sleeve tattoo with a trout, a string of pearls and a cat skull',
		'img-7018' => 'Two tiny fine-line fish tattoos on an upper arm',
		'img-7011' => 'Fresh fine-line Fisha fish tattoo on a leg',
		'img-1755' => 'Two Fisha fish swimming in a circle, fine-line calf tattoo',
		'img-7239' => 'Close-up of a lotus and Fisha fish shoulder tattoo',
		'img-3004' => 'Small Fisha fish tattoo on an upper arm beside a sky painting',
		'img-8514' => 'Close-up of a dotwork trout tattoo',
		'img-7242' => 'Full-arm Fisha sleeve tattoo with lotus flowers and red blossoms',
		'img-7244' => 'Fisha sleeve tattoo with lotus flowers, fish and red blossoms, full arm view',
		'img-7237' => 'Fisha sleeve tattoo with lotus flowers, fish and red blossoms',
	);
}

add_shortcode( 'fisha_tattoos', function ( $atts ) {
	$a = shortcode_atts( array(
		'title'      => 'Fisha Tattood',
		'eyebrow'    => 'Fisha on skin',
		'text'       => 'Fisha has swum off the paper and onto skin. Tiny fine-line fish, playful pairs and full sleeves of lotus, river and colour — every tattoo begins as a drawing in the sketchbook and becomes a little story you carry with you.',
		'hero_video' => 'img-7237',
		'order'      => 'img-7232,img-7007,img-8512,img-7018,img-7011,img-1755,img-7239,img-3004,img-8514,img-7242,img-7244',
		'cta'        => 'Request a Fisha tattoo',
		'url'        => '/request/?type=tattoo',
	), $atts, 'fisha_tattoos' );

	$d = fisha_river_dir( 'fisha-tattoos' );
	$f = $d['path'] . 'manifest.json';
	if ( ! file_exists( $f ) ) return '';
	$m    = json_decode( file_get_contents( $f ), true );
	$v    = '?v=' . filemtime( $f );
	$alts = fisha_tattoos_alts();
	$all  = array();
	foreach ( array_merge( $m['images'], $m['videos'] ) as $it ) $all[ $it['slug'] ] = $it;

	$hero = $all[ $a['hero_video'] ] ?? null;
	$order = array_filter( array_map( 'trim', explode( ',', $a['order'] ) ) );
	foreach ( $all as $slug => $it ) if ( ! in_array( $slug, $order, true ) && $slug !== $a['hero_video'] ) $order[] = $slug; // new files land at the end
	$items = array();
	foreach ( $order as $slug ) if ( isset( $all[ $slug ] ) ) $items[] = $all[ $slug ];

	$u   = function ( $file ) use ( $d, $v ) { return esc_url( $d['url'] . $file . $v ); };
	$alt = function ( $slug ) use ( $alts ) { return $alts[ $slug ] ?? 'Fisha tattoo'; };
	$words = array( 'fine line', 'blackwork', 'colour sleeves', 'tiny fish', 'big stories', 'drawn by hand' );

	// Structured data: an ImageGallery with the photos + VideoObjects
	$ld = array( '@context' => 'https://schema.org', '@type' => 'ImageGallery', 'name' => $a['title'], 'description' => $a['text'], 'url' => get_permalink(), 'associatedMedia' => array() );
	foreach ( array_merge( $items, $hero ? array( $hero ) : array() ) as $it ) {
		if ( 'video' === $it['type'] ) {
			$ld['associatedMedia'][] = array( '@type' => 'VideoObject', 'name' => $alt( $it['slug'] ), 'description' => $alt( $it['slug'] ), 'contentUrl' => $d['url'] . $it['src'], 'thumbnailUrl' => $d['url'] . $it['poster'], 'uploadDate' => gmdate( 'c', filemtime( $d['path'] . $it['src'] ) ) );
		} else {
			$ld['associatedMedia'][] = array( '@type' => 'ImageObject', 'contentUrl' => $d['url'] . $it['full'], 'caption' => $alt( $it['slug'] ) );
		}
	}
	ob_start(); ?>
<div class="fisha-tattoos">
	<section class="ft-hero" aria-labelledby="ft-title">
		<div class="ft-hero__text">
			<p class="ft-eyebrow"><?php echo esc_html( $a['eyebrow'] ); ?></p>
			<h1 id="ft-title" class="ft-title"><?php
				$parts = explode( ' ', $a['title'], 2 );
				echo esc_html( $parts[0] ) . ( isset( $parts[1] ) ? ' <span>' . esc_html( $parts[1] ) . '</span>' : '' );
			?></h1>
			<p class="ft-lead"><?php echo esc_html( $a['text'] ); ?></p>
			<ul class="ft-tags" aria-label="Styles">
				<li>Fine line</li><li>Blackwork</li><li>Colour</li>
			</ul>
			<div class="fisha-cta-wrap" style="text-align:left"><a class="fisha-cta" href="<?php echo esc_url( home_url( $a['url'] ) ); ?>"><?php echo esc_html( $a['cta'] ); ?> <span aria-hidden="true">→</span></a></div>
		</div>
		<?php if ( $hero ) : ?>
		<div class="ft-hero__media">
			<div class="ft-arch">
				<video class="ft-hero__video" src="<?php echo $u( $hero['src'] ); ?>" poster="<?php echo $u( $hero['poster'] ); ?>" width="<?php echo (int) $hero['w']; ?>" height="<?php echo (int) $hero['h']; ?>" autoplay muted loop playsinline preload="auto" aria-label="<?php echo esc_attr( $alt( $hero['slug'] ) ); ?>"></video>
				<button type="button" class="ft-sound" aria-pressed="false" aria-label="Turn sound on">
					<svg class="ft-sound__off" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9v6h4l5 4V5L8 9H4z" fill="currentColor"/><path d="M17 9l4 6M21 9l-4 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>
					<svg class="ft-sound__on" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9v6h4l5 4V5L8 9H4z" fill="currentColor"/><path d="M16.5 8.5a5 5 0 0 1 0 7M19 6a8.5 8.5 0 0 1 0 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>
				</button>
			</div>
			<svg class="ft-badge" viewBox="0 0 120 120" aria-hidden="true">
				<defs><path id="ft-circle" d="M60,60 m-44,0 a44,44 0 1,1 88,0 a44,44 0 1,1 -88,0"/></defs>
				<circle cx="60" cy="60" r="58" />
				<text textLength="272" lengthAdjust="spacingAndGlyphs"><textPath href="#ft-circle" textLength="272" lengthAdjust="spacingAndGlyphs">FISHA · TATTOOD · INK · FISHA · TATTOOD · INK · </textPath></text>
				<image href="<?php echo esc_url( FISHA_DESIGN_URL . 'img/fisha-logo.png' ); ?>" x="34" y="40" width="52" height="40" />
			</svg>
		</div>
		<?php endif; ?>
	</section>

	<div class="ft-marquee" aria-hidden="true"><div class="ft-marquee__track">
		<?php for ( $k = 0; $k < 4; $k++ ) foreach ( $words as $w ) echo '<span>' . esc_html( $w ) . '</span><i>✦</i>'; ?>
	</div></div>

	<section class="ft-sheet" aria-labelledby="ft-sheet-title">
		<header class="ft-sheet__head">
			<h2 id="ft-sheet-title">The Flash Sheet</h2>
			<p>Fresh lines, healed pieces and the sleeves that grew from a single fish. Tap any tattoo to see it up close.</p>
		</header>
		<div class="ft-grid">
			<?php foreach ( $items as $i => $it ) :
				$is_video = 'video' === $it['type'];
				$num = str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT );
			?>
			<figure class="ft-card<?php echo $is_video ? ' ft-card--video' : ''; ?>" style="--d:<?php echo ( $i % 3 ) * 90; ?>ms">
				<button type="button" class="ft-card__open" data-index="<?php echo (int) $i; ?>"
					data-type="<?php echo esc_attr( $it['type'] ); ?>"
					data-src="<?php echo $is_video ? $u( $it['src'] ) : $u( $it['full'] ); ?>"
					<?php if ( $is_video ) echo 'data-poster="' . $u( $it['poster'] ) . '"'; ?>
					data-alt="<?php echo esc_attr( $alt( $it['slug'] ) ); ?>"
					aria-label="<?php echo esc_attr( ( $is_video ? 'Play video: ' : 'View larger: ' ) . $alt( $it['slug'] ) ); ?>">
					<?php if ( $is_video ) : ?>
					<video class="ft-card__media" src="<?php echo $u( $it['src'] ); ?>" poster="<?php echo $u( $it['poster'] ); ?>" width="<?php echo (int) $it['w']; ?>" height="<?php echo (int) $it['h']; ?>" muted loop playsinline preload="metadata" aria-hidden="true"></video>
					<span class="ft-card__play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z" fill="currentColor"/></svg></span>
					<?php else : ?>
					<img class="ft-card__media" src="<?php echo $u( $it['tile'] ); ?>" width="<?php echo (int) $it['w']; ?>" height="<?php echo (int) $it['h']; ?>" alt="<?php echo esc_attr( $alt( $it['slug'] ) ); ?>" loading="<?php echo $i < 3 ? 'eager' : 'lazy'; ?>" decoding="async">
					<?php endif; ?>
					<span class="ft-card__no" aria-hidden="true">Nº <?php echo esc_html( $num ); ?></span>
				</button>
			</figure>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="ft-outro" aria-labelledby="ft-outro-title">
		<h2 id="ft-outro-title">Want a Fisha of your own?</h2>
		<p>Every Fisha tattoo is drawn for the person wearing it. Send a message with the idea, the spot and the size — and let’s draw it together.</p>
		<?php echo do_shortcode( '[fisha_cta text="' . esc_attr( $a['cta'] ) . '" url="' . esc_attr( $a['url'] ) . '"]' ); ?>
	</section>

	<div class="ft-lightbox" role="dialog" aria-modal="true" aria-label="Tattoo viewer" hidden>
		<button type="button" class="ft-lb__close" aria-label="Close">×</button>
		<button type="button" class="ft-lb__nav ft-lb__prev" aria-label="Previous">‹</button>
		<div class="ft-lb__stage"></div>
		<button type="button" class="ft-lb__nav ft-lb__next" aria-label="Next">›</button>
		<p class="ft-lb__caption"><span class="ft-lb__count"></span> <span class="ft-lb__alt"></span></p>
	</div>
	<script type="application/ld+json"><?php echo wp_json_encode( $ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
</div>
<?php
	return ob_get_clean();
} );
