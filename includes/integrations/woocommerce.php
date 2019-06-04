<?php
/**
 * Handle variable products pushing
 *
 * @package Distributor
 */

namespace Distributor\Woocommerce;

/**
 * Setup actions
 */
function setup() {
	add_action(
		'init',
		function () {
			add_action( 'woocommerce_rest_insert_product_variation_object', __NAMESPACE__ . '\handle_variations_redistribute', 10, 3 );
			add_filter( 'rest_pre_insert_product', __NAMESPACE__ . '\check_post_before_inserting', 1, 2 );

			add_action( 'dt_process_distributor_attributes', __NAMESPACE__ . '\set_variations', 10, 3 );
			add_action( 'dt_process_distributor_attributes', __NAMESPACE__ . '\hook_distributor_attributes_update_metas', 20, 3 );

			add_filter( 'dt_push_post_args', __NAMESPACE__ . '\push_variations', 10, 2 );
			add_filter( 'dt_subscription_post_args', __NAMESPACE__ . '\push_variations', 10, 2 );

			add_action( 'dt_process_subscription_attributes', __NAMESPACE__ . '\set_variations_update', 10, 2 );
			add_action( 'dt_process_subscription_attributes', __NAMESPACE__ . '\hook_distribution_update_update_metas', 20, 2 );

			add_action( 'dt_post_term_hierarchy_saved', __NAMESPACE__ . '\replace_primary_cat', 10, 2 );

			add_filter( 'dt_blacklisted_meta', __NAMESPACE__ . '\blacklist_keys', 10, 1 );
		}
	);
}




/**
 * Add metas to product variations
 *
 * @param array  $post_body Array of pushing post body.
 * @param object $post WP Post object.
 */
function push_variations( $post_body, $post ) {
	if ( 'product' === $post->post_type ) {
		$_product = wc_get_product( $post->ID );
		if ( $_product && null !== $_product && ! is_wp_error( $_product ) ) {
			if ( $_product->is_type( 'variable' ) ) {
				$variations = $_product->get_children();
				$result     = [];
				if ( ! empty( $variations ) ) {
					foreach ( $variations as $child ) {
						$item        = array();
						$var         = new \WC_Product_Variation( $child );
						$item['sku'] = $var->get_sku();
						if ( ! empty( $item['sku'] ) ) {
							$item['id']               = (int) $var->get_id();
							$item['meta']             = \Distributor\Utils\prepare_meta( $var->get_id() );
							$item['active']           = $var->variation_is_active();
							$result[ $var->get_id() ] = $item;
						}
					}
				}
			}
			if ( ! empty( $result ) ) {
				$post_body['distributor_product_variations'] = $result;

			}
		}
	}

	return $post_body;
}



/**
 * Set product variations
 *
 * @param WP_Post         $post    Inserted or updated post object.
 * @param WP_REST_Request $request Request object.
 * @param bool            $update  True when creating a post, false when updating.
 */
function set_variations( $post, $request, $update ) {
	if ( isset( $request['distributor_product_variations'] ) ) {
		$variations = $request['distributor_product_variations'];

		$product = wc_get_product( $post->ID );
		foreach ( $variations as $variation ) {
			create_variation( $variation, $product );
		}
	}
}

/**
 * Prepare variations update
 *
 * @param array $variations Existing variations ids array.
 * @param array $remote_variations Incoming variations update.
 */
function prepare_variations_update( $variations, $remote_variations ) {
	foreach ( $variations as $var_id ) {
		$origin_id = (int) get_post_meta( $var_id, 'dt_original_post_id', true );
		if ( ! empty( $origin_id ) ) {
			continue;
		}
		$variation = wc_get_product( $var_id );
		$sku       = $variation->get_sku();
		if ( empty( $sku ) ) {
			$variation->delete();
			continue;
		}

		foreach ( $remote_variations as $remote_variation ) {
			if ( $sku === $remote_variation['sku'] ) {
				update_post_meta( $var_id, 'dt_original_post_id', $remote_variation['id'] );
				$origin_id = $remote_variation['id'];
				break;
			}
		}
		if ( empty( $origin_id ) ) {
			$variation->delete();
			continue;
		}
	}
}


/**
 * Set product variations update
 *
 * @param WP_Post $post WP Post object.
 * @param array   $request    Request array.
 */
function set_variations_update( $post, $request ) {
	if ( isset( $request['distributor_product_variations'] ) && ! empty( $request['distributor_product_variations'] ) ) {
		$product           = wc_get_product( $post->ID );
		$remote_variations = $request['distributor_product_variations'];

		prepare_variations_update( $product->get_children(), $remote_variations );

		$existing_vars = $product->get_children();

		$result_vars = array();
		foreach ( $existing_vars as $var_id ) {
			$var       = wc_get_product( $var_id );
			$origin_id = get_post_meta( $var_id, 'dt_original_post_id', true );

			if ( empty( $origin_id ) || ! in_array( $origin_id, array_keys( $remote_variations ) ) ) { //phpcs:ignore
				$var->delete();
				continue;
			} else {
				$result_vars[ $origin_id ] = $var;
			}
		}
		foreach ( $remote_variations as $variation ) {
			if ( in_array( $variation['id'], array_keys( $result_vars ) ) ) { //phpcs:ignore
				update_variation( $variation, $result_vars[ $variation['id'] ] );
			} else {
				create_variation( $variation, $product );
			}
		}
	}
}

/**
 * Update existing variation
 *
 * @param array  $item Array of update data.
 * @param object $var Currently updating variation.
 */
