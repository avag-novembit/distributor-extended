<?php
/**
 * Handle media fields in post content
 *
 * @package Distributor
 */

namespace Distributor\Waves;

/**
 * Setup actions
 */
function setup() {
	add_action(
		'plugins_loaded',
		function () {
			/* Force-distribute custom types */
			add_filter( 'register_post_type_args', __NAMESPACE__ . '\show_post_type_in_rest', 10, 2 );
			add_filter( 'distributable_post_types', __NAMESPACE__ . '\distributable_custom_post_types', 10, 1 );
		}
	);

	add_action(
		'init',
		function () {

			remove_action( 'save_post', 'Distributor\Subscriptions\send_notifications' );
			add_action( 'save_post', __NAMESPACE__ . '\schedule_send_notifications' );
			add_action( 'dt_process_distributor_attributes', __NAMESPACE__ . '\set_post_data', 10, 2 );
			add_action( 'rest_api_init', __NAMESPACE__ . '\register_redistribute_route' );
			add_action( 'wp_ajax_dt_redistribute_post', __NAMESPACE__ . '\ajax_redistribute' );
			add_action( 'dt_process_subscription_attributes', __NAMESPACE__ . '\set_post_data', 10, 2 );
			add_action( 'dt_remove_subscription_data', __NAMESPACE__ . '\delete_subscription_data', 10, 2 );
			add_action( 'dt_add_to_menu_content', __NAMESPACE__ . '\render_redistribute_button', 10, 2 );
			add_filter( 'dt_push_post_args', __NAMESPACE__ . '\push_post_data', 10, 2 );
			add_filter( 'dt_distributable_post_statuses', __NAMESPACE__ . '\distributable_post_statuses', 10, 1 );
			add_filter( 'dt_allowed_media_extensions', __NAMESPACE__ . '\add_media_extensions', 10, 3 );
			add_filter( 'dt_subscription_post_args', __NAMESPACE__ . '\push_post_data', 10, 2 );
			add_filter( 'http_request_timeout', __NAMESPACE__ . '\increase_rest_timeout', 10, 1 );
			add_filter( 'dt_sync_media_delete_and_replace', __NAMESPACE__ . '\keep_media_on_update', 10, 2 );
			add_filter( 'dt_blacklisted_meta', __NAMESPACE__ . '\blacklist_groups_keys', 10, 1 );
			add_filter( 'dt_syncable_taxonomies', __NAMESPACE__ . '\add_mq_taxonomies', 10, 2 );

			add_action( 'dt_redistribute_posts_hook', __NAMESPACE__ . '\redistribute_posts' );

			add_filter( 'dt_post_content_shortcode_tags', __NAMESPACE__ . '\localize_theme_blocks', 10, 2 );
			setup_bulk_redistribute();
		}
	);
}

/**
 * Change default distributable statuses list
 *
 * @param array $statuses Statuses array.
 * @return array
 */
function distributable_post_statuses( $statuses ) {
	if ( ! in_array( 'draft', $statuses, true ) ) {
		$statuses[] = 'draft';
	}
	return $statuses;
}
/**
 * Add keys to blacklisted keys array
 *
 * @param array $blacklisted Array of blacklisted keys.
 * @return array
 */
function blacklist_groups_keys( $blacklisted ) {
	return array_merge(
		$blacklisted,
		array(
			'dt_connection_groups',
			'dt_connection_groups_pushing',
			'dt_connection_groups_pushed',
		)
	);
}

/**
 * Push post data to remote
 *
 * @param array   $post_body Pushing post body.
 * @param WP_POST $post Post object.
 */
function push_post_data( $post_body, $post ) {
	$author_id                    = $post->post_author;
	$posted                       = get_the_date( 'c', $post->ID );
	$post_body['original_posted'] = $posted;
	$post_body['post_status']     = get_post_status( $post->ID );
	$post_body['remote_author']   = get_user_by( 'id', $author_id )->user_login;
	return $post_body;
}

/**
 * Set post data on push and update
 *
 * @param WP_POST $post Post object.
 * @param array   $request Request array.
 */
