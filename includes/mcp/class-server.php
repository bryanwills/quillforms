<?php
/**
 * MCP server state.
 *
 * @since 1.0.0
 * @package QuillForms
 * @subpackage MCP
 */

namespace QuillForms\MCP;

use QuillForms\MCP\Abilities\Registrar;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the route wiring and the list of tools we publish.
 *
 * The protocol itself lives in Endpoint and is implemented in this plugin.
 * We deliberately depend on NO third-party MCP adapter: a vendor plugin being
 * deactivated, updated or dropped must never take this integration down.
 *
 * @since 1.0.0
 */
final class Server {

	/**
	 * REST namespace for the MCP endpoint.
	 *
	 * @since 1.0.0
	 */
	public const REST_NAMESPACE = 'quillforms/v1';

	/**
	 * Route.
	 *
	 * @since 1.0.0
	 */
	public const ROUTE = 'mcp';

	/**
	 * Protocol version we implement.
	 *
	 * @since 1.0.0
	 */
	public const PROTOCOL_VERSION = '2024-11-05';

	/**
	 * Wire the endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( Endpoint::class, 'register_routes' ) );
	}

	/**
	 * Public endpoint URL clients connect to.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( self::REST_NAMESPACE . '/' . self::ROUTE );
	}

	/**
	 * Ability names this endpoint publishes.
	 *
	 * Reads the live registry, so an ability that was never registered — for
	 * instance a write tool while updates are switched off — contributes
	 * nothing here either.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function tool_names() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			$name = is_object( $ability ) && method_exists( $ability, 'get_name' )
				? (string) $ability->get_name()
				: '';

			if ( '' !== $name && 0 === strpos( $name, Registrar::NAMESPACE_PREFIX ) ) {
				$names[] = $name;
			}
		}

		return array_values( array_unique( $names ) );
	}
}
