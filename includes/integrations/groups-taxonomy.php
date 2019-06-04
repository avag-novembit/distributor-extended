<?php
/**
 * GROUPS TAXONOMY functionality
 *
 * @package Distributor
 */

namespace Distributor\ConnectionGroups;

/**
 * Setup actions
 *
 * @since 1.3.0
 */
function setup() {
	add_action(
		'plugins_loaded',
		function () {
			add_action( 'init', __NAMESPACE__ . '\setup_groups' );
			add_action( 'admin_menu', __NAMESPACE__ . '\add_submenu_item', 11 );
			add_action( 'load-post.php', __NAMESPACE__ . '\init_metabox' );
			add_action( 'load-post-new.php', __NAMESPACE__ . '\init_metabox' );
			add_action( 'dt_push_groups_hook', __NAMESPACE__ . '\dt_push_groups' );
			add_filter( 'cron_schedules', __NAMESPACE__ . '\add_cron_interval' );
			add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\add_groups_check_scripts', 10, 1 );
		}
	);
}

/**
 * Register taxonomy for groups
 *
 * @since 1.3.0
 */
function setup_groups() {
	$taxonomy_capabilities = array(
		'manage_terms' => 'manage_categories',
		'edit_terms'   => 'manage_categories',
		'delete_terms' => 'manage_categories',
		'assign_terms' => 'edit_posts',
	);

	$taxonomy_labels = array(
		'name'              => esc_html__( 'External Connection Groups' ),
		'singular_name'     => esc_html__( 'External Connection Group' ),
		'search_items'      => esc_html__( 'Search External Connection Groups' ),
		'popular_items'     => esc_html__( 'Popular External Connection Groups' ),
		'all_items'         => esc_html__( 'All External Connection Groups' ),
		'parent_item'       => esc_html__( 'Parent External Connection Group' ),
		'parent_item_colon' => esc_html__( 'Parent External Connection Group' ),
		'edit_item'         => esc_html__( 'Edit External Connection Group' ),
		'update_item'       => esc_html__( 'Update External Connection Group' ),
		'add_new_item'      => esc_html__( 'Add New External Connection Group' ),
		'new_item_name'     => esc_html__( 'New External Connection Group Name' ),

	);
	$args = array(
		'labels'            => $taxonomy_labels,
		'public'            => false,
		'show_ui'           => true,
		'meta_box_cb'       => false,
		'show_tagcloud'     => false,
		'show_in_nav_menus' => false,
		'hierarchical'      => true,
		'rewrite'           => false,
		'capabilities'      => $taxonomy_capabilities,

	);
	register_taxonomy( 'dt_ext_connection_group', 'dt_ext_connection', $args );
}

/**
 * Add submenu for groups
 *
 * @since 1.3.0
 */
function add_submenu_item() {
	$link = admin_url( 'edit-tags.php' ) . '?taxonomy=dt_ext_connection_group&post_type=dt_ext_connection';
	add_submenu_page(
		'distributor',
		esc_html__( 'External Connection Groups', 'distributor' ),
		esc_html__( 'External Connection Groups', 'distributor' ),
		'manage_options',
		$link
	);
}

/**
 * Init actions for connection groups metabox
 */
function init_metabox() {
	add_action( 'add_meta_boxes', __NAMESPACE__ . '\add_metabox' );
	add_action( 'save_post', __NAMESPACE__ . '\save_metabox', 10, 2 );

}

/**
 * Add metabox for connection groups
 */
function add_metabox() {
	add_meta_box(
		'external-connection-groups',
		__( 'External Connection Groups', 'distributor' ),
		__NAMESPACE__ . '\render_metabox',
		\Distributor\Waves\get_distributable_custom_post_types(),
		'side',
		'high'
	);

}

/**
 * Render metabox for connection groups
 *
 * @param object(WP_Post) $post Current editing post object
 */
function render_metabox( $post ) {
	// Add nonce for security and authentication.
	wp_nonce_field( 'save_connection_groups', 'connection_groups_field' );
	\Distributor\ExternalConnectionGroups::factory()->groups_checklist( 'dt_ext_connection_group', $post->ID );
}

/**
 * Process connection groups pushing
 *
 * @param Integer         $post_id Id of current post
 * @param object(WP_Post) $post Current editing post object
 */
