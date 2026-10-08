<?php
/**
 * Fisha — SEO fallbacks.
 *
 * Fills in what Lighthouse's SEO audit wants when the page has nothing of its
 * own: a meta description, a descriptive home title and basic Open Graph tags.
 * Anything set in the editor (Hostinger AI SEO fields, a category's lead
 * text, a product's short description) always wins. Skipped entirely when a
 * real SEO plugin is active.
 */

defined( 'ABSPATH' ) || exit;

function fisha_seo_plugin_active() {
	return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
}

define( 'FISHA_HOME_TITLE', 'Fisha — Hand-Drawn Fish Art, Wearables & Collectibles from Tel Aviv' );

/** Hand-written descriptions for pages and categories that have none. */
function fisha_seo_known() {
	return apply_filters( 'fisha_seo_descriptions', array(
		'page:river'             => 'The Fisha River: hundreds of sketchbook fish drawn by hand, swimming together in one stream. Tap any sketch to see it up close.',
		'page:fisha-foundation'  => 'The Fisha Foundation: the story behind the little fish, why it swims, and the good it carries with it.',
		'page:tattoos'           => 'Fisha Tattood: people who carry the little fish on their skin. See the tattoos and find out how to get your own Fisha.',
		'page:my-account-2'      => 'Sign in to your Fisha account to see your orders, addresses and details.',
		'product_cat:collectibles' => 'Fisha collectibles: one-of-a-kind hand-painted Clipper lighters and small objects carrying the hand-drawn fish.',
		'product_cat:clippers'   => 'Hand-painted Fisha Clipper lighters. Every one is a single piece, painted by hand with the little fish.',
		'product_cat:originals'  => 'Original Fisha artworks, painted and drawn by hand. Each piece is one of a kind. See what is available and the happy clients who took one home.',
	) );
}

/** The description this request should carry, or '' to print nothing. */
function fisha_seo_description() {
	$known = fisha_seo_known();

	if ( is_singular() ) {
		$id = get_queried_object_id();
		if ( get_post_meta( $id, 'hostinger_ai_post_meta_description', true ) ) {
			return ''; // the theme prints it
		}
		$post = get_post( $id );
		$key  = $post->post_type . ':' . $post->post_name;
		if ( isset( $known[ $key ] ) ) {
			return $known[ $key ];
		}
		$text = $post->post_excerpt;
		if ( ! $text && 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$p    = wc_get_product( $id );
			$text = $p ? ( $p->get_short_description() ?: $p->get_description() ) : '';
			if ( ! trim( wp_strip_all_tags( $text ) ) && $p ) {
				$cats = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
				$text = sprintf( '%s by Fisha%s. Hand-drawn in Tel Aviv, shipped with care.', $p->get_name(), $cats ? ' — ' . implode( ', ', $cats ) : '' );
			}
		}
		if ( ! $text ) {
			$text = strip_shortcodes( $post->post_content );
		}
		return fisha_seo_trim( $text );
	}

	if ( is_tax( 'product_cat' ) ) {
		$t = get_queried_object();
		if ( function_exists( 'fisha_is_shop_cat' ) && fisha_is_shop_cat() && ( get_term_meta( $t->term_id, 'fisha_lead', true ) || term_description( $t ) ) ) {
			return ''; // shop.php prints the category's own text
		}
		$key = 'product_cat:' . $t->slug;
		return isset( $known[ $key ] ) ? $known[ $key ] : fisha_seo_trim( $t->description ?: $t->name . ' by Fisha — hand-drawn in Tel Aviv.' );
	}

	return '';
}

function fisha_seo_trim( $text ) {
	$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
	if ( mb_strlen( $text ) > 158 ) {
		$text = rtrim( mb_substr( $text, 0, 155 ), " ,.;:-" ) . '…';
	}
	return $text;
}

add_action( 'wp_head', function () {
	if ( fisha_seo_plugin_active() || is_admin() ) {
		return;
	}
	// The shop home and filled-in categories are handled in shop.php.
	if ( function_exists( 'fisha_is_shop_home' ) && fisha_is_shop_home() ) {
		return;
	}
	$desc = fisha_seo_description();
	if ( ! $desc ) {
		return;
	}
	echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( wp_get_document_title() ) . '">' . "\n";
	if ( is_singular() && has_post_thumbnail() ) {
		echo '<meta property="og:image" content="' . esc_url( get_the_post_thumbnail_url( null, 'large' ) ) . '">' . "\n";
	}
}, 3 );

// Front page: "Fisha" alone tells search engines nothing.
add_filter( 'pre_get_document_title', function ( $title ) {
	if ( fisha_seo_plugin_active() || ! is_front_page() ) {
		return $title;
	}
	// The theme stores an AI "SEO title" but never prints it, so WordPress falls
	// back to the bare site name. Use it only if someone wrote a real one.
	$custom = trim( (string) get_post_meta( get_queried_object_id(), 'hostinger_ai_post_meta_title', true ) );
	return ( $custom && mb_strlen( $custom ) > 20 ) ? $custom : FISHA_HOME_TITLE;
}, 25 );

add_action( 'wp_head', function () {
	if ( fisha_seo_plugin_active() || ! is_front_page() ) {
		return;
	}
	echo '<meta property="og:type" content="website">' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( home_url( '/' ) ) . '">' . "\n";
}, 3 );
