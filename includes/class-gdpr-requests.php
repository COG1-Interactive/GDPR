<?php

/**
 * The requests functionality of the plugin.
 *
 * @link       https://trewknowledge.com
 * @since      1.0.0
 *
 * @package    GDPR
 * @subpackage GDPR/admin
 */

/**
 * The requests functionality of the plugin.
 *
 * @package    GDPR
 * @subpackage GDPR/admin
 * @author     Fernando Claussen <fernandoclaussen@gmail.com>
 */
class GDPR_Requests {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	protected static $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of this plugin.
	 */
	protected static $version;

	protected static $allowed_types = array( 'access', 'rectify', 'portability', 'complaint', 'delete' );


	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		self::$plugin_name = $plugin_name;
		self::$version     = $version;

	}

	protected function get_allowed_types() {
    return self::$allowed_types;
  }

	static function user_has_content( $user ) {
		if ( ! $user instanceof WP_User ) {
			if ( ! is_int( $user ) ) {
				return;
			}
			$user = get_user_by( 'ID', $user );
		}

		$post_types = get_post_types( array( 'public' => true ) );
		foreach ( $post_types as $pt ) {
			$post_count = count_user_posts( $user->ID, $pt);
			if ( $post_count > 0 ) {
				return true;
			}
		}

		$comments = get_comments( array(
			'author_email' => $user->user_email,
			'include_unapproved' => true,
			'number' => 1,
			'count' => true,
		) );

		if ( $comments ) {
			return true;
		}

		$extra_checks = apply_filters( 'gdpr_user_has_content', false );

		return $extra_checks;
	}

	protected function remove_from_requests( $index ) {
		$requests = ( array ) get_option( 'gdpr_requests', array() );
		$index = sanitize_text_field( wp_unslash( $index ) );

		if ( array_key_exists( $index, $requests ) ) {
			unset( $requests[ $index ] );
			update_option( 'gdpr_requests', $requests );
			return true;
		}

		return false;
	}

	protected function confirm_request( $key ) {
		$key = sanitize_text_field( wp_unslash( $key ) );
		$requests = ( array ) get_option( 'gdpr_requests', array() );

		if ( empty( $requests ) || ! isset( $requests[ $key ] ) ) {
			return false;
		}

		$requests[ $key ]['confirmed'] = true;
		$type = $requests[ $key ]['type'];
		$email = $requests[ $key ]['email'];

		$user = get_user_by( 'email', $email );

		if ( $user instanceof WP_User ) {
			$meta_key = self::$plugin_name . '_' . $type . '_key';
			delete_user_meta( $user->ID, $meta_key );
			if ( $time = wp_next_scheduled( 'clean_gdpr_user_request_key', array( 'user_id' => $user->ID, 'meta_key' => $meta_key ) ) ) {
				wp_unschedule_event( $time, 'clean_gdpr_user_request_key', array( 'user_id' => $user->ID, 'meta_key' => $meta_key ) );
			}
		}

		return true;
	}

	function clean_requests( $key ) {
		$key = sanitize_text_field( $key );
		$requests = ( array ) get_option( 'gdpr_requests', array() );

		if ( array_key_exists( $key, $requests ) ) {
			if ( ! $requests[ $key ]['confirmed'] ) {
				unset( $requests[ $key ] );
				update_option( 'gdpr_requests', $requests );
			}
		}
	}

	function clean_user_request_key( $user_id, $meta_key ) {
		$user_id = ( int ) $user_id;
		$meta_key = sanitize_text_field( $meta_key );

		$meta = get_user_meta( $user_id, $meta_key, true );

		if ( $meta ) {
			delete_user_meta( $user_id, $meta_key );
		}
	}

	protected function add_to_requests( $email, $type, $data = null, $confirmed = false ) {
		$requests = ( array ) get_option( 'gdpr_requests', array() );

		$email = sanitize_email( $email );
		$type = sanitize_text_field( wp_unslash( $type ) );
		$data = sanitize_textarea_field( $data );

		if ( ! in_array( $type, self::$allowed_types ) ) {
			return false;
		}

		$key = wp_generate_password( 20, false );
		$requests[ $key ] = array(
			'email'     => $email,
			'date'      => date( "F j, Y" ),
			'type'      => $type,
			'data'      => $data,
			'confirmed' => $confirmed
		);

		/**
		 * Remove user from the requests if it did not confirm in 2 days.
		 */
		$user = get_user_by( 'email', $email );
		if ( $user instanceof WP_User ) {
			$meta_key = self::$plugin_name . '_' . $type . '_key';
			update_user_meta( $user->ID, $meta_key, $key );
			if ( $time = wp_next_scheduled( 'clean_gdpr_user_request_key', array( 'user_id' => $user->ID, 'meta_key' => $meta_key ) ) ) {
				wp_unschedule_event( $time, 'clean_gdpr_user_request_key', array( 'user_id' => $user->ID, 'meta_key' => $meta_key ) );
			}
			wp_schedule_single_event( time() + 2 * DAY_IN_SECONDS, 'clean_gdpr_user_request_key', array( 'user_id' => $user->ID, 'meta_key' => $meta_key ) );
		}

		update_option( 'gdpr_requests', $requests );

		return $key;
	}

}
