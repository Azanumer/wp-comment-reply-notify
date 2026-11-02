<?php
/**
 * Plugin Name:       Comment Reply Notify
 * Plugin URI:        https://github.com/Azanumer/wp-comment-reply-notify
 * Description:       Emails commenters when someone replies to their comment — opt-in checkbox on the form, one-click unsubscribe, no account needed.
 * Version:           1.0.0
 * Author:            Azan Umer
 * License:           MIT
 * Text Domain:       wp-comment-reply-notify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCRN_VERSION', '1.0.0' );
define( 'WCRN_META_SUBSCRIBE', '_wcrn_subscribed' );
define( 'WCRN_META_UNSUB_KEY', '_wcrn_unsub_key' );

/**
 * Add an opt-in checkbox to the comment form (logged-out visitors only;
 * logged-in users are subscribed by default and can opt out per comment).
 */
function wcrn_comment_form_field() {
	$checked = is_user_logged_in() ? ' checked' : '';
	echo '<p class="comment-form-notify-me"><label><input type="checkbox" name="wcrn_notify" value="1"' . $checked . ' /> '
		. esc_html__( 'Notify me when someone replies to my comment.', 'wp-comment-reply-notify' )
		. '</label></p>';
}
add_action( 'comment_form_logged_in_after', 'wcrn_comment_form_field' );
add_action( 'comment_form_after_fields', 'wcrn_comment_form_field' );

/**
 * Record the subscription choice when a comment is saved.
 *
 * @param int $comment_id New comment ID.
 */
function wcrn_save_subscription( $comment_id ) {
	$wants = isset( $_POST['wcrn_notify'] ) && '1' === $_POST['wcrn_notify']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! $wants ) {
		return;
	}
	$comment = get_comment( $comment_id );
	if ( ! $comment || empty( $comment->comment_author_email ) ) {
		return;
	}
	update_comment_meta( $comment_id, WCRN_META_SUBSCRIBE, '1' );
	// A random unsubscribe key, so the link in the email cannot be forged
	// by guessing a comment ID.
	update_comment_meta( $comment_id, WCRN_META_UNSUB_KEY, wp_generate_password( 24, false ) );
}
add_action( 'comment_post', 'wcrn_save_subscription' );

/**
 * Build the one-click unsubscribe URL for a comment.
 *
 * @param int $comment_id Comment ID.
 * @return string
 */
function wcrn_unsubscribe_url( $comment_id ) {
	$key = get_comment_meta( $comment_id, WCRN_META_UNSUB_KEY, true );
	return add_query_arg(
		array(
			'wcrn_unsubscribe' => $comment_id,
			'wcrn_key'         => $key,
		),
		home_url( '/' )
	);
}

/**
 * Handle unsubscribe clicks before anything is rendered.
 */
function wcrn_handle_unsubscribe() {
	if ( ! isset( $_GET['wcrn_unsubscribe'], $_GET['wcrn_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$comment_id = absint( $_GET['wcrn_unsubscribe'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$key        = sanitize_text_field( wp_unslash( $_GET['wcrn_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$stored     = get_comment_meta( $comment_id, WCRN_META_UNSUB_KEY, true );
	if ( '' !== $stored && hash_equals( $stored, $key ) ) {
		delete_comment_meta( $comment_id, WCRN_META_SUBSCRIBE );
		delete_comment_meta( $comment_id, WCRN_META_UNSUB_KEY );
		wp_die(
			esc_html__( 'You have been unsubscribed from replies to this comment.', 'wp-comment-reply-notify' ),
			esc_html__( 'Unsubscribed', 'wp-comment-reply-notify' ),
			array( 'response' => 200 )
		);
	}
}
add_action( 'init', 'wcrn_handle_unsubscribe' );

/**
 * When a reply is approved, email the parent comment's author if subscribed.
 *
 * @param string     $new_status New comment status.
 * @param string     $old_status Old comment status.
 * @param WP_Comment $comment    The reply comment.
 */
function wcrn_notify_on_approval( $new_status, $old_status, $comment ) {
	if ( 'approved' !== $new_status || 'approved' === $old_status ) {
		return; // Only notify on the transition into approved, not on edits.
	}
	if ( empty( $comment->comment_parent ) ) {
		return; // Top-level comments have nobody to notify.
	}
	$parent = get_comment( $comment->comment_parent );
	if ( ! $parent || '1' !== get_comment_meta( $parent->comment_ID, WCRN_META_SUBSCRIBE, true ) ) {
		return;
	}
	if ( empty( $parent->comment_author_email ) ) {
		return;
	}
	// Never email someone about their own reply.
	if ( $parent->comment_author_email === $comment->comment_author_email ) {
		return;
	}
	$post    = get_post( $comment->comment_post_ID );
	$subject = sprintf(
		/* translators: %s: post title. */
		__( 'Someone replied to your comment on "%s"', 'wp-comment-reply-notify' ),
		$post ? $post->post_title : get_bloginfo( 'name' )
	);
	$message = sprintf(
		/* translators: 1: parent commenter, 2: replier, 3: post title, 4: reply text, 5: conversation link, 6: unsubscribe link. */
		__( "Hi %1\$s,\n\n%2\$s replied to your comment on \"%3\$s\":\n\n%4\$s\n\nView the conversation: %5\$s\n\nUnsubscribe from replies to this comment: %6\$s", 'wp-comment-reply-notify' ),
		$parent->comment_author,
		$comment->comment_author,
		$post ? $post->post_title : '',
		wp_strip_all_tags( $comment->comment_content ),
		get_comment_link( $comment ),
		wcrn_unsubscribe_url( $parent->comment_ID )
	);
	wp_mail( $parent->comment_author_email, $subject, $message );
}
add_action( 'transition_comment_status', 'wcrn_notify_on_approval', 10, 3 );
