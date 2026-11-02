<?php
/**
 * Remove subscription meta on uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->commentmeta} WHERE meta_key IN (%s, %s)",
		'_wcrn_subscribed',
		'_wcrn_unsub_key'
	)
);
