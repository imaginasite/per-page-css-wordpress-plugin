<?php
/**
 * File executed when deleting (uninstalling) the plugin
 */

// If the call does not come from WordPress (security), we stop
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

// Delete all post metas with the '_imaginasite_per_page_css' key from the database
delete_post_meta_by_key( '_imaginasite_per_page_css' );
