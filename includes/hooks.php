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
			add_filter( 'register_post_type_args', __NAMESPACE__ . '\add_taxonomy_to_cpt_args', 10, 2 );
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

/**
 * Add taxonomy to the connection custom post type arguments
 *
 * @param array $args
 * @param string $post_type
 *
 * @return array
 */
function add_taxonomy_to_cpt_args( $args, $post_type ) {

	if ( $post_type == 'dt_ext_connection' ) {
		$cpt_args['taxonomies'] = [ 'dt_ext_connection_group' ];
	}

	return $args;
}