function save_metabox( $post_id, $post ) {

	// Check if user has permissions to save data.
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Check if not an autosave.
	if ( wp_is_post_autosave( $post_id ) ) {
		return;
	}

	// Check if not a revision.
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! in_array( $post->post_type, \Distributor\Waves\get_distributable_custom_post_types(), true ) ) {
		return;
	}
	if ( 'auto-draft' === $post->post_status ) {
		return;
	}

	if ( ! isset( $_POST['connection_groups_field'] ) || ! wp_verify_nonce( $_POST['connection_groups_field'], 'save_connection_groups' ) ) {
		return;
	}
	$group_ids = $_POST['tax_input']['dt_ext_connection_group'] ?? array();
	$groups    = array();
	foreach ( $group_ids as $group_id ) {
		$term     = get_term_by( 'id', $group_id, 'dt_ext_connection_group' );
		$groups[] = $term->slug;
	}
	update_post_meta( $post_id, 'dt_connection_groups', $groups );

	$groups = get_post_meta( $post_id, 'dt_connection_groups', true );

	if ( ! empty( $groups ) && is_array( $groups ) ) {
		$pushed_groups = get_post_meta( $post_id, 'dt_connection_groups_pushed', true );

		if ( ! empty( $pushed_groups ) ) {
			if ( ! empty( array_diff( $groups, $pushed_groups ) ) ) {
				update_post_meta( $post_id, 'dt_connection_groups_pushing', array_diff( $groups, $pushed_groups ) );
			}
		} else {
			update_post_meta( $post_id, 'dt_connection_groups_pushing', $groups );
		}
	}

	/*TODO: find better place for this */
	if ( ! wp_next_scheduled( 'dt_push_groups_hook' ) ) {
		wp_schedule_single_event( time(), 'dt_push_groups_hook' );
	}
}

/**
 * Get connections for group
 *
 * @param string $group Single group term
 * @return array
 */
function get_connections( $group ) {
	$term             = get_term_by( 'slug', $group, 'dt_ext_connection_group' );
	$connections      = array();
	$connection_array = get_posts(
		array(
			'post_type'   => 'dt_ext_connection',
			'numberposts' => -1,
			'tax_query'   => array(
				array(
					'taxonomy'         => 'dt_ext_connection_group',
					'field'            => 'term_id',
					'terms'            => $term->term_id,
					'include_children' => true,
				),
			),
		)
	);
	if ( ! empty( $connection_array ) ) {
		foreach ( $connection_array as $index => $conn_obj ) {
			$connection         = array();
			$connection['id']   = $conn_obj->ID;
			$connection['type'] = $conn_obj->post_type;
			$connections[]      = $connection;
		}
	}
	return $connections;
}

/**
 * Push post to single connection
 *
 * @param array           $connection Single connection array
 * @param object(WP_Post) $post Current editing post object
 */
function push_connection( $connection, $post ) {
	$connection_map = get_post_meta( $post->ID, 'dt_connection_map', true );
	if ( empty( $connection_map ) ) {
		$connection_map = array();
	}

	if ( empty( $connection_map['external'] ) ) {
		$connection_map['external'] = array();
	}
	if ( 'dt_ext_connection' === $connection['type'] ) {
		$external_connection_type = get_post_meta( $connection['id'], 'dt_external_connection_type', true );
		$external_connection_url  = get_post_meta( $connection['id'], 'dt_external_connection_url', true );
		$external_connection_auth = get_post_meta( $connection['id'], 'dt_external_connection_auth', true );

		if ( empty( $external_connection_auth ) ) {
			$external_connection_auth = array();
		}

		if ( ! empty( $external_connection_type ) && ! empty( $external_connection_url ) ) {
			$external_connection_class = \Distributor\Connections::factory()->get_registered()[ $external_connection_type ];

			$auth_handler = new $external_connection_class::$auth_handler_class( $external_connection_auth );

			$external_connection = new $external_connection_class( get_the_title( $connection['id'] ), $external_connection_url, $connection['id'], $auth_handler );

			$push_args = array();

			if ( ! empty( $connection_map['external'][ (int) $connection['id'] ] ) && ! empty( $connection_map['external'][ (int) $connection['id'] ]['post_id'] ) ) {
				$push_args['remote_post_id'] = (int) $connection_map['external'][ (int) $connection['id'] ]['post_id'];
			}

			if ( ! empty( $post->post_status ) ) {
				$push_args['post_status'] = $post->post_status;
			}

			$remote_id = $external_connection->push( intval( $post->ID ), $push_args );

			/**
			 * Record the external connection id's remote post id for this local post
			 */

			if ( ! is_wp_error( $remote_id ) && 0 !== (int) $remote_id ) {
				$connection_map['external'][ (int) $connection['id'] ] = array(
					'post_id' => (int) $remote_id,
					'time'    => time(),
				);

				$external_push_results[ (int) $connection['id'] ] = array(
					'post_id' => (int) $remote_id,
					'date'    => date( 'F j, Y g:i a' ),
					'status'  => 'success',
				);
				\Distributor\Logger\log( 'success', 'first push', $connection['id'], $post->ID, null, $post->post_type );
				$external_connection->log_sync( array( $remote_id => $post->ID ) );
			} elseif ( ! is_wp_error( $remote_id ) && 0 === (int) $remote_id ) {
				$external_push_results[ (int) $connection['id'] ] = array(
					'post_id' => (int) $remote_id,
					'date'    => date( 'F j, Y g:i a' ),
					'status'  => 'fail',
				);
				\Distributor\Logger\log( 'error', 'first push', $connection['id'], $post->ID, 'Can not set up remote id properly', $post->post_type );
			} else {
				$external_push_results[ (int) $connection['id'] ] = array(
					'post_id' => (int) $remote_id,
					'date'    => date( 'F j, Y g:i a' ),
					'status'  => 'fail',
				);
				\Distributor\Logger\log( 'error', 'first push', $connection['id'], $post->ID, $remote_id->get_error_messages(), $post->post_type );
			}
		}
	}
	update_post_meta( intval( $post->ID ), 'dt_connection_map', $connection_map );
}
/**
 * Perform scheduled push groups
 */
