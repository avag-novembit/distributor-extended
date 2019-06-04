<?php
/**
 * Check user permissions on connection creating
 *
 * @package Distributor
 */

namespace Distributor\Permissions;

/**
 * Setup actions
 */
function setup() {
	add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );
}

/**
 * Register REST routes
 */
function register_routes() {
	register_rest_route(
		'wp/v2',
		'/distributor/permissions',
		array(
			'methods'  => 'GET',
			'callback' => __NAMESPACE__ . '\check_permissions',
		)
	);
}

/**
 * Check user permissions for provided post types
 */
function check_permissions() {
	$types    = get_post_types(
		array(
			'show_in_rest' => true,
		),
		'objects'
	);
	$response = array(
		'can_get'  => array(),
		'can_post' => array(),
	);
	foreach ( $types as $type ) {
		$caps                  = $type->cap;
		$response['can_get'][] = $type->name;

		if ( current_user_can( $caps->edit_posts ) && current_user_can( $caps->create_posts ) && current_user_can( $caps->publish_posts ) ) {
			$response['can_post'][] = $type->name;
		}
	}
	return $response;
}
