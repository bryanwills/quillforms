<?php
/**
 * Ability registrar.
 *
 * @since 1.0.0
 * @package QuillForms
 * @subpackage MCP
 */

namespace QuillForms\MCP\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Turns plain definition arrays into registered WP abilities.
 *
 * Every ability goes through here so the meta contract is applied in exactly
 * one place. Never call wp_register_ability() directly from a definition file.
 *
 * @since 1.0.0
 */
final class Registrar {

	/**
	 * Ability name prefix.
	 *
	 * @since 1.0.0
	 */
	public const NAMESPACE_PREFIX = 'quillforms/';

	/**
	 * Register a set of definitions.
	 *
	 * @since 1.0.0
	 *
	 * @param array $definitions Name => definition.
	 * @return void
	 */
	public static function register_definitions( array $definitions ) {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( $definitions as $name => $definition ) {
			if ( is_string( $name ) && is_array( $definition ) ) {
				self::register_one( $name, $definition );
			}
		}
	}

	/**
	 * Register a single ability.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name       Ability name, including the namespace prefix.
	 * @param array  $definition Definition.
	 * @return void
	 */
	private static function register_one( $name, array $definition ) {
		if ( empty( $definition['label'] ) || empty( $definition['description'] ) ) {
			self::doing_it_wrong( $name, 'missing label or description' );
			return;
		}
		if ( empty( $definition['execute_callback'] ) || ! is_callable( $definition['execute_callback'] ) ) {
			self::doing_it_wrong( $name, 'missing or non-callable execute_callback' );
			return;
		}
		if ( empty( $definition['permission_callback'] ) || ! is_callable( $definition['permission_callback'] ) ) {
			self::doing_it_wrong( $name, 'missing or non-callable permission_callback' );
			return;
		}

		$annotations = self::build_annotations( $definition );

		$args = array(
			'label'               => $definition['label'],
			'description'         => $definition['description'],
			'category'            => Categories::SLUG,
			'execute_callback'    => $definition['execute_callback'],
			'permission_callback' => $definition['permission_callback'],
			'meta'                => array(
				'annotations'  => $annotations,
				// Required. WordPress 7.0.x defaults show_in_rest to false and
				// has no `public` meta key, so omitting this would register an
				// ability that is unreachable over REST.
				'show_in_rest' => true,
				// Consumed by MCP adapter plugins that scan for tools.
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		);

		// WordPress treats the presence of an input schema as "input is
		// expected", and passes ZERO arguments to both callbacks when none is
		// registered. Only attach a schema when the ability actually takes one.
		if ( ! empty( $definition['input_schema']['properties'] ) ) {
			$args['input_schema'] = $definition['input_schema'];
		}
		if ( ! empty( $definition['output_schema'] ) && is_array( $definition['output_schema'] ) ) {
			$args['output_schema'] = $definition['output_schema'];
		}

		wp_register_ability( $name, $args );
	}

	/**
	 * Build the annotation set for an ability.
	 *
	 * Read-only is the DEFAULT, not a ceiling: an author who omits annotations
	 * gets the safe value, and a write ability opts out deliberately. These
	 * annotations are what tell an agent whether a call is safe to make and
	 * safe to retry, so a mutating ability that inherited `readonly: true`
	 * would be actively dangerous.
	 *
	 * Both spellings are emitted: WordPress core (and its JS consumer) reads
	 * lowercase `readonly`/`destructive`/`idempotent`, while the MCP spec and
	 * adapter plugins read camelCase `readOnlyHint` and friends.
	 *
	 * @since 1.0.0
	 *
	 * @param array $definition Definition.
	 * @return array
	 */
	private static function build_annotations( array $definition ) {
		$supplied = isset( $definition['annotations'] ) && is_array( $definition['annotations'] )
			? $definition['annotations']
			: array();

		$annotations = array_merge(
			array(
				'readonly'   => true,
				'destructive' => false,
				'idempotent' => true,
			),
			$supplied
		);

		$annotations['readOnlyHint']    = $annotations['readonly'];
		$annotations['destructiveHint'] = $annotations['destructive'];
		$annotations['idempotentHint']  = $annotations['idempotent'];
		$annotations['openWorldHint']   = false;

		return $annotations;
	}

	/**
	 * Surface an authoring mistake without breaking the request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name   Ability name.
	 * @param string $reason Reason.
	 * @return void
	 */
	private static function doing_it_wrong( $name, $reason ) {
		_doing_it_wrong(
			__METHOD__,
			esc_html( sprintf( 'Quill Forms MCP ability "%s" was not registered: %s.', $name, $reason ) ),
			'1.0.0'
		);
	}
}
