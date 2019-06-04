<?php
/**
 * Install functions.
 *
 * @package  distributor
 */

namespace Distributor\Installer;

/**
 * Setup installer.
 */
function setup() {
	add_action( 'init', __NAMESPACE__ . '\check_versions' );
}


/**
 * Compare versions and perform install/update accordingly.
 */
function check_versions() {
	if ( ! defined( 'IFRAME_REQUEST' ) && version_compare( get_option( 'distributor_version' ), DT_VERSION, '<' ) ) {
		install();
		do_action( 'distributor_updated' );
	}
}


/**
 * All actions to be executed on install/update.
 */
function install() {
	create_tables();
	update_function();

	update_option( 'distributor_version', DT_VERSION );
}


/**
 * Create the database tables.
 */
function create_tables() {
	\Distributor\Logger\create_log_table();
}


/**
 * Contains functions to execute based on version being updated.
 */
function update_function() {
	wp_clear_scheduled_hook( 'dt_push_groups_hook' ); // Clear old schedule running every 10 seconds

	// @todo - in the future this would contain update functions to execute per version
}