function set_post_data( $post, $request ) {
	$args = array( 'ID' => $post->ID );
	if ( isset( $request['remote_author'] ) ) {
		$user = get_user_by( 'login', $request['remote_author'] );
		if ( $user ) {
			$args['post_author'] = $user->ID;
		}
	}
	if ( isset( $request['original_posted'] ) ) {
		$args['post_date'] = $request['original_posted'];
	}
	if ( isset( $request['post_status'] ) ) {
		$args['post_status'] = $request['post_status'];
	}
	if ( isset( $request['distributor_original_post_parent'] ) ) {
		global $wpdb;
		$parent_original_id = $request['distributor_original_post_parent'];
		$parent_id          = get_post_from_original_id( $parent_original_id );
		if ( ! empty( $parent_id ) ) {
			$args['post_parent'] = $parent_id;
		}
	}

	/*
	 * TODO: we have several problems here
	 * we can't convert content to HTML
	 * we need to happen how and when media will be created, so, we can replce id
	 * we need to work with distributed content, not get it from post
	 */
	// $args['post_content'] = get_new_media_content( $post_id ); @codingStandardsIgnoreLine
	wp_update_post( $args );
}

/**
 * Get post in destination using original post id
 *
 * @param int $original_id Original post id.
 * @return null|int
 */
function get_post_from_original_id( $original_id ) {
	global $wpdb;
	return $wpdb->get_var( "SELECT post_id from $wpdb->postmeta WHERE meta_key = 'dt_original_post_id' AND meta_value = '$original_id'" ); //phpcs:ignore
}


/**
 * Increase rest response timeout
 *
 * @param integer $timeout Request timeout in seconds.
 */
function increase_rest_timeout( $timeout ) {
	return 60;
}

/**
 * Do not delete media on update
 *
 * @param bool $delete Delete media ?.
 * @param int  $post_id Post ID.
 */
function keep_media_on_update( $delete, $post_id ) {
	return false;
}

/**
 * Check and return updated post content
 *
 * @param int $post_id Post ID.
 * @return string
 */
function get_new_media_content( $post_id ) {
	$post = get_post( $post_id );
	$dom  = new \DOMDocument();
	$dom->loadHTML( $post->post_content );
	$key = 'wp-image-';
	foreach ( $dom->getElementsByTagName( 'img' ) as $node ) {
		if ( $node->hasAttribute( 'class' ) && $node->hasAttribute( 'src' ) ) {
			$class    = $node->getAttribute( 'class' );
			$src      = $node->getAttribute( 'src' );
			$position = strpos( $class, $key );
			if ( false !== $position ) {
				$part = substr( $class, $position + strlen( $key ), strlen( $class ) - $position );
				preg_match( '/[^0-9.]/', $part, $matches );
				if ( $matches ) {
					$tail = strpos( $part, $matches[0] );
					$part = substr( $part, 0, $tail );
				}
			}
			$current_id = get_image( (int) $part );
			if ( ! empty( $current_id ) ) {
				$height = get_height_from_url( $src );
				if ( $height ) {
					$new_file = get_new_src( $height, $current_id );
					if ( isset( $new_file ) ) {
						$new_src = wp_get_attachment_image_src( $current_id, $new_file );
						$node->setAttribute( 'src', $new_src[0] );
						$node->setAttribute( 'class', str_replace( $part, $current_id, $class ) );
					}
				}
			}
		}
	}
	return $dom->saveHTML();

}


/**
 * Get image if exists
 *
 * @param int $id Image remote original id.
 * @return null|int
 */
function get_image( $id ) {
	global $wpdb;
	$result = $wpdb->get_col( "SELECT post_id from $wpdb->postmeta WHERE meta_key = 'dt_original_media_id' AND meta_value = '$id'" ); //phpcs:ignore

	if ( 1 === count( $result ) ) {
		return $result[0];
	}

	if ( count( $result ) > 1 ) {
		// We have trashed post, dodge it
		return $wpdb->get_var(
			" SELECT post_id 
                                        FROM $wpdb->postmeta 
                                          INNER JOIN `$wpdb->posts` 
                                            ON `$wpdb->posts`.`ID` = `$wpdb->postmeta`.`post_id` 
                                            AND `$wpdb->posts`.`post_status` != 'trash' 
                                        WHERE meta_key LIKE 'dt_original_media_id' 
                                          AND meta_value = '$id' 
                                        LIMIT 1"
		);
	}

	return null;
}

/**
 * Get image dimensions from provided url
 *
 * @param string $url Source url.
 * @return string|false
 */
function get_height_from_url( $url ) {
	preg_match( '/-?(\d+(?:\.\d+)?(\'|ft|yd|m|")?)\s*x\s*-?(\d+(?:\.\d+)?(?:\\2)?)/i', $url, $matches );
	return isset( $matches[3] ) ? (int) $matches[3] : false;
}

