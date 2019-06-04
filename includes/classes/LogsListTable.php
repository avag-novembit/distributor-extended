<?php
/**
 * Display logs in WP table
 *
 * @package Distributor
 */

namespace Distributor;

/**
 * Class to render logs table
 */
class LogsListTable extends \WP_List_Table {
	/**
	 * Instance of class
	 *
	 * @var object
	 */
	protected static $instance = null;

	/**
	 * Class constructor
	 */
	public function __construct() {

		parent::__construct(
			[
				'singular' => __( 'Log', 'distributor' ),
				'plural'   => __( 'Logs', 'distributor' ),
				'ajax'     => false,

			]
		);
	}
	/**
	 * Get logs data from db
	 *
	 * @param int $per_page Display per page
	 * @param int $page_number Current page number
	 *
	 * @return mixed
	 */
	public static function get_logs( $per_page = 5, $page_number = 1 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'dt_ext_connections_logs';
		$sql        = "SELECT * FROM {$table_name}";
		if ( ! empty( $_REQUEST['orderby'] ) ) {
			$sql .= ' ORDER BY ' . esc_sql( $_REQUEST['orderby'] );
			$sql .= ! empty( $_REQUEST['order'] ) ? ' ' . esc_sql( $_REQUEST['order'] ) : ' ASC';
		}
		$sql .= " LIMIT $per_page";

		$sql .= ' OFFSET ' . ( $page_number - 1 ) * $per_page;

		$result = $wpdb->get_results( $sql, 'OBJECT' );

		return $result;
	}

	/**
	 * Delete log record.
	 *
	 * @param int $id log ID
	 */
	public static function delete_log( $id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'dt_ext_connections_logs';
		$wpdb->delete(
			"{$table_name}",
			[ 'ID' => $id ],
			[ '%d' ]
		);
	}

	/**
	 * Returns the count of records in the database.
	 *
	 * @return null|string
	 */
	public static function record_count() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'dt_ext_connections_logs';
		$sql        = "SELECT COUNT(*) FROM {$table_name}";

		return $wpdb->get_var( $sql );
	}

	/**
	 * Text displayed when no customer data is available
	 */
	public function no_items() {
		esc_html_e( 'No logs avaliable.', 'distributor' );
	}




	/**
	 * Render the bulk edit checkbox
	 *
	 * @param array $item Checked item
	 *
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="bulk-delete[]" value="%s" />',
			$item->ID
		);
	}

	/**
	 * Render a column when no column specific method exists.
	 *
	 * @param array  $item Log item from db
	 * @param string $column_name Column name
	 *
	 * @return mixed
	 */
	public function column_default( $item, $column_name ) {

		switch ( $column_name ) {
			case 'error_data':
				$messages = maybe_unserialize( $item->$column_name );
				$result   = '';
				if ( ! empty( $messages ) && is_array( $messages ) ) {
					foreach ( $messages as $message ) {
						$result .= $message . '<br>';
					}
				} else {
					$result = $messages;
				}
				return $result;
			default:
				return $item->$column_name;

		}
	}


	/**
	 *  Associative array of columns
	 *
	 * @return array
	 */
	public function get_columns() {
		$columns = array(
			'cb'            => '<input type="checkbox" />',
			'log_type'      => __( 'Status', 'distributor' ),
			'action_type'   => __( 'Action', 'distributor' ),
			'connection_id' => __( 'Connection', 'distributor' ),
			'post_type'     => __( 'Post Type', 'distributor' ),
			'post_id'       => __( 'Post ID', 'distributor' ),
			'error_data'    => __( 'External data', 'distributor' ),
			'created_at'    => __( 'Created At', 'distributor' ),
		);
		return $columns;
	}

	/**
	 * Columns to make sortable.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		$sortable_columns = array(
			'log_type'      => array( 'log_type', true ),
			'action_type'   => array( 'action_type', true ),
			'connection_id' => array( 'connection_id', true ),
			'post_id'       => array( 'post_id', true ),
			'created_at'    => array( 'created_at', true ),
		);

		return $sortable_columns;
	}

	/**
	 * Returns an associative array containing the bulk action
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		$actions = [
			'bulk-delete' => 'Delete',
		];

		return $actions;
	}

	/**
	 * Handles data query and filter, sorting, and pagination.
	 */
	public function prepare_items() {

		$this->_column_headers = array( $this->get_columns(), [], $this->get_sortable_columns() );

		$this->process_bulk_action();

		$per_page     = $this->get_items_per_page( 'logs_per_page', 5 );
		$current_page = $this->get_pagenum();
		$total_items  = self::record_count();

		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
			]
		);
		$this->items = self::get_logs( $per_page, $current_page );
	}

	/**
	 * Process bulk actions
	 */
	public function process_bulk_action() {
		// If the delete bulk action is triggered
		if ( ( isset( $_POST['action'] ) && 'bulk-delete' === $_POST['action'] ) || ( isset( $_POST['action2'] ) && 'bulk-delete' === $_POST['action2'] )
		) {

			$delete_ids = esc_sql( $_POST['bulk-delete'] );

			// loop over the array of record IDs and delete them
			foreach ( $delete_ids as $id ) {
				self::delete_log( $id );

			}

			wp_safe_redirect( esc_url( add_query_arg() ) );
			exit;
		}
	}

	/**
	 * Singleton instance
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

}
