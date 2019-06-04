<?php
/**
 * Handle "blocks" website specific content distribution
 *
 * @package Distributor
 */

namespace Distributor\Blocks;

/**
 * Setup actions
 */
function setup() {
	add_action(
		'init',
		function () {
			add_filter( 'dt_post_content_shortcode_tags', __NAMESPACE__ . '\handle_shortcode_distribution', 10, 2 );
		}
	);
}

/**
 * Handles website specific shortcode attributes distribution
 *
 * Called for every shortcode in distributed post content
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
 *
 * @return string
 */
function handle_shortcode_distribution( $shortcode, $matches ) {

	// If shortcode has `bg="(\d+)"` attribute, it is something to operate with
	$p = '/bg="(\d+)"/';
	$s = $shortcode;
	preg_match( $p, $s, $m );

	if ( ! empty( $m[1] ) && $m[1] > 0 ) {
		// Get the mapped image id
		$mapped_img_id = \Distributor\Waves\get_image( $m[1] );
		if ( $mapped_img_id > 0 ) {
			// Replace attachment id with the distributed one's id in the shortcode attribute
			$p = '/(.*bg=")(\d+)(".*)/';
			$r = '${1}' . $mapped_img_id . '$3';

			$shortcode = preg_replace( $p, $r, $s );
		}
	}

	return $shortcode;
}