/**
 * Get new image src
 *
 * @param int $height Image height.
 * @param int $id Image id.
 */
function get_new_src( $height, $id ) {
	$dimensions = wp_get_attachment_metadata( $id );
	$heights    = [];
	foreach ( $dimensions['sizes'] as $slug => $size ) {
		$heights[ $slug ] = (int) $size['height'];
	}

	if ( ! in_array( $height, $heights, true ) ) {
		asort( $heights );

		foreach ( $heigths as $key => $needle ) {
			if ( $height < $needle ) {
				$file = $key;
				break;
			}
		}
	} else {
		$file = array_search( $height, $heights, true );
	}

	return $file;
}






/**
 *  Setup filters for bulk redistribution
 */
function setup_bulk_redistribute() {
	$list = get_distributable_custom_post_types();
	foreach ( $list as $screen ) {
		add_filter( 'bulk_actions-edit-' . $screen, __NAMESPACE__ . '\display_bulk_redistribute' );
		add_filter( 'handle_bulk_actions-edit-' . $screen, __NAMESPACE__ . '\handle_bulk_redistribute', 10, 3 );
	}
}


/**
 * Add redistribute to bulk actions
 *
 * @param array $actions Array of bulk actions.
 * @return array
 */
function display_bulk_redistribute( $actions ) {
	$actions['redistribute'] = __( 'Redistribute', 'distributor' );
	return $actions;
}

/**
 * Replace native send notifications with cron job
 *
 * @param int $post_id Post ID.
 */
function schedule_send_notifications( $post_id ) {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
		return false;
	}
	if ( ! in_array( get_post_type( $post_id ), get_distributable_custom_post_types() ) ) {  //phpcs:ignore
		return false;
	}
	schedule_redistribution( $post_id );
}

/**
 * Schedule post redistribution
 *
 * @param int  $post_id Post id.
 * @param bool $schedule Schedule cron job? Default true.
 */
function schedule_redistribution( $post_id, $schedule = true ) {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
		return false;
	}
	if ( ! in_array( get_post_type( $post_id ), get_distributable_custom_post_types() ) ) {  //phpcs:ignore
		return false;
	}
	update_post_meta( $post_id, 'dt_redistribute_post', 'yes' );
	if ( $schedule && ! wp_next_scheduled( 'dt_redistribute_posts_hook' ) ) {
		wp_schedule_single_event( time(), 'dt_redistribute_posts_hook' );
	}
}

/**
 * Schedule bulk redistribution
 *
 * @param array $post_ids Array of post ids to redistribute.
 */
function schedule_bulk_redistribution( $post_ids ) {
	foreach ( $post_ids as $post_id ) {
		schedule_redistribution( $post_id, false );
	}
	if ( ! wp_next_scheduled( 'dt_redistribute_posts_hook' ) ) {
		wp_schedule_single_event( time(), 'dt_redistribute_posts_hook' );
	}
}

/**
 * Handle bulk redistribution
 *
 * @param string $redirect_to Redirect link after action completed.
 * @param string $action Action slug.
 * @param array  $post_ids Array of selected post ids.
 */
function handle_bulk_redistribute( $redirect_to, $action, $post_ids ) {
	if ( 'redistribute' !== $action ) {
		return $redirect_to;
	}
	schedule_bulk_redistribution( $post_ids );
	return $redirect_to;
}



/**
 * Perform posts redistribution
 */
function redistribute_posts() {
	$query = new \WP_Query(
		array(
			'post_type'      => get_distributable_custom_post_types(),
			'post_status'    => array( 'publish', 'draft', 'trash' ),
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'posts_per_page' => 20,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => 'dt_redistribute_post',
					'compare' => 'EXISTS',
				),
			),
		)
	);
	$posts = $query->posts;
	if ( ! empty( $posts ) ) {
		$post_ids = array();
		foreach ( $posts as $post ) {
			$post_ids[] = $post->ID;
		}
		$count = count( $post_ids );
		foreach ( $posts as $post ) {
			\Distributor\Subscriptions\send_notifications( $post->ID );
			delete_post_meta( $post->ID, 'dt_redistribute_post' );

			for ( $i = 0; $i < $count; $i++ ) {
				if ( isset( $post_ids[ $i ] ) && $post_ids[ $i ] == $post->ID ) {
					unset( $post_ids[ $i ] );
					update_option( 'dt_redistributing_posts', $post_ids, false );
				}
			}
		}
		if ( $query->found_posts > $query->post_count ) {
			wp_schedule_single_event( time(), 'dt_redistribute_posts_hook' );
		}
	}

}


