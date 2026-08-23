<?php
/**
 * Shared JSON Schema fragments and permission helpers.
 *
 * @since 1.0.0
 * @package QuillForms
 * @subpackage MCP
 */

namespace QuillForms\MCP\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Reusable schema pieces.
 *
 * @since 1.0.0
 */
final class Schemas {

	/**
	 * Capability required to read or manage forms.
	 *
	 * Matches what the Quill Forms REST controllers themselves check, so the
	 * MCP surface can never grant more than the admin UI would.
	 *
	 * @since 1.0.0
	 */
	public const CAPABILITY = 'manage_quillforms';

	/**
	 * Permission callback shared by every ability.
	 *
	 * WordPress compares the result with a strict `true !==`, so this must
	 * return a real boolean rather than a truthy value.
	 *
	 * @since 1.0.0
	 *
	 * @return callable
	 */
	public static function permission() {
		return static function () {
			return (bool) current_user_can( self::CAPABILITY );
		};
	}

	/**
	 * A required form_id property.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function form_id_property() {
		return array(
			'type'        => 'integer',
			'description' => __( 'The Quill Forms form ID.', 'quillforms' ),
		);
	}

	/**
	 * Input schema taking only a form_id.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function form_id_input() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'form_id' => self::form_id_property(),
			),
			'required'             => array( 'form_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * The blocks payload, described as a JSON string.
	 *
	 * Block attributes are deeply nested, heterogeneous across block types and
	 * extensible by addons, so a literal schema would be both enormous and
	 * stale the moment an addon registers a new attribute.
	 *
	 * @since 1.0.0
	 *
	 * @param string $description Description.
	 * @return array
	 */
	public static function json_string( $description ) {
		return array(
			'type'        => 'string',
			'description' => $description,
		);
	}

	/**
	 * Schema for a caller-supplied blocks array.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function blocks_input_property() {
		return array(
			'type'        => 'array',
			'description' => __( 'Ordered list of form blocks. Each item needs a "name" (see quillforms/list-block-types) and an "attributes" object; "id" is generated when omitted.', 'quillforms' ),
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'         => array(
						'type'        => 'string',
						'description' => __( 'Stable block id. Omit to have one generated.', 'quillforms' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'Block type slug, e.g. short-text, email, multiple-choice.', 'quillforms' ),
					),
					'attributes' => array(
						'type'        => 'object',
						'description' => __( 'Block attributes, e.g. label, required, description, choices.', 'quillforms' ),
					),
				),
			),
		);
	}

	/**
	 * Output schema returning a form summary object.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function form_output() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'              => array( 'type' => 'integer' ),
				'title'           => array( 'type' => 'string' ),
				'status'          => array( 'type' => 'string' ),
				'blocks'          => self::json_string( __( 'Form blocks as a JSON string.', 'quillforms' ) ),
				'messages'        => self::json_string( __( 'Form messages as a JSON string.', 'quillforms' ) ),
				'notifications'   => self::json_string( __( 'Email notifications as a JSON string.', 'quillforms' ) ),
				'settings'        => self::json_string( __( 'Form settings as a JSON string.', 'quillforms' ) ),
				'theme_id'        => array( 'type' => 'integer' ),
				'responses_count' => array( 'type' => 'integer' ),
				'shortcode'       => array( 'type' => 'string' ),
				'edit_url'        => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Output schema for the block-mutation abilities.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function blocks_output() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'form_id'  => array( 'type' => 'integer' ),
				'block_id' => array( 'type' => 'string' ),
				'deleted'  => array( 'type' => 'string' ),
				'blocks'   => self::json_string( __( 'Resulting blocks as a JSON string.', 'quillforms' ) ),
			),
		);
	}
}
