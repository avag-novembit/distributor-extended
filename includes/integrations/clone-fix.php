<?php
/**
 * Handle media fields in post content
 *
 * @package Distributor
 */

namespace Distributor\CloneFix;

/**
 * Setup actions
 */
function setup() {
	add_action(
		'init',
		function() {
			add_action( 'dt_repair_posts_hook', __NAMESPACE__ . '\push_post_data' );

		}
	);
	add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );
}


/**
 * Register REST routes
 */
function register_routes() {
	register_rest_route(
		'wp/v2',
		'/distributor/repair-clon',
		array(
			'methods'  => 'POST',

			'callback' => __NAMESPACE__ . '\repair_posts',
		)
	);
}

/**
 * Try to repair posts
 *
 * @param array $data Array of post data
 */
function repair_posts( $data ) {
	$posts    = $data->get_params();
	$response = array();
	foreach ( $posts as $post_id ) {
		$spoke_id  = \Distributor\Waves\get_post_from_original_id( $post_id );
		$signature = \Distributor\Subscriptions\generate_signature();
		update_post_meta( $spoke_id, 'dt_subscription_signature', $signature );
		$response[ $post_id ] = array(
			'remote_id' => $spoke_id,
			'signature' => $signature,
		);
	}
	return $response;
}

/**
 * Push post data to spoke
 */
function push_post_data() {
	global $wpdb;

	$query = "
		SELECT `post_id` 
			FROM $wpdb->postmeta AS `postmeta`
		INNER JOIN $wpdb->posts AS `posts`
			ON `postmeta`.`post_id`=`posts`.`ID` 
			AND `posts`.`post_status` IN ( 'publish','draft','trash' ) 
		WHERE `postmeta`.`meta_key`=%s
		LIMIT 20
	  ";
	$posts = $wpdb->get_col( $wpdb->prepare( $query, 'dt_repair_post' ) );

	$hosts = array();
	foreach ( $posts as $post_id ) {
		$connection_id                      = get_post_meta( $post_id, 'dt_repair_post', true );
		$host                               = get_post_meta( $connection_id, 'dt_external_connection_url', true );
		$hosts[ $connection_id ]['host']    = untrailingslashit( $host );
		$hosts[ $connection_id ]['posts'][] = $post_id;
	}

	$external_connection_class = \Distributor\Connections::factory()->get_registered( 'external' )['wp'];
	foreach ( $hosts as $connection_id => $host ) {
		$url                      = $host['host'] . '/wp/v2/distributor/repair-clon';
		$external_connection_auth = get_post_meta( $connection_id, 'dt_external_connection_auth', true );
		$auth_handler             = new $external_connection_class::$auth_handler_class( $external_connection_auth );

		$response = wp_remote_post(
			$url,
			$auth_handler->format_post_args(
				array(

					'timeout' => 60,

					'body'    => $host['posts'],
				)
			)
		);
		if ( ! is_wp_error( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			foreach ( $data as $post_id => $items ) {
				$subscription_id = \Distributor\Subscriptions\create_subscription( $post_id, $items['remote_id'], $host['host'], $items['signature'] );
				if ( ! is_wp_error( $subscription_id ) && ! empty( $subscription_id ) ) {
					$connection_map = get_post_meta( $post_id, 'dt_connection_map', true );
					if ( empty( $connection_map ) || empty( $connection_map['external'] ) ) {
						$connection_map = array( 'external' => array() );
					}
					if ( ! in_array( $connection_id, array_keys( $connection_map['external'] ), true ) ) {
						$connection_map['external'][ $connection_id ] = array(
							'post_id' => $items['remote_id'],
							'time'    => time(),
						);
					}
					update_post_meta( $post_id, 'dt_connection_map', $connection_map );
					delete_post_meta( $post_id, 'dt_repair_post', $connection_id );
				}
			}
		}
	}
}
