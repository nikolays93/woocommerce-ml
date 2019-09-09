<?php


namespace NikolayS93\Exchange;


class Error {
	/**
	 * @param string $message
	 * @param string $type
	 * @param bool $no_exit
	 */
	public static function set_message( $message, $type = "Error", $no_exit = null ) {
		if ( $message instanceof \WP_Error ) {
			static::set_wp_error( $message, null, 'WP Error', $no_exit );

			return;
		}

		if ( null === $no_exit && ( 'Warning' == $type || 'Notice' == $type ) ) {
			$no_exit = true;
		}

		// failure\n think about
		$message   = "$type: $message";
		$last_char = substr( $message, - 1 );
		if ( ! in_array( $last_char, array( '.', '!', '?' ) ) ) {
			$message .= '.';
		}

		error_log( $message );
		echo "$message\n";

		if ( is_debug() ) {
			echo "\n";
			debug_print_backtrace();

			$arInfo = array(
				"Request URI"       => Request::get_full_request_uri(),
				"Server API"        => PHP_SAPI,
				"Memory limit"      => ini_get( 'memory_limit' ),
				"Maximum POST size" => ini_get( 'post_max_size' ),
				"PHP version"       => PHP_VERSION,
				"WordPress version" => get_bloginfo( 'version' ),
				"Plugin version"    => Plugin::VERSION,
			);
			echo "\n";
			foreach ( $arInfo as $info_name => $info_value ) {
				echo "$info_name: $info_value\n";
			}
		}

		if ( ! $no_exit ) {
			Transaction()->wpdb_stop();
			exit;
		}
	}

	/**
	 * @param \WP_Error $wp_error
	 * @param null $only_error_code
	 */
	static function set_wp_error( $wp_error, $only_error_code = null, $type = "WP Error", $no_exit = false ) {
		$messages = array();
		foreach ( $wp_error->get_error_codes() as $error_code ) {
			if ( $only_error_code && $error_code != $only_error_code ) {
				continue;
			}

			$wp_error_messages = implode( ", ", $wp_error->get_error_messages( $error_code ) );
			$wp_error_messages = strip_tags( $wp_error_messages );
			$messages[]        = sprintf( "%s: %s", $error_code, $wp_error_messages );
		}

		static::set_message( implode( "; ", $messages ), $type, $no_exit );
	}

	/**
	 * Check error from global database manager
	 */
	public static function check_wpdb_error() {
		global $wpdb;

		if ( ! $wpdb->last_error ) {
			return;
		}

		self::set_message( sprintf(
			"%s for query \"%s\"",
			$wpdb->last_error,
			$wpdb->last_query
		), "DB Error", true );

		Transaction()->wpdb_stop( false, true );
		exit;
	}

	/**
	 * Except all notices\warnings\errors
	 */
	public static function set_strict_mode() {
		set_error_handler( array( __CLASS__, 'strict_error_handler' ) );
		set_exception_handler( array( __CLASS__, 'strict_exception_handler' ) );
	}

	static function strict_error_handler( $errno, $errstr, $errfile, $errline, $errcontext ) {
		if ( 0 === error_reporting() ) {
			return false;
		}

		switch ( $errno ) {
			case E_NOTICE:
			case E_USER_NOTICE:
				$type = "Notice";
				break;
			case E_WARNING:
			case E_USER_WARNING:
				$type = "Warning";
				break;
			case E_ERROR:
			case E_USER_ERROR:
				$type = "Fatal Error";
				break;
			default:
				$type = "Unknown Error";
				break;
		}

		$message = sprintf( "%s in %s on line %d", $errstr, $errfile, $errline );
		static::set_message( $message, "PHP $type" );

		return true;
	}

	/**
	 * @param \Exception $exception
	 */
	static function strict_exception_handler( $exception ) {
		$message = sprintf(
			"%s in %s on line %d",
			$exception->getMessage(),
			$exception->getFile(),
			$exception->getLine()
		);

		static::set_message( $message, "Exception" );
	}
}