/**
 * Add taxonomies created by ctp-by-mq plugin
 *
 * @param array   $taxonomies Array of taxonomies names.
 * @param WP_Post $post WP_Post object.
 */
function add_mq_taxonomies( $taxonomies, $post ) {
	$mq_taxonomies = array(
		'policy-type',
		'restriction-option',
		'country',
		'nav-code',
		'search-term',
		'type',
		'unit-of-measure',
		'workflow_milestone',
		'workflow_priority',
		'workflow_status',
		'zone',
	);
	return array_merge( $taxonomies, $mq_taxonomies );
}

/**
 * Returns custom post types that are registered by other plugins and must be distributable.
 *
 * @return array
 */
function get_distributable_custom_post_types() {
	return array(
		'agreements',
		'ai_galleries',
		'application',
		'banners',
		'blocks',
		'compliance-rule',
		'documentation',
		'events',
		'faq',
		'features',
		'glossary',
		'help',
		'information',
		'newsletters',
		'page',
		'post',
		'presentations',
		'product',
		'resellers',
		'services',
		'shipping_option',
		'shipping_package',
		'shipping_validation',
		'task',
		'vacancies',
	);
}

/**
 * Some post types are no visible in REST, so, cannot be distributed.
 *
 * @param array  $args      Array of arguments for registering a post type.
 * @param string $post_type Post type key.
 */
function show_post_type_in_rest( $args, $post_type ) {
	if ( in_array( $post_type, get_distributable_custom_post_types() ) ) { //phpcs:ignore
		$args['show_in_rest'] = true;
	}
	return $args;
}

/**
 * Add custom post types that are distributable.
 *
 * @param array $post_types Post types that are distributable.
 */
function distributable_custom_post_types( $post_types ) {
	return array_merge( $post_types, get_distributable_custom_post_types() );
}


/**
 * Handle non image media processing
 *
 * @param array   $allowed_extensions Array of allowed extensions.
 * @param string  $url Media url.
 * @param integer $post_id Post ID.
 */
function add_media_extensions( $allowed_extensions, $url, $post_id ) {
	if ( ! in_array( 'pdf', $allowed_extensions, true ) ) {
		$allowed_extensions[] = 'pdf';
	}
	return $allowed_extensions;
}

/**
 * Handle additional metas for deleted post's origin
 *
 * @param int $post_id Post_ID.
 * @param int $remote_post_id Deleted remote Post ID.
 */
function delete_subscription_data( $post_id, $remote_post_id ) {
	$connection_map = get_post_meta( $post_id, 'dt_connection_map', true );
	if ( ! empty( $connection_map ) && isset( $connection_map['external'] ) ) {
		$to_delete = null;
		foreach ( $connection_map['external'] as $connection_id => $connection_data ) {
			if ( (int) $connection_data['post_id'] === (int) $remote_post_id ) {
				$to_delete = $connection_id;
			}
		}
		if ( ! is_null( $to_delete ) ) {
			unset( $connection_map['external'][ $to_delete ] );
			update_post_meta( $post_id, 'dt_connection_map', $connection_map );
		}
	}
}

/**
 * Register REST route for redistribution
 */
function register_redistribute_route() {

	register_rest_route(
		'wp/v2',
		'/distributor/distribute/(?P<id>\d+)',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\redistribute_post',
			'permission_callback' => __NAMESPACE__ . '\redistribute_post_perms',
		)
	);

}

/**
 * Redistribute post
 *
 * @param array $data Data from request.
 * @return array
 */
function redistribute_post( $data ) {
	$post_id = absint( $data['post_id'] );

	$responses = \Distributor\Subscriptions\send_notifications( $post_id );
	// Also trigger a cron event just in case there are any first time syncs.
	if ( ! wp_next_scheduled( 'dt_push_groups_hook' ) ) {
		wp_schedule_single_event( time(), 'dt_push_groups_hook' );
	}

	$message = '';
	if ( $responses ) {
		$success = array_filter(
			$responses,
			function( $response ) {
				return ! is_wp_error( $response ) && property_exists( $response, 'updated' ) && $response->updated;
			}
		);
		$failed  = array_filter(
			$responses,
			function( $response ) {
				return is_wp_error( $response ) || ! ( property_exists( $response, 'updated' ) && $response->updated );
			}
		);

		$message = '';
		if ( $success ) {
			// translators:.
			$message .= sprintf( __( 'The post has been successfully distributed to %d site(s)' ), count( $success ) ) . '<br/>';
		}
		if ( $failed ) {
			// translators:.
			$message .= sprintf( __( 'Distribution to external connection(s) %s have failed. More information in the error log.' ), '<strong>' . implode( ', ', array_keys( $failed ) ) . '</strong>' );
		}
	}

	return array(
		'success' => empty( $failed ),
		'message' => $message,
	);
}


