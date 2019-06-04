<?php
/**
 * Admin status screen
 *
 * @package Distributor
 */

namespace Distributor\Status;

/**
 * Setup status
 *
 * @since 1.0
 */
function setup() {
	add_action(
		'plugins_loaded',
		function () {
			add_action( 'admin_menu', __NAMESPACE__ . '\admin_menu', 25 );
		}
	);
}


/**
 * Output status menu option
 *
 * @since 1.0
 */
function admin_menu() {
	add_submenu_page( 'distributor', esc_html__( 'Status', 'distributor' ), esc_html__( 'Status', 'distributor' ), 'manage_options', 'distributor-status', __NAMESPACE__ . '\status_screen' );
}


/**
 * Output status screen
 *
 * @since 1.0
 */
function status_screen() {
	global $wpdb;
	if ( isset( $_POST['dt_verify_repair_posts_nonce'] ) && wp_verify_nonce( $_POST['dt_verify_repair_posts_nonce'], 'dt_repair_posts_action' ) && isset( $_POST['dt_shedule_repair'] ) ) {
		if ( 'start' === $_POST['dt_shedule_repair'] ) {
			wp_schedule_event( current_time( 'timestamp' ), 'every_minute', 'dt_repair_posts_hook' );
		} elseif ( 'stop' === $_POST['dt_shedule_repair'] ) {
			wp_clear_scheduled_hook( 'dt_repair_posts_hook' );
		}
	}

	if ( isset( $_POST['dt_verify_nonce'] ) && wp_verify_nonce( $_POST['dt_verify_nonce'], 'dt_schedule_nonce_action' ) && isset( $_POST['dt_shedule'] ) && 'start' === $_POST['dt_shedule'] ) {
		$dt_current_timestamp = current_time( 'timestamp' );
		wp_schedule_event( $dt_current_timestamp, 'every_minute', 'dt_redistribute_posts_hook' );
	}
	if ( isset( $_POST['dt_verify_nonce'] ) && wp_verify_nonce( $_POST['dt_verify_nonce'], 'dt_unschedule_nonce_action' ) && isset( $_POST['dt_unshedule'] ) && 'stop' === $_POST['dt_unshedule'] ) {
		delete_option( 'dt_redistributing_posts' );
		wp_clear_scheduled_hook( 'dt_redistribute_posts_hook' );
	}
	if ( isset( $_POST['dt-status'] ) && isset( $_POST['dt_status_nonce'] ) && wp_verify_nonce( $_POST['dt_status_nonce'], 'dt_status_nonce_action' ) ) {
		if ( 'on' === $_POST['dt-status'] ) {
			update_option( 'dt_reditribute_status', 'on', false );
		}
		if ( 'off' === $_POST['dt-status'] ) {
			update_option( 'dt_reditribute_status', 'off', false );
		}
	}

	$groups                                  = $wpdb->get_results( "SELECT DISTINCT meta_key, meta_value FROM $wpdb->postmeta WHERE meta_key = 'dt_subscription_target_url' GROUP BY meta_value" );
	$post_count                              = $wpdb->get_var( "SELECT count(*) FROM $wpdb->postmeta WHERE meta_key = 'dt_redistribute_post' " );
	$post_ids                                = get_option( 'dt_redistributing_posts' );
	$status                                  = get_option( 'dt_reditribute_status' );
	$cron_array                              = _get_cron_array();
	$dt_timestamp                            = '';
	$dt_interval                             = '';
	$dt_shedule                              = '';
	$dt_redistribute_posts_hook_is_scheduled = '';
	$dt_repair_posts_hook_is_scheduled       = false;
	foreach ( $cron_array as $key => $value ) {
		if ( isset( $value['dt_redistribute_posts_hook'] ) ) {
			$dt_redistribute_posts_hook_is_scheduled = true;
			$dt_timestamp                            = get_date_from_gmt( date( 'Y-m-d H:i:s', $key ), 'F j, Y H:i:s' );
			foreach ( $value['dt_redistribute_posts_hook'] as $interval ) {
				$dt_interval = $interval['interval'];
				$dt_shedule  = $interval['schedule'];
			}
		}
		if ( isset( $value['dt_repair_posts_hook'] ) ) {
			$dt_repair_posts_hook_is_scheduled = true;
		}
	}
	?>
	<div class="wrap">
		<div style="width: 100%; max-width: 500px;">
			<div style="margin-bottom: 20px; border-bottom: 1px solid #ccc; padding-bottom: 20px;">
				<h2 style="font-size: 2em;"><?php echo esc_html( 'Subscription target URLs group ' ); ?></h2>
				<?php
				foreach ( $groups as $url ) {
					?>
					<p>
						<a href="<?php echo esc_url( $url->meta_value ); ?>"><?php echo esc_url( $url->meta_value ); ?></a>
					</p>
					<?php
				}
				?>
			</div>

			<div style="margin-bottom: 20px; border-bottom: 1px solid #ccc; padding-bottom: 20px;">
				<h2 style="display: inline-block; margin-right: 10px; font-size: 2em;"><?php echo esc_html( 'Status of Posts to Redistribute' ); ?></h2>
				<p><?php echo esc_html( 'Total post count scheduled to redistribute - ' . $post_count ); ?></p>
				<?php if ( true === $dt_redistribute_posts_hook_is_scheduled ) { ?>
					<p><?php echo esc_html( 'dt_redistribute_posts_hook is scheduled - Yes' ); ?></p>
					<p><?php echo esc_html( 'Scheduled on - ' . $dt_timestamp ); ?></p>
					<?php if ( '' !== $dt_interval && '' !== $dt_shedule ) { ?>
						<p><?php echo esc_html( 'Schedule interval - ' . $dt_interval . 's.  ' . $dt_shedule ); ?></p>
					<?php } ?>
					<?php
				}
				if ( true !== $dt_redistribute_posts_hook_is_scheduled ) {
					?>
					<form method="post">
						<?php
						wp_nonce_field( 'dt_schedule_nonce_action', 'dt_verify_nonce' );
						?>
						<input  name="dt_shedule" type="hidden" value="start">
						<?php
						submit_button( 'Schedule dt_redistribute_posts_hook', 'primary' );
						?>
					</form>
					<?php
				} else {
					?>
					<form method="post">
						<?php
						wp_nonce_field( 'dt_unschedule_nonce_action', 'dt_verify_nonce' );
						?>
						<input  name="dt_unshedule" type="hidden" value="stop">
						<?php
						submit_button( 'Unschedule dt_redistribute_posts_hook', 'primary' );
						?>
					</form>
					<?php
				}
				?>
			</div>
			<div>
				<form method="post">
					<?php wp_nonce_field( 'dt_repair_posts_action', 'dt_verify_repair_posts_nonce' ); ?>
					<?php if ( $dt_repair_posts_hook_is_scheduled ) : ?>
						<input  name="dt_shedule_repair" type="hidden" value="stop">
						<?php submit_button( 'Unschedule dt_repair_posts_hook', 'primary' ); ?>
					<?php else : ?>
						<input  name="dt_shedule_repair" type="hidden" value="start">
						<?php submit_button( 'Schedule dt_repair_posts_hook', 'primary' ); ?>
					<?php endif ?>

				</form>
			</div>
			<form method="post">
				<?php
				wp_nonce_field( 'dt_status_nonce_action', 'dt_status_nonce' );
				if ( 'off' === $status ) {
					?>
					<p>
						<strong style="color: red; font-size: 1.2em;"><?php echo esc_html( 'Redistribution paused ' ); ?></strong>
					</p>
					<input type="checkbox" id="dt-on" name="dt-status" value="on"><label
						for="dt-on"><?php echo esc_html( 'Resume it now!' ); ?></label>
					<?php
					submit_button( 'Process', 'primary' );
				}
				if ( ! empty( $post_ids ) ) {
					if ( 'on' === $status ) {
						?>
						<p style="font-size: 1.2em;"><?php echo esc_html( 'Background redistribution ' ); ?><strong
								style="color: green;"><?php echo esc_html( 'is running' ); ?></strong></p>
						<p><?php echo esc_html( 'Redistributing right now ...' ); ?></p>
						<?php
						foreach ( $post_ids as $post_id ) {
							$link = get_edit_post_link( $post_id );
							?>
							<a href="<?php echo esc_url( $link ); ?>" target="_blank"><?php echo esc_html( $post_id ); ?></a>,&nbsp;
							<?php
						}
						?>
						<p><input type="checkbox" id="dt-off" name="dt-status" value="off"><label
								for="dt-off"><?php echo esc_html( 'Pause it now!' ); ?></label></p>
						<p><?php submit_button( 'Process', 'primary' ); ?> </p>
						<?php
					}
				}
				?>
			</form>
		</div>
	</div>
	<?php
}
