<?php
/**
 * Optional MCP Adapter registration.
 *
 * @since 1.0.0
 * @package QuillForms
 * @subpackage MCP
 */

namespace QuillForms\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Publishes our abilities through the WordPress MCP Adapter when one is present.
 *
 * This is a bonus access path, never a dependency. Our own endpoint in
 * {@see Endpoint} keeps working with or without an adapter, so deactivating the
 * plugin that ships one cannot take this integration down.
 *
 * Registering a named server with an explicit ability list means our abilities
 * do not auto-enroll on the adapter's default server.
 *
 * @since 1.0.0
 */
final class Adapter_Server {

	/**
	 * Server id.
	 *
	 * @since 1.0.0
	 */
	private const SERVER_ID = 'quillforms-mcp-server';

	/**
	 * Route namespace.
	 *
	 * @since 1.0.0
	 */
	private const NAMESPACE_ROUTE = 'quillforms-mcp/v1';

	/**
	 * Route.
	 *
	 * @since 1.0.0
	 */
	private const ROUTE = 'mcp';

	/**
	 * Hook the adapter's init action.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'mcp_adapter_init', array( self::class, 'register' ) );
	}

	/**
	 * Register a named server with the adapter.
	 *
	 * Silent no-op if no adapter is installed or it has not loaded yet.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return;
		}
		if ( ! class_exists( '\WP\MCP\Transport\HttpTransport' ) ) {
			return;
		}

		$tools = Server::tool_names();
		if ( empty( $tools ) ) {
			return;
		}

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		if ( ! $adapter || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$adapter->create_server(
			self::SERVER_ID,
			self::NAMESPACE_ROUTE,
			self::ROUTE,
			'Quill Forms',
			'Quill Forms — list, read, create and edit forms, and read form responses. Same abilities as the native /wp-json/quillforms/v1/mcp endpoint; the adapter transport is an additional access path, not a replacement.',
			defined( 'QUILLFORMS_VERSION' ) ? QUILLFORMS_VERSION : '1.0.0',
			array( \WP\MCP\Transport\HttpTransport::class ),
			null,
			null,
			$tools
		);
	}
}
