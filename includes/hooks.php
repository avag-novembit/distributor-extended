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
			add_filter( 'dt_before_setup_cpt', __NAMESPACE__ . '\add_taxonomy_to_cpt_args' );
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
 * @param array $cpt_args
 *
 * @return array
 */
function add_taxonomy_to_cpt_args( $cpt_args ) {

	$cpt_args['taxonomies'] = [ 'dt_ext_connection_group' ];

	return $cpt_args;
}
