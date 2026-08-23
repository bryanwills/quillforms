<?php
/**
 * Form ability definitions.
 *
 * @since 1.0.0
 * @package QuillForms
 * @subpackage MCP
 */

namespace QuillForms\MCP\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the form-facing abilities.
 *
 * Definitions only — registration and the meta contract live in Registrar.
 *
 * @since 1.0.0
 */
final class Form_Abilities {

	/**
	 * Read abilities, always registered when the addon is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get_read_definitions() {
		$permission = Schemas::permission();

		return array(
			'quillforms/list-forms'       => array(
				'label'               => __( 'List Quill Forms forms', 'quillforms' ),
				'description'         => __( 'Lists Quill Forms forms with their id, title, status, number of responses and shortcode. Use this first to find the ID of a form the user refers to by name.', 'quillforms' ),
				'permission_callback' => $permission,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'status'   => array(
							'type'        => 'string',
							'enum'        => array( 'any', 'publish', 'draft', 'trash' ),
							'description' => __( 'Filter by publication status. Defaults to any, which covers live forms; pass trash to find forms that were deleted.', 'quillforms' ),
						),
						'search'   => array(
							'type'        => 'string',
							'description' => __( 'Optional search term matched against the form title.', 'quillforms' ),
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => __( 'Results per page, 1-100. Defaults to 20.', 'quillforms' ),
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => __( 'Page number, starting at 1.', 'quillforms' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'forms' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'              => array( 'type' => 'integer' ),
									'title'           => array( 'type' => 'string' ),
									'status'          => array( 'type' => 'string' ),
									'blocks_count'    => array( 'type' => 'integer' ),
									'responses_count' => array( 'type' => 'integer' ),
									'shortcode'       => array( 'type' => 'string' ),
									'edit_url'        => array( 'type' => 'string' ),
									'preview_url'     => array( 'type' => 'string' ),
									'date_created'    => array( 'type' => 'string' ),
								),
							),
						),
						'total' => array( 'type' => 'integer' ),
						'page'  => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => static function ( $input = null ) {
					return Forms_Service::list_forms( is_array( $input ) ? $input : array() );
				},
			),

			'quillforms/get-form'         => array(
				'label'               => __( 'Get a Quill Forms form', 'quillforms' ),
				'description'         => __( 'Returns the complete definition of one form: blocks (fields), messages, notifications, settings and theme. Nested payloads are returned as JSON strings.', 'quillforms' ),
				'permission_callback' => $permission,
				'input_schema'        => Schemas::form_id_input(),
				'output_schema'       => Schemas::form_output(),
				'execute_callback'    => static function ( $input = null ) {
					return Forms_Service::get_form( is_array( $input ) ? $input : array() );
				},
			),

			'quillforms/get-form-blocks'  => array(
				'label'               => __( 'Get form fields', 'quillforms' ),
				'description'         => __( 'Returns only the blocks (fields) of a form as a JSON string. Cheaper than get-form when you just need to inspect or edit fields.', 'quillforms' ),
				'permission_callback' => $permission,
				'input_schema'        => Schemas::form_id_input(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'form_id' => array( 'type' => 'integer' ),
						'blocks'  => Schemas::json_string( __( 'Form blocks as a JSON string.', 'quillforms' ) ),
					),
				),
				'execute_callback'    => static function ( $input = null ) {
					return Forms_Service::get_form_blocks( is_array( $input ) ? $input : array() );
				},
			),

			'quillforms/list-block-types' => array(
				'label'               => __( 'List available field types', 'quillforms' ),
				'description'         => __( 'Lists every registered Quill Forms block type with the attributes it accepts. Call this before creating or editing fields so you use valid block names and attributes.', 'quillforms' ),
				'permission_callback' => $permission,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'block_types' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name'               => array( 'type' => 'string' ),
									'supported_features' => Schemas::json_string( __( 'Supported features as a JSON string.', 'quillforms' ) ),
									'attributes_schema'  => Schemas::json_string( __( 'Attribute schema as a JSON string.', 'quillforms' ) ),
								),
							),
						),
					),
				),
				'execute_callback'    => static function ( $input = null ) {
					return Forms_Service::list_block_types();
				},
			),

			'quillforms/get-shortcode'    => array(
				'label'               => __( 'Get form shortcode', 'quillforms' ),
				'description'         => __( 'Returns the shortcode used to embed a form in a post or page.', 'quillforms' ),
				'permission_callback' => $permission,
				'input_schema'        => Schemas::form_id_input(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'   => array( 'type' => 'integer' ),
						'shortcode' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function ( $input = null ) {
					$input   = is_array( $input ) ? $input : array();
					$form_id = Forms_Service::validate_form_id( $input['form_id'] ?? 0 );
					if ( is_wp_error( $form_id ) ) {
						return $form_id;
					}
					return array(
						'form_id'   => (int) $form_id,
						'shortcode' => Forms_Service::shortcode( $form_id ),
					);
				},
			),
		);
	}

	/**
	 * Write abilities, registered only when updates are allowed.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get_write_definitions() {
		$permission = Schemas::permission();

		return array(
			'quillforms/create-form'          => array(
				'label'               => __( 'Create a Quill Forms form', 'quillforms' ),
				'description'         => __( 'Creates a new form, optionally with its fields. Call quillforms/list-block-types first to learn which block names and attributes are valid. Created as a draft unless status is set to publish.', 'quillforms' ),
				'permission_callback' => $permission,
				'annotations'         => array(
					'readonly'   => false,
					'destructive' => false,
					'idempotent' => false,
				),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'    => array(
							'type'        => 'string',
							'description' => __( 'The form title. Required.', 'quillforms' ),
						),
						'status'   => array(
							'type'        => 'string',
							'enum'        => array( 'publish', 'draft' ),
							'description' => __( 'Publication status. Defaults to draft.', 'quillforms' ),
						),
						'blocks'   => Schemas::blocks_input_property(),
						'settings' => array(
							'type'        => 'object',
							'description' => __( 'Optional form settings object.', 'quillforms' ),
						),
						'messages' => array(
							'type'        => 'object',
							'description' => __( 'Optional overrides for the form UI messages.', 'quillforms' ),
						),
					),
					'required'             => array( 'title' ),
					'additionalProperties' => false,
				),
				'output_schema'       => Schemas::form_output(),
				'execute_callback'    => static function ( $input = null ) {
					return Forms_Service::create_form( is_array( $input ) ? $input : array() );
				},
			),

			'quillforms/update-form-blocks'   => array(
				'label'               => __( 'Replace form fields', 'quillforms' ),
				'description'         => __( 'Replaces the entire blocks array of a form. Any field missing from the supplied array is deleted, so fetch the current blocks first and send them back with your changes. If the replacement would remove fields, the call is refused and lists them; re-send with confirm_deletions set to true only once the user has agreed. To change one field without touching the others, use quillforms/update-field instead.', 'quillforms' ),
				'permission_callback' => $permission,
				'annotations'         => array(
					'readonly'   => false,
					'destructive' => true,
					'idempotent' => true,
				),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id'           => Schemas::form_id_property(),
						'blocks'            => Schemas::blocks_input_property(),
						'confirm_deletions' => array(
							'type'        => 'boolean',
							'description' => __( 'Set to true to allow deleting fields that are absent from the supplied array. Ask the user before setting this: deleted fields cannot be recovered, and existing responses to them stop mapping to a question.', 'quillforms' ),
						),
					),
					'required'             => array( 'form_id', 'blocks' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'form_id' => array( 'type' => 'integer' ),
						'blocks'  => Schemas::json_string( __( 'Resulting blocks as a JSON string.', 'quillforms' ) ),
						'deleted' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Fields removed by this call, if any.', 'quillforms' ),
						),
					),
				),
				'execute_callback'    => static function ( $input = null ) {
					return Forms_Service::update_form_blocks( is_array( $input ) ? $input : array() );
				},
			),

			'quillforms/add-field'            => array(
				'label'               => __( 'Add a field to a form', 'quillforms' ),
				'description'         => __( 'Adds one field to a form. Position it with index, before_block_id or after_block_id; appended to the end by default.', 'quillforms' ),
				'permission_callback' => $permission,
				'annotations'         => array(
					'readonly'   => false,
					'destructive' => false,
					'idempotent' => false,
				),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id'         => Schemas::form_id_property(),
						'name'            => array(
							'type'        => 'string',
							'description' => __( 'Block type slug, e.g. short-text, email, multiple-choice. See quillforms/list-block-types.', 'quillforms' ),
						),
						'attributes'      => array(
							'type'        => 'object',
							'description' => __( 'Block attributes such as label, required, description, choices.', 'quillforms' ),
						),
						'index'           => array(
							'type'        => 'integer',
							'description' => __( 'Zero-based position to insert at.', 'quillforms' ),
						),
						'before_block_id' => array(
							'type'        => 'string',
							'description' => __( 'Insert immediately before this block id.', 'quillforms' ),
						),
						'after_block_id'  => array(
							'type'        => 'string',
							'description' => __( 'Insert immediately after this block id.', 'quillforms' ),
						),
					),
					'required'             => array( 'form_id', 'name' ),
					'additionalProperties' => false,
				),
				'output_schema'       => Schemas::blocks_output(),
				'execute_callback'    => static function ( $input = null ) {
					return Forms_Service::add_field( is_array( $input ) ? $input : array() );
				},
			),

			'quillforms/update-field'         => array(
				'label'               => __( 'Update a field', 'quillforms' ),
				'description'         => __( 'Patches the attributes of one field, identified by its block id. Supplied attributes are merged over the existing ones, so omitted attributes are preserved.', 'quillforms' ),
				'permission_callback' => $permission,
				'annotations'         => array(
					'readonly'   => false,
					'destructive' => false,
					'idempotent' => true,
				),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id'    => Schemas::form_id_property(),
						'block_id'   => array(
							'type'        => 'string',
							'description' => __( 'The id of the block to update.', 'quillforms' ),
						),
						'attributes' => array(
							'type'        => 'object',
							'description' => __( 'Attributes to merge, e.g. {"label":"Your email","required":true}.', 'quillforms' ),
						),
					),
					'required'             => array( 'form_id', 'block_id', 'attributes' ),
					'additionalProperties' => false,
				),
				'output_schema'       => Schemas::blocks_output(),
				'execute_callback'    => static function ( $input = null ) {
					return Forms_Service::update_field( is_array( $input ) ? $input : array() );
				},
			),

			'quillforms/delete-field'         => array(
				'label'               => __( 'Delete a field', 'quillforms' ),
				'description'         => __( 'Removes one field from a form permanently, identified by its block id.', 'quillforms' ),
				'permission_callback' => $permission,
				'annotations'         => array(
					'readonly'   => false,
					'destructive' => true,
					'idempotent' => true,
				),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id'  => Schemas::form_id_property(),
						'block_id' => array(
							'type'        => 'string',
							'description' => __( 'The id of the block to delete.', 'quillforms' ),
						),
					),
					'required'             => array( 'form_id', 'block_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => Schemas::blocks_output(),
				'execute_callback'    => static function ( $input = null ) {
					return Forms_Service::delete_field( is_array( $input ) ? $input : array() );
				},
			),

			'quillforms/update-form-settings' => array(
				'label'               => __( 'Update form settings', 'quillforms' ),
				'description'         => __( 'Updates a form title, publication status, settings, messages or theme without touching its fields. Supplied settings and messages are merged over the existing ones, so keys you omit keep their current values.', 'quillforms' ),
				'permission_callback' => $permission,
				'annotations'         => array(
					'readonly'   => false,
					'destructive' => false,
					'idempotent' => true,
				),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id'  => Schemas::form_id_property(),
						'title'    => array(
							'type'        => 'string',
							'description' => __( 'New form title.', 'quillforms' ),
						),
						'status'   => array(
							'type'        => 'string',
							'enum'        => array( 'publish', 'draft' ),
							'description' => __( 'New publication status.', 'quillforms' ),
						),
						'settings' => array(
							'type'        => 'object',
							'description' => __( 'Form settings to write.', 'quillforms' ),
						),
						'messages' => array(
							'type'        => 'object',
							'description' => __( 'Form message overrides to write.', 'quillforms' ),
						),
						'theme_id' => array(
							'type'        => 'integer',
							'description' => __( 'Id of the form theme to apply.', 'quillforms' ),
						),
					),
					'required'             => array( 'form_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => Schemas::form_output(),
				'execute_callback'    => static function ( $input = null ) {
					return Forms_Service::update_form_settings( is_array( $input ) ? $input : array() );
				},
			),

			'quillforms/duplicate-form'       => array(
				'label'               => __( 'Duplicate a form', 'quillforms' ),
				'description'         => __( 'Creates a draft copy of an existing form, including its fields, settings, theme and notifications. Responses are not copied.', 'quillforms' ),
				'permission_callback' => $permission,
				'annotations'         => array(
					'readonly'   => false,
					'destructive' => false,
					'idempotent' => false,
				),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id' => Schemas::form_id_property(),
						'title'   => array(
							'type'        => 'string',
							'description' => __( 'Title for the copy. Defaults to the original title with "(copy)" appended.', 'quillforms' ),
						),
					),
					'required'             => array( 'form_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => Schemas::form_output(),
				'execute_callback'    => static function ( $input = null ) {
					return Forms_Service::duplicate_form( is_array( $input ) ? $input : array() );
				},
			),

			'quillforms/delete-form'          => array(
				'label'               => __( 'Delete a form', 'quillforms' ),
				'description'         => __( 'Moves a form to the trash. Set force to true to delete it permanently along with its responses. Confirm with the user before forcing.', 'quillforms' ),
				'permission_callback' => $permission,
				'annotations'         => array(
					'readonly'   => false,
					'destructive' => true,
					'idempotent' => true,
				),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id' => Schemas::form_id_property(),
						'force'   => array(
							'type'        => 'boolean',
							'description' => __( 'Permanently delete instead of trashing. Irreversible.', 'quillforms' ),
						),
					),
					'required'             => array( 'form_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'title'   => array( 'type' => 'string' ),
						'deleted' => array( 'type' => 'boolean' ),
						'forced'  => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => static function ( $input = null ) {
					return Forms_Service::delete_form( is_array( $input ) ? $input : array() );
				},
			),
		);
	}
}
