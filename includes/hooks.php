<?php

namespace Distributor\Hooks;

/**
 * Setup actions
 */
function setup() {
	add_action(
		'init',
		function () {
			add_action( 'dt_meta_box_external_connection_details', __NAMESPACE__ . '\add_external_connection_details', 10, 1 );
		}
	);
}

/**
 * Add external connection details
 *
 * @param \WP_Post $post Post object
 */
function add_external_connection_details( $post ) {
	$connection_groups = \Distributor\ExternalConnectionGroups::factory();
	$connection_groups->display_groups( $post->ID );
}
