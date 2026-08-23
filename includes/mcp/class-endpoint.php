<?php
/**
 * MCP JSON-RPC endpoint.
 *
 * @since 1.0.0
 * @package QuillForms
 * @subpackage MCP
 */

namespace QuillForms\MCP;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Implements the Model Context Protocol over a single REST route.
 *
 * Speaks JSON-RPC 2.0: initialize, notifications/initialized, ping, tools/list
 * and tools/call. Tools are the registered quillforms/* abilities, so the MCP
 * surface and the REST surface can never drift apart.
 *
 * Authentication is ordinary WordPress authentication — Application Passwords
 * over Basic auth is what the @automattic/mcp-wordpress-remote bridge sends.
 * Authorization is enforced per tool by each ability's permission callback.
 *
 * @since 1.0.0
 */
final class Endpoint {

	/**
	 * JSON-RPC: method not found.
	 *
	 * @since 1.0.0
	 */
	private const METHOD_NOT_FOUND = -32601;

	/**
	 * JSON-RPC: invalid params.
	 *
	 * @since 1.0.0
	 */
	private const INVALID_PARAMS = -32602;

	/**
	 * Register the route.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			Server::REST_NAMESPACE,
			'/' . Server::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'handle' ),
					// Authorization is per-tool, via each ability's own
					// permission callback. Returning true here only allows the
					// handshake; it grants access to no data on its own.
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Handle one JSON-RPC request.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$id     = $body['id'] ?? null;
		$method = isset( $body['method'] ) ? (string) $body['method'] : '';
		$params = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();

		// Notifications carry no id and must not receive a response body.
		$is_notification = ! array_key_exists( 'id', $body ) || null === $id;

		switch ( $method ) {
			case 'initialize':
				return self::result( $id, self::initialize() );

			case 'notifications/initialized':
			case 'notifications/cancelled':
				return new WP_REST_Response( null, 202 );

			case 'ping':
				return self::result( $id, (object) array() );

			case 'tools/list':
				return self::result( $id, array( 'tools' => self::list_tools() ) );

			case 'tools/call':
				return self::call_tool( $id, $params );
		}

		if ( $is_notification ) {
			return new WP_REST_Response( null, 202 );
		}

		return self::error( $id, self::METHOD_NOT_FOUND, sprintf( 'Unknown method: %s', $method ) );
	}

	/**
	 * Build the initialize result.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function initialize() {
		return array(
			'protocolVersion' => Server::PROTOCOL_VERSION,
			'capabilities'    => array(
				'tools' => (object) array(),
			),
			'serverInfo'      => array(
				'name'    => 'Quill Forms',
				'version' => defined( 'QUILLFORMS_VERSION' ) ? QUILLFORMS_VERSION : '1.0.0',
			),
		);
	}

	/**
	 * Map registered abilities to MCP tool descriptors.
	 *
	 * Tools the current user may not run are omitted rather than advertised
	 * and then refused: an agent should not plan around a tool it cannot call.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function list_tools() {
		$tools = array();

		foreach ( Server::tool_names() as $name ) {
			$ability = wp_get_ability( $name );
			if ( ! $ability ) {
				continue;
			}

			// check_permissions() returns true, false or WP_Error; only a
			// strict true means the caller may run this tool. Abilities whose
			// permission callback expects input are skipped rather than
			// probed with a fabricated payload.
			if ( true !== $ability->check_permissions() ) {
				continue;
			}

			$input_schema = (array) $ability->get_input_schema();

			if ( empty( $input_schema ) ) {
				$input_schema = array(
					'type'       => 'object',
					'properties' => (object) array(),
				);
			}

			$tool = array(
				'name'        => self::tool_name( $name ),
				'description' => (string) $ability->get_description(),
				'inputSchema' => $input_schema,
			);

			$annotations = method_exists( $ability, 'get_meta_item' )
				? $ability->get_meta_item( 'annotations' )
				: null;

			if ( is_array( $annotations ) ) {
				$tool['annotations'] = array(
					'title'           => (string) $ability->get_label(),
					'readOnlyHint'    => (bool) ( $annotations['readOnlyHint'] ?? $annotations['readonly'] ?? true ),
					'destructiveHint' => (bool) ( $annotations['destructiveHint'] ?? $annotations['destructive'] ?? false ),
					'idempotentHint'  => (bool) ( $annotations['idempotentHint'] ?? $annotations['idempotent'] ?? true ),
					'openWorldHint'   => false,
				);
			}

			$tools[] = $tool;
		}

		return $tools;
	}

	/**
	 * Execute a tool call.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $id     Request id.
	 * @param array $params Params.
	 * @return WP_REST_Response
	 */
	private static function call_tool( $id, array $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		if ( '' === $name ) {
			return self::error( $id, self::INVALID_PARAMS, 'A tool name is required.' );
		}

		$ability_name = self::ability_name( $name );
		if ( ! in_array( $ability_name, Server::tool_names(), true ) ) {
			return self::error( $id, self::METHOD_NOT_FOUND, sprintf( 'Unknown tool: %s', $name ) );
		}

		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			return self::error( $id, self::METHOD_NOT_FOUND, sprintf( 'Unknown tool: %s', $name ) );
		}

		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] )
			? $params['arguments']
			: array();

		// An ability registered without an input schema takes no argument.
		$has_schema = method_exists( $ability, 'get_input_schema' )
			&& ! empty( (array) $ability->get_input_schema() );

		$result = $has_schema ? $ability->execute( $arguments ) : $ability->execute();

		if ( is_wp_error( $result ) ) {
			return self::tool_error( $id, $result );
		}

		return self::result(
			$id,
			array(
				'content'           => array(
					array(
						'type' => 'text',
						'text' => self::stringify( $result ),
					),
				),
				'structuredContent' => is_array( $result ) ? $result : array( 'value' => $result ),
				'isError'           => false,
			)
		);
	}

	/**
	 * Return a failed tool call as an MCP tool error.
	 *
	 * Per the MCP spec a tool that fails reports isError on a successful
	 * JSON-RPC response, so the model can read the message and adapt, rather
	 * than the transport treating it as a protocol failure.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed    $id    Request id.
	 * @param WP_Error $error Error.
	 * @return WP_REST_Response
	 */
	private static function tool_error( $id, WP_Error $error ) {
		return self::result(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => sprintf( '%s: %s', $error->get_error_code(), $error->get_error_message() ),
					),
				),
				'isError' => true,
			)
		);
	}

	/**
	 * Convert an ability result into text for the model.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $result Result.
	 * @return string
	 */
	private static function stringify( $result ) {
		if ( is_string( $result ) ) {
			return $result;
		}

		$json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}

	/**
	 * Convert an ability name to an MCP tool name.
	 *
	 * Slashes are legal in MCP tool names but several clients dislike them, so
	 * quillforms/list-forms is published as quillforms_list_forms.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ability_name Ability name.
	 * @return string
	 */
	private static function tool_name( $ability_name ) {
		return str_replace( array( '/', '-' ), '_', $ability_name );
	}

	/**
	 * Resolve an incoming tool name back to an ability name.
	 *
	 * Accepts both the published underscored form and the raw ability name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tool_name Tool name.
	 * @return string
	 */
	private static function ability_name( $tool_name ) {
		if ( false !== strpos( $tool_name, '/' ) ) {
			return $tool_name;
		}

		foreach ( Server::tool_names() as $name ) {
			if ( self::tool_name( $name ) === $tool_name ) {
				return $name;
			}
		}

		return $tool_name;
	}

	/**
	 * Build a JSON-RPC success response.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $id     Request id.
	 * @param mixed $result Result.
	 * @return WP_REST_Response
	 */
	private static function result( $id, $result ) {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Build a JSON-RPC error response.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $id      Request id.
	 * @param int    $code    Error code.
	 * @param string $message Message.
	 * @return WP_REST_Response
	 */
	private static function error( $id, $code, $message ) {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => (int) $code,
					'message' => (string) $message,
				),
			),
			200
		);
	}
}