function dt_push_groups() {
	$query = new \WP_Query(
		array(
			'post_type'      => \Distributor\Waves\get_distributable_custom_post_types(),
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'posts_per_page' => 20,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => 'dt_connection_groups_pushing',
					'compare' => 'EXISTS',
				),
			),
		)
	);

	$all_posts = $query->posts;
	if ( ! empty( $all_posts ) ) {
		foreach ( $all_posts as $post ) {
			$connection_map = get_post_meta( $post->ID, 'dt_connection_groups_pushing', true );
			if ( empty( $connection_map ) ) {
				delete_post_meta( $post->ID, 'dt_connection_groups_pushing' );
				continue;
			} elseif ( ! is_array( $connection_map ) ) {
				$connection_map = array( $connection_map );
			}
			$successed_groups = get_post_meta( $post->ID, 'dt_connection_groups_pushed', true );
			if ( empty( $successed_groups ) || null === $successed_groups ) {
				$successed_groups = array();
			}
			foreach ( $connection_map as $group ) {

				$index            = get_term_by( 'slug', $group, 'dt_ext_connection_group' )->term_id;
				$push_connections = get_connections( $group );
				if ( empty( $push_connections ) ) {
					$key = array_search( $group, $connection_map, true );
					if ( ! in_array( $group, $successed_groups, true ) ) {
						$successed_groups[] = $group;
						update_post_meta( $post->ID, 'dt_connection_groups_pushed', $successed_groups );
					}
					if ( false !== $key || null !== $key ) {
						unset( $connection_map[ $key ] );
					}
					continue;
				}
				$pushed_connections_map = get_post_meta( $post->ID, 'dt_connection_map', true );

				foreach ( $push_connections as $con ) {
					if ( empty( $pushed_connections_map ) || ! isset( $pushed_connections_map['external'] ) || ! in_array( $con['id'], array_keys( $pushed_connections_map['external'] ), true ) ) {
						\Distributor\ConnectionGroups\push_connection( $con, $post );
					}
				}

				$key = array_search( $group, $connection_map, true );
				if ( ! in_array( $group, $successed_groups, true ) ) {
					$successed_groups[] = $group;
					update_post_meta( $post->ID, 'dt_connection_groups_pushed', $successed_groups );
				}
				if ( false !== $key || null !== $key ) {
					unset( $connection_map[ $key ] );
				}
			}
			if ( empty( $connection_map ) ) {
				delete_post_meta( $post->ID, 'dt_connection_groups_pushing' );
			} else {
				update_post_meta( $post->ID, 'dt_connection_groups_pushing', $connection_map );
			}
		}
	}

	// Re-schedule a new event when there are still others to be distributed.
	if ( $query->found_posts > $query->post_count ) {
		wp_schedule_single_event( time(), 'dt_push_groups_hook' );
	}
}


/**
 * Add new interval for cron job
 *
 * @param array $schedules Array of existing schedules
 * @return array
 */
function add_cron_interval( $schedules ) {
	$schedules['ten_seconds'] = array(
		'interval' => 60,
		'display'  => esc_html__( 'Every Ten Seconds' ),
	);

	return $schedules;
}

/**
 * Add js scripts to pages
 *
 * @param string $hook Current hook
 */
function add_groups_check_scripts( $hook ) {
	if ( 'post.php' === $hook ) {
		wp_enqueue_script( 'dt_check_groups', plugins_url( '../dist/js/check-groups.js', __DIR__ ), array(), DT_VERSION, true );
	}
}