function update_variation( $item, $var ) {
	$sku = $var->get_sku();
	if ( $sku != $item['sku'] ) { //phpcs:ignore
		try {
			$var->set_sku( $item['sku'] );
		} catch ( \WC_Data_Exception $e ) {
			$var->delete();
			return;
		}
	}
	\Distributor\Utils\set_meta( $var->get_id(), $item['meta'] );

	/* if there is any re-indexing that needs to happen*/
	$var->save();

	$status = $item['active'] ? 'publish' : 'private';
	wp_update_post(
		array(
			'ID'          => $var->get_id(),
			'post_status' => $status,
		)
	);
}


/**
 * Create new variation for product
 *
 * @param array  $variation Array of variation data.
 * @param object $product Product to add variation.
 */
function create_variation( $variation, $product ) {
	if ( ! empty( $variation['sku'] ) ) {
		$status         = $variation['active'] ? 'publish' : 'private';
		$variation_post = array(
			'post_title'  => $product->get_title(),
			'post_name'   => 'product-' . $product->get_id() . '-variation',
			'post_status' => $status,
			'post_parent' => $product->get_id(),
			'post_type'   => 'product_variation',
			'guid'        => $product->get_permalink(),
		);
		$variation_id   = wp_insert_post( $variation_post );
		$var            = new \WC_Product_Variation( $variation_id );

		try {
			$var->set_sku( $variation['sku'] );
		} catch ( \WC_Data_Exception $e ) {
			$var->delete();
			return;
		}

		$var->save();
		\Distributor\Utils\set_meta( $var->get_id(), $variation['meta'] );
		update_post_meta( $var->get_id(), 'dt_original_post_id', $variation['id'] );
	}
}


/**
 * Replace primary category term id with new one
 *
 * @param int   $post_id Post ID.
 * @param array $map The taxonomy term id mapping.
 */
function replace_primary_cat( $post_id, $map ) {
	$origin_id = get_post_meta( $post_id, '_primary_term_product_cat', true );
	if ( ! empty( $origin_id ) ) {
		$new_id = $origin_id;
		if ( isset( $map['product_cat'][ $origin_id ] ) ) {
			$new_id = $map['product_cat'][ $origin_id ];
		}
		update_post_meta( $post_id, '_primary_term_product_cat', $new_id );
	}
}


/**
 * Update special metas
 *
 * @param int $post_id    Post ID.
 */
function update_metas( $post_id ) {

	// 'product' post type updates
	if ( get_post_type( $post_id ) == 'product' ) { //phpcs:ignore
		/* _advanced_pricing can contain variation ID */
		$advanced_pricing = (array) get_post_meta( $post_id, '_advanced_pricing', true );
		foreach ( $advanced_pricing as &$pricing ) {
			if ( isset( $pricing['condition']['variation'] ) ) {
				global $wpdb;
				$source_id = $pricing['condition']['variation'];
				$mapped_id = $wpdb->get_var( "SELECT post.ID FROM $wpdb->posts AS post INNER JOIN $wpdb->postmeta AS meta ON post.ID = meta.post_id WHERE post.post_status != 'trash'  AND meta.meta_key = 'dt_original_post_id' AND meta.meta_value ='$source_id'" ); //phpcs:ignore
				if ( $mapped_id ) {
					$pricing['condition']['variation'] = $mapped_id;
				}
			}
		}
		update_post_meta( $post_id, '_advanced_pricing', $advanced_pricing );
	}
}


/**
 * Update special metas right after creating new post.
 *
 * @param WP_Post         $post    Inserted or updated post object.
 * @param WP_REST_Request $request Request object.
 * @param bool            $update  True when creating a post, false when updating.
 */
function hook_distributor_attributes_update_metas( $post, $request, $update ) {
	update_metas( $post->ID );
}


/**
 * Update special metas after post updates.
 *
 * @param WP_Post $post WP Post object.
 * @param array   $request    Request array.
 */
function hook_distribution_update_update_metas( $post, $request ) {
	update_metas( $post->ID );
}


/**
 * Check post to avoid duplicates
 *
 * @param stdClass        $post An object representing a single post .
 * @param WP_REST_Request $request       Request object.
 *
 * @return stdClass|WP_Error
 */
function check_post_before_inserting( $post, $request ) {
	$sku = $request['distributor_meta']['_sku'][0];
	if ( ! isset( $sku ) || empty( $sku ) ) {
		return new \WP_Error( 'dt_duplicated_post', 'Product SKU can\'t be empty' );
	}
	$existing = wc_get_product_id_by_sku( $sku );
	if ( ! empty( $existing ) ) {
		return new \WP_Error( 'dt_duplicated_post', 'Post already exist, please update it instead' );
	}
	return $post;
}

/**
 * Catch variation update via REST event and schedule redistribution for it's parent
 *
 * @param WC_Product_Variation $variation Product Variation object.
 * @param array                $request Request array.
 * @param bool                 $create Is request for creating variation.
 */
function handle_variations_redistribute( $variation, $request, $create ) {
	if ( ! $create ) {
		\Distributor\Waves\schedule_redistribution( $variation->get_parent_id() );
	}
}

/**
 * Add keys to blacklisted keys array
 *
 * @param array $blacklisted Array of blacklisted keys.
 * @return array
 */
function blacklist_keys( $blacklisted ) {
	return array_merge(
		$blacklisted,
		array(
			'_wc_average_rating',
			'_wc_rating_count',
			'_wc_review_count',
		)
	);
}
