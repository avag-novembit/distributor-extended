<?php
/**
 * Handle media fields in post content
 *
 * @package Distributor
 */

namespace Distributor\Members;

/**
 * Setup actions
 */
function setup() {
	add_action(
		'init',
		function () {
			// Prevent issue with Memberships checking post_parent of non-existing post ID - https://github.com/10up/distributor/issues/221
			add_filter(
				'members_check_parent_post_permission',
				function( $check, $post_id, $user_id ) {
					if ( empty( $post_id ) ) { // Don't check when post ID is not set
						return false;
					}

					return $check;
				},
				10,
				3
			);
		}
	);
}
