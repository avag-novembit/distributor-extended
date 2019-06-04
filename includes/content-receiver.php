<?php
/**
 * Handle distributed post specific content saving
 *
 * @package Distributor
 */

namespace Distributor\ContentReceiver;

/**
 * Setup actions
 */
function setup() {
	add_action(
		'init',
		function () {
			add_action( 'dt_process_distributor_attributes', __NAMESPACE__ . '\save_post_specific_content', 10, 2 );
			add_action( 'dt_process_subscription_attributes', __NAMESPACE__ . '\save_post_specific_content', 10, 2 );
		}
	);
}

/**
 * Handle post site specific content
 *
 * @param WP_Post         $post    Inserted or updated post object.
 * @param WP_REST_Request $request Request object.
 */
function save_post_specific_content( $post, $request ) {
	global $shortcode_tags;

	$new_post = get_post( $post->ID );
	$content  = $new_post->post_content;

	// Replace with the website local images
	$content = preg_replace_callback(
		'|<img [^>]+>|',
		__NAMESPACE__ . '\localize_images',
		$content
	);

	// Handle shortcode distribution
	if ( ! empty( $shortcode_tags ) && is_array( $shortcode_tags ) ) {
		// Find all registered tag names in $content.
		preg_match_all( '@\[([^<>&/\[\]\x00-\x20=]++)@', $content, $matches );
		$tagnames = array_intersect( array_keys( $shortcode_tags ), $matches[1] );

		if ( ! empty( $tagnames ) ) {
			$pattern = get_shortcode_regex( $tagnames );

			$content = preg_replace_callback(
				"/$pattern/",
				function ( $matches ) {
					$shortcode = $matches[0];

					/**
					 * Filters the the shortcode tags in the post content
					 *
					 * @param string $shortcode Whole shortcode tag including wrapped content, if there is any
					 * @param array  $matches the array of matches, including {
					 *      @type string $matches[0] Whole shortcode tag including wrapped content, if there is any
					 *      @type string $matches[1] Optional second opening bracket for escaping shortcodes: [[tag]]
					 *      @type string $matches[2] Shortcode name
					 *      @type string $matches[3] Shortcode attributes and their values as a string
					 *      @type string $matches[4] Self closing tag and closing bracket
					 *      @type string $matches[5] Optionally, anything between the opening and closing shortcode tags
					 *      @type string $matches[6] Optional second closing bracket for escaping shortcodes: [[tag]]
					 * }
					 */
					$shortcode = apply_filters( 'dt_post_content_shortcode_tags', $shortcode, $matches );

					return $shortcode;
				},
				$content
			);
		}
	}

	wp_update_post(
		[
			'ID'           => $post->ID,
			'post_content' => $content,
		]
	);
}

/**
 * Reference distributed post images to local attachments
 *
 * @param array $matches
 *
 * @return string
 */
function localize_images( $matches ) {
	// Convert <img ... /> string to array consisiting attributes' names as keys and their values as array of values
	$exploded = preg_split( '/(?<=<img)\s+|(?<=")\s+/', $matches[0] );

	$attrs = array();
	foreach ( $exploded as $value ) {
		$t              = explode( '=', $value );
		$attrs[ $t[0] ] = isset( $t[1] ) ? explode( ' ', $t[1] ) : array();
	}

	/**
	 * Filters the <img> attributes before modifications
	 *
	 * @param array<string,array> $attrs Attributes array
	 */
	$attrs = apply_filters( 'dt_post_attachment_attributes_before_modify', $attrs );

	// Get attachment size and original id
	$orig_img_id = 0;
	$img_size    = '';
	if ( ! empty( $attrs['class'] ) ) {
		foreach ( $attrs['class'] as $c ) {
			preg_match( '/wp-image-(\d+)/', $c, $m );
			if ( ! empty( $m[1] ) ) {
				$orig_img_id = $m[1];
			}

			preg_match( '/size-(\w+)/', $c, $m2 );
			if ( ! empty( $m2 ) ) {
				$img_size = $m2[1];
			}
		}
	}

	if ( 0 === $orig_img_id ) {
		return $matches[0];
	}

	// Get the mapped image id
	$mapped_img_id = \Distributor\Waves\get_image( $orig_img_id );

	if ( empty( $mapped_img_id ) ) {
		return $matches[0];
	}

	if ( '' === $img_size ) {
		$img_src = wp_get_attachment_url( $mapped_img_id );
	} else {
		$img_src = wp_get_attachment_image_url( $mapped_img_id, $img_size );
	}

	// Replace attachment url
	if ( ! empty( $attrs['src'] ) ) {
		$attrs['src'][0] = $img_src;
	}

	// Replace image id with the appropriate one in the class
	foreach ( $attrs['class'] as &$v ) {
		if ( strpos( $v, 'wp-image-' ) !== false ) {
			$v = 'wp-image-' . $mapped_img_id;
		}
	}
	unset( $v ); // break the reference with the last element

	/**
	 * Filters the <img> attributes after implemented modifications
	 *
	 * @param array<string,array> $attrs Attributes array
	 */
	$attrs = apply_filters( 'dt_post_attachment_attributes_after_modify', $attrs );

	// Re-assemble the <img ... /> tag
	$img_tag = '';
	foreach ( $attrs as $attr_name => $attr_values ) {
		$img_tag .= $attr_name;
		if ( ! empty( $attr_values ) ) {
			$img_tag .= '="';
			foreach ( $attr_values as $attr_value ) {
				$img_tag .= trim( $attr_value, '"' ) . ' ';
			}
			$img_tag  = rtrim( $img_tag );
			$img_tag .= '"';
		}

		$img_tag .= ' ';
	}

	return rtrim( $img_tag );
}
