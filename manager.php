<?php

/**
 * PSR-4 autoloading
 */
spl_autoload_register(
	function( $class ) {
		// Project-specific namespace prefix.
		$prefix = 'Distributor\\';
		// Base directory for the namespace prefix.
		$base_dir = __DIR__ . '/includes/classes/';
		// Does the class use the namespace prefix?
		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}
		$relative_class = substr( $class, $len );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';
		// If the file exists, require it.
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/* Require plug-in files */
require_once __DIR__ . '/includes/external-connections-logger.php';
require_once __DIR__ . '/includes/integrations/status.php';
require_once __DIR__ . '/includes/check-permissions.php';
require_once __DIR__ . '/includes/integrations/waves.php';
require_once __DIR__ . '/includes/integrations/groups-taxonomy.php';
require_once __DIR__ . '/includes/integrations/clone-fix.php';
require_once __DIR__ . '/includes/integrations/woocommerce.php';
require_once __DIR__ . '/includes/integrations/acf.php';
require_once __DIR__ . '/includes/integrations/members.php';
require_once __DIR__ . '/includes/integrations/blocks.php';
require_once __DIR__ . '/includes/installer.php';
require_once __DIR__ . '/includes/content-receiver.php';
require_once __DIR__ . '/includes/hooks.php';

/* Call the setup functions */
\Distributor\ConnectionGroups\setup();
\Distributor\Logger\setup();
\Distributor\CloneFix\setup();
\Distributor\Status\setup();
\Distributor\Permissions\setup();
\Distributor\Woocommerce\setup();
\Distributor\Acf\setup();
\Distributor\Waves\setup();
\Distributor\Members\setup();
\Distributor\Installer\setup();
\Distributor\ContentReceiver\setup();
\Distributor\Blocks\setup();
\Distributor\Hooks\setup();
