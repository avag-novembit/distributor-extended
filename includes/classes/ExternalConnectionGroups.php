<?php
/**
 * Admin checkbox list of connection groups
 *
 * @package  distributor
 */

namespace Distributor;

/**
 * External connection groups checklist
 */
class ExternalConnectionGroups {


	/**
	 * Array of connection groups
	 *
	 * @var array
	 */
	protected $all_groups = array();
	/**
	 * Array of arguments for wp_terms_checklist function
	 *
	 * @var array
	 */
	public $args = array();

	/**
	 * Assign variables and add actions
	 */
	protected function __construct() {
		$this->all_groups = get_terms(
			array(
				'taxonomy'   => 'dt_ext_connection_group',
				'fields'     => 'all',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
	}

	/**
	 * Display connection groups checklist in Connection edit page
	 *
	 * @param integer $id Connection id to get terms checklist for it
	 */
	public function display_groups( $id ) {
		?>
		<div class="dt-connection-groups">
			<label><?php esc_html_e( 'Connection Groups', 'distributor' ); ?></label><br>
			<?php if ( empty( $this->all_groups ) ) : ?>
				<p><?php esc_html_e( 'You haven\'t added connection groups yet' ); ?><br>
					<?php esc_html_e( 'You can add them' ); ?>
					<a href="<?php esc_url( admin_url( 'edit-tags.php' ) . '?taxonomy=dt_ext_connection_group&post_type=dt_ext_connection' ); ?>">
						<?php esc_html_e( 'here' ); ?>
					</a>
				</p>
				<?php
			else :

					$this->groups_checklist( 'dt_ext_connection_group', $id, true );

			endif
			?>
		</div>
		<?php
	}

	/**
	 * Print terms checklist
	 *
	 * @param string  $taxonomy Taxonomy slug to get terms for it
	 * @param integer $post_id Post id to get assigned terms
	 * @param bool    $connection_page Defines if script is on connection edit page
	 */
	public function groups_checklist( $taxonomy, $post_id, $connection_page = false ) {
		?>
		<ul>
		<?php wp_terms_checklist( $post_id, [ 'taxonomy' => $taxonomy ] ); ?>
		</ul>
		<?php
		if ( ! $connection_page ) {
			$this->distributed_groups( $post_id );
		}
	}

	/**
	 * Get and send distributed group to DOM
	 *
	 * @param int $post_id Post id
	 */
	public function distributed_groups( $post_id ) {
		$groups = get_post_meta( $post_id, 'dt_connection_groups', true );
		$ids    = [];
		if ( ! empty( $groups ) ) {

			foreach ( $groups as $slug ) {
				$id    = get_term_by( 'slug', $slug, 'dt_ext_connection_group' )->term_id;
				$ids[] = $id;
			}
			?>
			<div id='active-groups-ids' style='display:none' data-groups=<?php echo wp_json_encode( $ids ); ?>>
			</div>
			<?php
		}

	}

	/**
	 * Get class instance
	 */
	public static function factory() {
		static $instance;

		if ( ! $instance ) {
			$instance = new self();
		}

		return $instance;
	}

}
