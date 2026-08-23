<?php
/**
 * Entry ability definitions.
 *
 * @since 1.0.0
 * @package QuillForms
 * @subpackage MCP
 */

namespace QuillForms\MCP\Abilities;

use QuillForms\Core;
use QuillForms\Entries\Entry;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the response-facing abilities.
 *
 * All read-only: this addon never mutates submitted responses. Editing or
 * deleting a person's submitted data through an agent is a different risk
 * class from editing a form, and is deliberately out of scope.
 *
 * @since 1.0.0
 */
final class Entry_Abilities {

	/**
	 * Get the definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get_definitions() {
		$permission = Schemas::permission();

		return array(
			'quillforms/get-form-stats' => array(
				'label'               => __( 'Get form response statistics', 'quillforms' ),
				'description'         => __( 'Returns response counts for a form: total, completed, partial and unread, optionally within a date range.', 'quillforms' ),
				'permission_callback' => $permission,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id' => Schemas::form_id_property(),
						'from'    => array(
							'type'        => 'string',
							'description' => __( 'Start date, YYYY-MM-DD. Defaults to the beginning of time.', 'quillforms' ),
						),
						'to'      => array(
							'type'        => 'string',
							'description' => __( 'End date, YYYY-MM-DD. Defaults to today.', 'quillforms' ),
						),
					),
					'required'             => array( 'form_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'   => array( 'type' => 'integer' ),
						'title'     => array( 'type' => 'string' ),
						'total'     => array( 'type' => 'integer' ),
						'completed' => array( 'type' => 'integer' ),
						'partial'   => array( 'type' => 'integer' ),
						'unread'    => array( 'type' => 'integer' ),
						'from'      => array( 'type' => 'string' ),
						'to'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function ( $input = null ) {
					return self::get_stats( is_array( $input ) ? $input : array() );
				},
			),

			'quillforms/list-entries'   => array(
				'label'               => __( 'List form responses', 'quillforms' ),
				'description'         => __( 'Lists submitted responses for a form with human-readable answers, optionally filtered by date. Use a small per_page value first — response payloads can be large.', 'quillforms' ),
				'permission_callback' => $permission,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id'  => Schemas::form_id_property(),
						'from'     => array(
							'type'        => 'string',
							'description' => __( 'Start date, YYYY-MM-DD.', 'quillforms' ),
						),
						'to'       => array(
							'type'        => 'string',
							'description' => __( 'End date, YYYY-MM-DD.', 'quillforms' ),
						),
						'status'   => array(
							'type'        => 'string',
							'enum'        => array( 'completed', 'partial' ),
							'description' => __( 'Filter by submission status.', 'quillforms' ),
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => __( 'Responses per page, 1-100. Defaults to 10.', 'quillforms' ),
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => __( 'Page number, starting at 1.', 'quillforms' ),
						),
					),
					'required'             => array( 'form_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'form_id' => array( 'type' => 'integer' ),
						'total'   => array( 'type' => 'integer' ),
						'page'    => array( 'type' => 'integer' ),
						'entries' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'           => array( 'type' => 'integer' ),
									'status'       => array( 'type' => 'string' ),
									'is_read'      => array( 'type' => 'boolean' ),
									'date_created' => array( 'type' => 'string' ),
									'answers'      => Schemas::json_string( __( 'Answers keyed by field label, as a JSON string.', 'quillforms' ) ),
								),
							),
						),
					),
				),
				'execute_callback'    => static function ( $input = null ) {
					return self::list_entries( is_array( $input ) ? $input : array() );
				},
			),
		);
	}

	/**
	 * Compute response statistics.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	private static function get_stats( array $input ) {
		$form_id = Forms_Service::validate_form_id( $input['form_id'] ?? 0 );
		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}

		$from = isset( $input['from'] ) ? (string) $input['from'] : null;
		$to   = isset( $input['to'] ) ? (string) $input['to'] : null;

		return array(
			'form_id'   => (int) $form_id,
			'title'     => (string) get_the_title( $form_id ),
			'total'     => (int) Entry::get_entries_count( $form_id, $from, $to ),
			'completed' => (int) Entry::get_entries_count( $form_id, $from, $to, null, 'completed' ),
			'partial'   => (int) Entry::get_entries_count( $form_id, $from, $to, null, 'partial' ),
			'unread'    => (int) Entry::get_entries_count( $form_id, $from, $to, 0 ),
			'from'      => (string) ( $from ?? '' ),
			'to'        => (string) ( $to ?? '' ),
		);
	}

	/**
	 * List responses with readable answers.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	private static function list_entries( array $input ) {
		$form_id = Forms_Service::validate_form_id( $input['form_id'] ?? 0 );
		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}

		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 10;
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$from   = isset( $input['from'] ) ? (string) $input['from'] : null;
		$to     = isset( $input['to'] ) ? (string) $input['to'] : null;
		$status = isset( $input['status'] ) ? (string) $input['status'] : null;

		$form_data = Core::get_form_data( $form_id );
		$labels    = self::block_labels( $form_data );

		$entries = Entry::get_all( $form_id, $from, $to, $offset, $per_page, $status );
		$items   = array();

		foreach ( $entries as $entry ) {
			// get_all() deliberately does not load records; pull them per row.
			$entry->ensure_records_load();
			$readable = $entry->get_readable_records( $form_data, 'plain' );
			$fields   = isset( $readable['fields'] ) && is_array( $readable['fields'] ) ? $readable['fields'] : array();

			$answers = array();
			foreach ( $fields as $block_id => $record ) {
				$label             = $labels[ $block_id ] ?? $block_id;
				$answers[ $label ] = $record['readable_value'] ?? ( $record['value'] ?? null );
			}

			$items[] = array(
				'id'           => (int) $entry->ID,
				'status'       => (string) $entry->status,
				'is_read'      => (bool) $entry->is_read,
				'date_created' => (string) $entry->date_created,
				'answers'      => self::encode( $answers ),
			);
		}

		return array(
			'form_id' => (int) $form_id,
			'total'   => (int) Entry::get_entries_count( $form_id, $from, $to, null, $status ),
			'page'    => $page,
			'entries' => $items,
		);
	}

	/**
	 * Map block ids to their labels so answers read as questions, not ids.
	 *
	 * @since 1.0.0
	 *
	 * @param array $form_data Form data.
	 * @return array
	 */
	private static function block_labels( array $form_data ) {
		$labels = array();
		$blocks = isset( $form_data['blocks'] ) && is_array( $form_data['blocks'] ) ? $form_data['blocks'] : array();

		foreach ( $blocks as $block ) {
			if ( ! isset( $block['id'] ) ) {
				continue;
			}
			$label = $block['attributes']['label'] ?? '';
			$label = is_string( $label ) ? trim( wp_strip_all_tags( $label ) ) : '';

			$labels[ $block['id'] ] = '' !== $label ? $label : (string) $block['id'];
		}

		return $labels;
	}

	/**
	 * JSON-encode a payload for return through the output schema.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function encode( $value ) {
		$json = wp_json_encode( $value );
		return is_string( $json ) ? $json : '{}';
	}
}