/**
 * Render redistribute button in wp admin bar
 *
 * @param int   $post_id Post id.
 * @param array $connection_map Array of post connections.
 */
function render_redistribute_button( $post_id, $connection_map ) {
	if ( isset( $connection_map['external'] ) && ! empty( $connection_map['external'] ) ) {
		?>
		<div class="redistribute-wrapper">
			<button id="redistribute-button" ><?php esc_html_e( 'Redistribute' ); ?></button>
		</div>
		<div id="redistribute-response" style="display:none"></div>
		<style>
			.redistribute-wrapper {
				float: right;
				clear: right;
				width: 35% !important;
			}
			#wpadminbar #distributor-push-wrapper .redistribute-wrapper.loading #redistribute-button:after {
				content: ' ';
				vertical-align: middle;
				border-radius: 50%;
				width: 6px;
				margin-left: 8px;
				height: 6px;
				display: inline-block;
				font-size: 9px;
				text-indent: -9999em;
				border-top: 3px solid #cfcfcf;
				border-right: 3px solid #cfcfcf;
				border-bottom: 3px solid #cfcfcf;
				border-left: 3px solid #00aef2;
				-webkit-transform: translateZ(0);
				transform: translateZ(0);
				-webkit-animation: a 1.1s infinite linear;
				animation: a 1.1s infinite linear;
				position: relative;
				top: -1px;
				-webkit-box-sizing: content-box;
				box-sizing: content-box;
				-webkit-box-sizing: initial;
				box-sizing: initial;
			}

			#redistribute-response {
				width: 35%;
				float: right;
				clear: right;
			}
		</style>
		<script>
			var btn = jQuery('#redistribute-button');
			btn !== null &&
			btn.click(function() {
				var wrapper = btn.parent();

				if (!wrapper.hasClass('loading')) {
					wrapper.addClass('loading');
					wrapper.css('opacity', '.5');
				}
				var data = {
					action: 'dt_redistribute_post',
					nonce: dt.nonce,
					post_id: dt.postId,
				};

				jQuery.post(dt.ajaxurl, data, function(response) {
					jQuery('#redistribute-response')
						.empty()
						.show()
						.append(response.data);
					wrapper.removeClass('loading');
					wrapper.css('opacity', '');
				});
			});
		</script>
		<?php
	}
}

/**
 * Handle ajax redistribution call
 */
function ajax_redistribute() {
	if ( ! check_ajax_referer( 'dt-push', 'nonce', false ) ) {
		wp_send_json_error();
		exit;
	}

	if ( empty( $_POST['post_id'] ) ) {
		wp_send_json_error();
		exit;
	}
	$result = redistribute_post( $_POST );
	$result['success'] ? wp_send_json_success( $result['message'] ) : wp_send_json_error( $result['message'] );
}

/**
 * Check if user can redistribute
 *
 * @param array $request Request array.
 */
function redistribute_post_perms( $request ) {
	return current_user_can( 'publish_posts' );
}

/**
 * Replace distributed 'waves blocks' id-s in the post content to appropriate local blocks' id-s
 *
 * @param string $shortcode
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
function localize_theme_blocks( $shortcode, $matches ) {
	if ( 'block' === $matches[2] && ! empty( $matches[3] ) ) {

		// The 'block' shortcode has only 'id' attribute, we should get something like this: 'id="123"'
		$attr = $matches[3];

		$exploded = explode( '=', $attr );

		$original_block_id = (int) trim( $exploded[1], '"' );

		// todo implement mapping functionality after creating blocks distribution functionality
		// Get the mapped id for block
		$mapped_block_id = $original_block_id + 1;

		// Reassemble the 'block' shortcode with the 'mapped block id'
		$shortcode = '[' . $matches[2] . ' id="' . $mapped_block_id . '"]';
	}

	return $shortcode;
}
