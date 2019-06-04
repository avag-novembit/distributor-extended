<?php
/**
 * Logger for external connections
 *
 * @package  distributor
 */

namespace Distributor\Logger;

/**
 * Setup logger
 */
function setup() {
	add_action( 'admin_menu', __NAMESPACE__ . '\add_submenu_item', 11 );
}

/**
 * Create logging table if not exists
 */
function create_log_table() {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE {$wpdb->prefix}dt_ext_connections_logs (
			  ID MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
			  log_type VARCHAR(100) NOT NULL,
			  action_type VARCHAR(100) NOT NULL,
			  connection_id mediumint(9) NOT NULL,
			  post_type VARCHAR(255) NOT NULL,
			  post_id MEDIUMINT(9),
			  error_data TEXT DEFAULT NULL,
			  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY (ID)
		 ) $charset_collate;";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Insert log into db
 *
 * @param string  $log_type Type of log (e.g. error, success)
 * @param string  $action_type Action when log created (first push, update)
 * @param integer $connection_id Id of connection
 * @param integer $post_id Post id
 * @param array   $error_data Array of error messages
 */
function log( $log_type, $action_type, $connection_id, $post_id, $error_data = null, $post_type ) {
	$log_data = prepare_log( $log_type, $action_type, $connection_id, $post_id, $error_data, $post_type );
	global $wpdb;
	$wpdb->insert( $wpdb->prefix . 'dt_ext_connections_logs', $log_data );

}

/**
 * Sanitize log data before inserting
 *
 * @param string  $log_type Type of log (e.g. error, success)
 * @param string  $action_type Action when log created (first push, update)
 * @param integer $connection_id Id of connection
 * @param integer $post_id Post id
 * @param array   $error_data Array of error messages
 */
function prepare_log( $log_type, $action_type, $connection_id, $post_id, $error_data, $post_type ) {
	$args                  = array();
	$args['log_type']      = sanitize_text_field( $log_type );
	$args['action_type']   = sanitize_text_field( $action_type );
	$args['post_type']     = sanitize_text_field( $post_type );
	$args['connection_id'] = (int) $connection_id;
	$args['post_id']       = (int) $post_id;
	if ( ! empty( $error_data ) && is_array( $error_data ) ) {
		$args['error_data'] = maybe_serialize( array_map( 'sanitize_text_field', $error_data ) );
	} else {
		$args['error_data'] = maybe_serialize( $error_data );
	}
	return $args;
}


/**
 * Add submenu for logger
 */
function add_submenu_item() {
	$hook = add_submenu_page(
		'distributor',
		esc_html__( 'External Logs', 'distributor' ),
		esc_html__( 'External Logs', 'distributor' ),
		'manage_options',
		'connection_logs',
		__NAMESPACE__ . '\render_page'
	);
	add_action( "load-$hook", __NAMESPACE__ . '\screen_option' );
}

/**
 * Set screen options for log page
 */
function screen_option() {
	$option = 'per_page';
	$args   = [
		'label'   => 'Logs',
		'default' => 5,
		'option'  => 'logs_per_page',
	];

	add_screen_option( $option, $args );

}

/**
 * Render logs page
 */
function render_page() {
	$logs = \Distributor\LogsListTable::get_instance();
	?>
	<div class="wrap">
		<h2>External Connections Logs</h2>

		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">
					<div class="meta-box-sortables ui-sortable">
						<form method="post">
							<?php
							$logs->prepare_items();
							$logs->display();
							?>
						</form>
					</div>
				</div>
			</div>
			<br class="clear">
		</div>
	</div>
	<?php
}
