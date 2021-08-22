<?php
/**
 * Class Log_Handler_DB file.
 *
 * @package QuillForms
 * @subpackage Log_Handlers
 *
 * @since 1.0.0
 */

namespace QuillForms\Log_Handlers;

use Automattic\Jetpack\Constants;
use QuillForms\Abstracts\Log_Handler;
use QuillForms\Abstracts\Log_Levels;

/**
 * Handles log entries by writing to database.
 *
 * @class          Log_Handler_DB
 *
 * @since        1.0.0
 */
class Log_Handler_DB extends Log_Handler {

	/**
	 * Handle a log entry.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $timestamp Log timestamp.
	 * @param string $level emergency|alert|critical|error|warning|notice|info|debug.
	 * @param string $message Log message.
	 * @param array  $context {
	 *      Additional information for log handlers.
	 *
	 *     @type string $source Optional. Source will be available in log table.
	 *                  If no source is provided, attempt to provide sensible default.
	 * }
	 *
	 * @see Log_Handler_DB::get_log_source() for default source.
	 *
	 * @return bool False if value was not handled and true if value was handled.
	 */
	public function handle( $timestamp, $level, $message, $context ) {

		if ( isset( $context['source'] ) && $context['source'] ) {
			$source = $context['source'];
		} else {
			$source = $this->get_log_source();
		}

		return $this->add( $timestamp, $level, $message, $source, $context );
	}

	/**
	 * Add a log entry to chosen file.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $timestamp Log timestamp.
	 * @param string $level emergency|alert|critical|error|warning|notice|info|debug.
	 * @param string $message Log message.
	 * @param string $source Log source. Useful for filtering and sorting.
	 * @param array  $context Context will be serialized and stored in database.
	 *
	 * @return bool True if write was successful.
	 */
	protected static function add( $timestamp, $level, $message, $source, $context ) {
		global $wpdb;

		$insert = array(
			'timestamp' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			'level'     => Log_Levels::get_level_severity( $level ),
			'message'   => $message,
			'source'    => $source,
		);

		$format = array(
			'%s',
			'%d',
			'%s',
			'%s',
			'%s', // possible serialized context.
		);

		if ( ! empty( $context ) ) {
			$insert['context'] = serialize( $context ); // @codingStandardsIgnoreLine.
		}

		return false !== $wpdb->insert( "{$wpdb->prefix}quillforms_log", $insert, $format );
	}

	/**
	 * Clear all logs from the DB.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if flush was successful.
	 */
	public static function flush() {
		global $wpdb;

		return $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}quillforms_log" );
	}

	/**
	 * Clear entries for a chosen handle/source.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source Log source.
	 * @return bool
	 */
	public function clear( $source ) {
		global $wpdb;

		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}quillforms_log WHERE source = %s",
				$source
			)
		);
	}

	/**
	 * Delete selected logs from DB.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string|array $log_ids Log ID or array of Log IDs to be deleted.
	 *
	 * @return bool
	 */
	public static function delete( $log_ids ) {
		global $wpdb;

		if ( ! is_array( $log_ids ) ) {
			$log_ids = array( $log_ids );
		}

		$format   = array_fill( 0, count( $log_ids ), '%d' );
		$query_in = '(' . implode( ',', $format ) . ')';
		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}quillforms_log WHERE log_id IN {$query_in}", $log_ids ) ); // @codingStandardsIgnoreLine.
	}

	/**
	 * Delete all logs older than a defined timestamp.
	 *
	 * @since 1.0.0
	 *
	 * @param integer $timestamp Timestamp to delete logs before.
	 */
	public static function delete_logs_before_timestamp( $timestamp = 0 ) {
		if ( ! $timestamp ) {
			return;
		}

		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}quillforms_log WHERE timestamp < %s",
				gmdate( 'Y-m-d H:i:s', $timestamp )
			)
		);
	}

	/**
	 * Get appropriate source based on file name.
	 *
	 * Try to provide an appropriate source in case none is provided.
	 *
	 * @since 1.0.0
	 *
	 * @return string Text to use as log source. "" (empty string) if none is found.
	 */
	protected static function get_log_source() {
		static $ignore_classes = array( 'QuillForms\Log_Handlers\Log_Handler_DB', 'QuillForms\Logger' );

		/**
		 * PHP < 5.3.6 correct behavior
		 *
		 * @see http://php.net/manual/en/function.debug-backtrace.php#refsect1-function.debug-backtrace-parameters
		 */
		if ( Constants::is_defined( 'DEBUG_BACKTRACE_IGNORE_ARGS' ) ) {
			$debug_backtrace_arg = DEBUG_BACKTRACE_IGNORE_ARGS; // phpcs:ignore PHPCompatibility.Constants.NewConstants.debug_backtrace_ignore_argsFound
		} else {
			$debug_backtrace_arg = false;
		}

		$trace = debug_backtrace( $debug_backtrace_arg ); // @codingStandardsIgnoreLine.
		foreach ( $trace as $t ) {
			if ( isset( $t['class'] ) ) {
				if ( in_array( $t['class'], $ignore_classes, true ) ) {
					continue;
				}
				return $t['class'] . $t['type'] . $t['function'];
			}
			if ( isset( $t['file'] ) ) {
				return static::clean_filename( $t['file'] );
			}
		}

		return '';
	}

	/**
	 * Clean filename
	 *
	 * @param string $filename Full path of file.
	 * @return string
	 */
	protected static function clean_filename( $filename ) {
		if ( substr( $filename, 0, strlen( ABSPATH ) ) === ABSPATH ) {
			$filename = substr( $filename, strlen( ABSPATH ) );
		}
		return $filename;
	}

}
