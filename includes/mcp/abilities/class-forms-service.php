<?php
/**
 * Forms service.
 *
 * @since 1.0.0
 * @package QuillForms
 * @subpackage MCP
 */

namespace QuillForms\MCP\Abilities;

use QuillForms\Core;
use QuillForms\Managers\Blocks_Manager;
use WP_Error;
use WP_Query;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Does the actual reading and writing of forms.
 *
 * Writes are dispatched as internal REST requests against
 * /wp/v2/quill_forms/<id> rather than calling update_post_meta() directly.
 * That is deliberate: the REST fields registered by Quill Forms core carry the
 * block-name enum, unique-id checks, wp_kses on messages, payment gateway and
 * currency validation, and they fire the quillforms_form_*_updated actions that
 * addons listen on. Raw meta writes bypass all of it and would let an agent
 * silently corrupt a form.
 *
 * @since 1.0.0
 */
final class Forms_Service {

	/**
	 * Post type.
	 *
	 * @since 1.0.0
	 */
	public const POST_TYPE = 'quill_forms';

	/**
	 * List forms.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_forms( array $input ) {
		$status   = isset( $input['status'] ) ? (string) $input['status'] : 'any';
		$search   = isset( $input['search'] ) ? (string) $input['search'] : '';
		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$page     = isset( $input['page'] ) ? (int) $input['page'] : 1;

		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );

		if ( 'any' === $status ) {
			// Trash is excluded from "any" on purpose — an agent listing forms
			// wants the live ones — but is reachable explicitly, so a form
			// deleted by mistake can still be found and restored.
			$post_status = array( 'publish', 'draft', 'pending', 'private' );
		} else {
			$post_status = array( $status );
		}

		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => $post_status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$forms = array();

		foreach ( $query->posts as $post ) {
			$forms[] = self::summarize( $post->ID, $post );
		}

		return array(
			'forms' => $forms,
			'total' => (int) $query->found_posts,
			'page'  => $page,
		);
	}

	/**
	 * Build the compact summary used by list-forms.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $form_id Form id.
	 * @param \WP_Post $post    Post object.
	 * @return array
	 */
	private static function summarize( $form_id, $post ) {
		// Same source as every other ability, so blocks_count can never
		// disagree with the array quillforms_get_form_blocks returns.
		$blocks = self::read_blocks( $form_id );

		return array(
			'id'              => (int) $form_id,
			'title'           => (string) get_the_title( $form_id ),
			'status'          => (string) $post->post_status,
			'blocks_count'    => count( $blocks ),
			'responses_count' => self::responses_count( $form_id ),
			'shortcode'       => self::shortcode( $form_id ),
			'edit_url'        => (string) admin_url( 'admin.php?page=quillforms&path=/forms/' . $form_id . '/builder' ),
			'preview_url'     => (string) get_permalink( $form_id ),
			'date_created'    => self::created_date( $post ),
		);
	}

	/**
	 * Creation date, preferring GMT.
	 *
	 * WordPress leaves post_date_gmt zeroed for drafts, so fall back to the
	 * site-local date rather than reporting 0000-00-00 to the model.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	private static function created_date( $post ) {
		$gmt = (string) $post->post_date_gmt;

		if ( '' !== $gmt && 0 !== strpos( $gmt, '0000-00-00' ) ) {
			return $gmt;
		}

		return (string) $post->post_date;
	}

	/**
	 * Responses count, tolerant of the entries class being unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @param int $form_id Form id.
	 * @return int
	 */
	public static function responses_count( $form_id ) {
		if ( ! class_exists( '\QuillForms\Entries\Entry' ) ) {
			return 0;
		}
		return (int) \QuillForms\Entries\Entry::get_count( $form_id );
	}

	/**
	 * Shortcode string for a form.
	 *
	 * @since 1.0.0
	 *
	 * @param int $form_id Form id.
	 * @return string
	 */
	public static function shortcode( $form_id ) {
		return sprintf( '[quillforms id="%d" width="100%%" height="500px"]', (int) $form_id );
	}

	/**
	 * Ensure a post id really is a Quill Forms form.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $form_id Form id.
	 * @return int|WP_Error
	 */
	public static function validate_form_id( $form_id ) {
		$form_id = (int) $form_id;
		$post    = $form_id > 0 ? get_post( $form_id ) : null;

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'quillforms_mcp_form_not_found',
				sprintf( 'No Quill Forms form found with id %d.', $form_id ),
				array( 'status' => 404 )
			);
		}

		return $form_id;
	}

	/**
	 * Get one form in full.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_form( array $input ) {
		$form_id = self::validate_form_id( $input['form_id'] ?? 0 );
		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}

		$post      = get_post( $form_id );
		$form_data = Core::get_form_data( $form_id );

		return array(
			'id'              => (int) $form_id,
			'title'           => (string) get_the_title( $form_id ),
			'status'          => (string) $post->post_status,
			'blocks'          => self::encode( self::read_blocks( $form_id ) ),
			'messages'        => self::encode( $form_data['messages'] ?? array() ),
			'notifications'   => self::encode( $form_data['notifications'] ?? array() ),
			'settings'        => self::encode( Core::get_form_settings( $form_id ) ),
			'theme_id'        => (int) Core::get_theme_id( $form_id ),
			'responses_count' => self::responses_count( $form_id ),
			'shortcode'       => self::shortcode( $form_id ),
			'edit_url'        => (string) admin_url( 'admin.php?page=quillforms&path=/forms/' . $form_id . '/builder' ),
		);
	}

	/**
	 * Get just the blocks of a form.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_form_blocks( array $input ) {
		$form_id = self::validate_form_id( $input['form_id'] ?? 0 );
		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}

		return array(
			'form_id' => (int) $form_id,
			'blocks'  => self::encode( self::read_blocks( $form_id ) ),
		);
	}

	/**
	 * The authoritative block list for a form.
	 *
	 * Every ability — read and write alike — returns blocks through here, so a
	 * caller never has to wonder whether the array it got back is the same
	 * shape the next call will report.
	 *
	 * Blocks that Quill Forms injects at render time rather than storing are
	 * filtered out. The honeypot is the current example: it is an anti-spam
	 * field appended by Honeypot::inject_block() during read, it is not part of
	 * the saved form, and an agent that saw it would be liable to try to edit
	 * or delete a field that does not really exist.
	 *
	 * @since 1.0.0
	 *
	 * @param int $form_id Form id.
	 * @return array
	 */
	private static function read_blocks( $form_id ) {
		$blocks = Core::get_blocks( $form_id );
		$blocks = is_array( $blocks ) ? $blocks : array();

		$virtual = array( 'honeypot' );

		$blocks = array_filter(
			$blocks,
			static function ( $block ) use ( $virtual ) {
				return ! isset( $block['name'] ) || ! in_array( $block['name'], $virtual, true );
			}
		);

		return array_values( $blocks );
	}

	/**
	 * List the registered block types and their attribute schemas.
	 *
	 * Without this an agent has no way to know which block names are legal or
	 * which attributes each one accepts.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function list_block_types() {
		$types = array();

		foreach ( Blocks_Manager::instance()->get_all_registered() as $name => $block ) {
			$supported = array();
			if ( property_exists( $block, 'supported_features' ) ) {
				$supported = (array) $block->supported_features;
			}

			$schema = method_exists( $block, 'get_attributes_schema' )
				? (array) $block->get_attributes_schema()
				: array();

			$types[] = array(
				'name'               => (string) $name,
				'supported_features' => self::encode( $supported ),
				'attributes_schema'  => self::encode( $schema ),
			);
		}

		return array( 'block_types' => $types );
	}

	/**
	 * Create a form.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function create_form( array $input ) {
		$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error(
				'quillforms_mcp_missing_title',
				'A form title is required.',
				array( 'status' => 400 )
			);
		}

		$status = isset( $input['status'] ) ? (string) $input['status'] : 'draft';
		if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
			$status = 'draft';
		}

		$form_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => $title,
				'post_status' => $status,
			),
			true
		);

		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}

		$blocks = isset( $input['blocks'] ) && is_array( $input['blocks'] ) ? $input['blocks'] : array();
		$blocks = self::prepare_blocks( $blocks );

		$body = array( 'blocks' => $blocks );
		if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$body['settings'] = $input['settings'];
		}
		if ( isset( $input['messages'] ) && is_array( $input['messages'] ) ) {
			$body['messages'] = $input['messages'];
		}

		$saved = self::save( $form_id, $body );
		if ( is_wp_error( $saved ) ) {
			// Roll back so a validation failure does not leave an empty husk —
			// but only if the form really is still empty. A partial write, or
			// an addon that hooked the insert and attached its own data, means
			// a force-delete would destroy more than this call created; leaving
			// a draft behind is the lesser harm, and the error names it.
			$written = get_post_meta( $form_id, 'blocks', true );

			if ( empty( $written ) ) {
				wp_delete_post( $form_id, true );
				return $saved;
			}

			return new WP_Error(
				'quillforms_mcp_partial_create',
				sprintf(
					'Form %d was created but could not be fully saved: %s. It has been left as a draft for you to inspect or delete.',
					(int) $form_id,
					$saved->get_error_message()
				),
				array( 'status' => 500 )
			);
		}

		return self::get_form( array( 'form_id' => $form_id ) );
	}

	/**
	 * Replace the blocks of a form.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_form_blocks( array $input ) {
		$form_id = self::validate_form_id( $input['form_id'] ?? 0 );
		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}

		$blocks = isset( $input['blocks'] ) && is_array( $input['blocks'] ) ? $input['blocks'] : null;
		if ( null === $blocks ) {
			return new WP_Error(
				'quillforms_mcp_missing_blocks',
				'A blocks array is required.',
				array( 'status' => 400 )
			);
		}

		$prepared = self::prepare_blocks( $blocks );

		// This ability replaces the whole array, so any field the caller left
		// out is deleted. That is a reasonable thing to ask for deliberately
		// and a very bad thing to do by accident — a forgotten field takes its
		// answers' meaning with it, since existing responses keep pointing at a
		// block id that no longer exists. Require the caller to acknowledge
		// each removal by name rather than discovering it afterwards.
		// Both sides are flattened before comparing. A field nested inside a
		// group is just as gone as a top-level one when it disappears, so a
		// guard that only diffed the outer array would report "nothing
		// deleted" while wiping every child of a group — worse than no guard,
		// because it reads as an all-clear.
		$existing = self::flatten_blocks( self::read_blocks( $form_id ) );
		$kept     = array();
		foreach ( self::flatten_blocks( $prepared ) as $block ) {
			$kept[ (string) $block['id'] ] = true;
		}

		$removed = array();
		foreach ( $existing as $block ) {
			if ( isset( $block['id'] ) && ! isset( $kept[ (string) $block['id'] ] ) ) {
				$label     = $block['attributes']['label'] ?? '';
				$label     = is_string( $label ) ? trim( wp_strip_all_tags( $label ) ) : '';
				$removed[] = sprintf(
					'%s (%s%s)',
					(string) $block['id'],
					(string) ( $block['name'] ?? 'unknown' ),
					'' !== $label ? ': ' . $label : ''
				);
			}
		}

		if ( ! empty( $removed ) && empty( $input['confirm_deletions'] ) ) {
			$responses = self::responses_count( $form_id );

			return new WP_Error(
				'quillforms_mcp_would_delete_fields',
				sprintf(
					'This would permanently delete %d field(s) not present in the supplied array: %s.%s Re-send with "confirm_deletions": true if that is intended, or use quillforms/update-field to change one field without touching the rest.',
					count( $removed ),
					implode( ', ', $removed ),
					$responses > 0
						? sprintf(
							' This form has %d response(s); their answers to the deleted field(s) will no longer map to a question.',
							$responses
						)
						: ''
				),
				array( 'status' => 409 )
			);
		}

		$saved = self::save( $form_id, array( 'blocks' => $prepared ) );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$result            = self::get_form_blocks( array( 'form_id' => $form_id ) );
		$result['deleted'] = $removed;

		return $result;
	}

	/**
	 * Add a single field to a form.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function add_field( array $input ) {
		$form_id = self::validate_form_id( $input['form_id'] ?? 0 );
		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}

		$name = isset( $input['name'] ) ? (string) $input['name'] : '';
		if ( ! Blocks_Manager::instance()->is_registered( $name ) ) {
			return new WP_Error(
				'quillforms_mcp_unknown_block',
				sprintf( 'Unknown block type "%s". Call quillforms/list-block-types for the allowed names.', $name ),
				array( 'status' => 400 )
			);
		}

		$blocks = self::read_blocks( $form_id );

		$block = array(
			// Collision-check against nested ids too: block ids must be unique
			// across the whole form, not just the top level.
			'id'         => self::generate_block_id( self::flatten_blocks( $blocks ) ),
			'name'       => $name,
			'attributes' => isset( $input['attributes'] ) && is_array( $input['attributes'] ) ? $input['attributes'] : array(),
		);

		$index = self::resolve_index( $blocks, $input );
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		array_splice( $blocks, $index, 0, array( $block ) );

		$saved = self::save( $form_id, array( 'blocks' => self::prepare_blocks( $blocks ) ) );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'form_id'  => (int) $form_id,
			'block_id' => $block['id'],
			'blocks'   => self::encode( self::read_blocks( $form_id ) ),
		);
	}

	/**
	 * Patch one block's attributes.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_field( array $input ) {
		$form_id = self::validate_form_id( $input['form_id'] ?? 0 );
		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}

		$block_id = isset( $input['block_id'] ) ? (string) $input['block_id'] : '';
		$blocks   = self::read_blocks( $form_id );
		$patch    = isset( $input['attributes'] ) && is_array( $input['attributes'] )
			? $input['attributes']
			: array();

		// Walks nested blocks too, so a field inside a group is addressable by
		// its id like any other.
		$found  = false;
		$blocks = self::patch_block( $blocks, $block_id, $patch, $found );

		if ( ! $found ) {
			return new WP_Error(
				'quillforms_mcp_block_not_found',
				sprintf( 'No block with id "%s" in form %d.', $block_id, $form_id ),
				array( 'status' => 404 )
			);
		}

		$saved = self::save( $form_id, array( 'blocks' => self::prepare_blocks( $blocks ) ) );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'form_id'  => (int) $form_id,
			'block_id' => $block_id,
			'blocks'   => self::encode( self::read_blocks( $form_id ) ),
		);
	}

	/**
	 * Delete one block.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function delete_field( array $input ) {
		$form_id = self::validate_form_id( $input['form_id'] ?? 0 );
		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}

		$block_id = isset( $input['block_id'] ) ? (string) $input['block_id'] : '';
		$blocks   = self::read_blocks( $form_id );

		// Walks nested blocks too. Deleting a group still takes its children
		// with it — that is what deleting a container means — but a child can
		// now also be removed on its own.
		$found     = false;
		$remaining = self::remove_block( $blocks, $block_id, $found );

		if ( ! $found ) {
			return new WP_Error(
				'quillforms_mcp_block_not_found',
				sprintf( 'No block with id "%s" in form %d.', $block_id, $form_id ),
				array( 'status' => 404 )
			);
		}

		$saved = self::save( $form_id, array( 'blocks' => self::prepare_blocks( $remaining ) ) );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'form_id' => (int) $form_id,
			'deleted' => $block_id,
			'blocks'  => self::encode( self::read_blocks( $form_id ) ),
		);
	}

	/**
	 * Update form title, status, settings, messages or theme.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_form_settings( array $input ) {
		$form_id = self::validate_form_id( $input['form_id'] ?? 0 );
		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}

		$body = array();
		if ( isset( $input['title'] ) ) {
			$body['title'] = sanitize_text_field( (string) $input['title'] );
		}
		if ( isset( $input['status'] ) && in_array( $input['status'], array( 'publish', 'draft' ), true ) ) {
			$body['status'] = (string) $input['status'];
		}
		// Merge rather than replace, matching update-field: an agent flipping
		// one setting must not silently wipe every other setting on the form.
		// A caller that really wants to reset everything can read the current
		// values and send the whole object back.
		if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$current          = Core::get_form_settings( $form_id );
			$body['settings'] = array_replace(
				is_array( $current ) ? $current : array(),
				$input['settings']
			);
		}
		if ( isset( $input['messages'] ) && is_array( $input['messages'] ) ) {
			$current          = get_post_meta( $form_id, 'messages', true );
			$body['messages'] = array_replace(
				is_array( $current ) ? $current : array(),
				$input['messages']
			);
		}
		if ( isset( $input['theme_id'] ) ) {
			$body['theme'] = (int) $input['theme_id'];
		}

		if ( empty( $body ) ) {
			return new WP_Error(
				'quillforms_mcp_nothing_to_update',
				'Provide at least one of title, status, settings, messages or theme_id.',
				array( 'status' => 400 )
			);
		}

		$saved = self::save( $form_id, $body );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return self::get_form( array( 'form_id' => $form_id ) );
	}

	/**
	 * Duplicate a form.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function duplicate_form( array $input ) {
		$form_id = self::validate_form_id( $input['form_id'] ?? 0 );
		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}

		$title = isset( $input['title'] ) && '' !== $input['title']
			? sanitize_text_field( (string) $input['title'] )
			/* translators: %s: original form title. */
			: sprintf( __( '%s (copy)', 'quillforms' ), get_the_title( $form_id ) );

		$new_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'draft',
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Copy everything except an explicit deny list, rather than copying an
		// allow list of keys known at the time this was written. Addons store
		// their per-form configuration in their own meta (addon_emailoctopus,
		// and so on); an allow list silently drops them, so the copy looks
		// right in the editor while its integrations quietly do nothing.
		$skip = array(
			// Per-form usage state, not part of the definition.
			'coupons_usage_count',
			// WordPress internals that must not follow a new post.
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_thumbnail_id',
		);

		foreach ( get_post_meta( $form_id ) as $key => $values ) {
			if ( in_array( $key, $skip, true ) || ! is_array( $values ) ) {
				continue;
			}

			foreach ( $values as $value ) {
				// get_post_meta() without $single returns raw serialized
				// strings; unserialize so update_post_meta stores the same
				// structure the source form had.
				update_post_meta( $new_id, $key, maybe_unserialize( $value ) );
			}
		}

		/**
		 * Let addons react to a form being duplicated.
		 *
		 * The blocks were written directly rather than through the REST fields,
		 * so the usual quillforms_form_blocks_updated action does not fire for
		 * a copy. Addons that maintain derived state per form need this.
		 *
		 * @since 5.8.0
		 *
		 * @param int $new_id  The new form id.
		 * @param int $form_id The form it was copied from.
		 */
		do_action( 'quillforms_form_duplicated', $new_id, $form_id );

		return self::get_form( array( 'form_id' => $new_id ) );
	}

	/**
	 * Delete a form.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function delete_form( array $input ) {
		$form_id = self::validate_form_id( $input['form_id'] ?? 0 );
		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}

		$force = ! empty( $input['force'] );
		$title = (string) get_the_title( $form_id );

		$result = $force ? wp_delete_post( $form_id, true ) : wp_trash_post( $form_id );
		if ( ! $result ) {
			return new WP_Error(
				'quillforms_mcp_delete_failed',
				sprintf( 'Could not delete form %d.', $form_id ),
				array( 'status' => 500 )
			);
		}

		return array(
			'id'      => (int) $form_id,
			'title'   => $title,
			'deleted' => true,
			'forced'  => $force,
		);
	}

	/**
	 * Restore a trashed form.
	 *
	 * The counterpart to the non-forced delete: without it, an agent that
	 * trashed the wrong form has no way to put it back and has to send the
	 * user to the admin.
	 *
	 * @since 5.8.0
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function restore_form( array $input ) {
		$form_id = self::validate_form_id( $input['form_id'] ?? 0 );
		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}

		$post = get_post( $form_id );

		if ( 'trash' !== $post->post_status ) {
			return new WP_Error(
				'quillforms_mcp_not_trashed',
				sprintf(
					'Form %d is not in the trash (its status is "%s"), so there is nothing to restore.',
					(int) $form_id,
					(string) $post->post_status
				),
				array( 'status' => 409 )
			);
		}

		if ( ! wp_untrash_post( $form_id ) ) {
			return new WP_Error(
				'quillforms_mcp_restore_failed',
				sprintf( 'Could not restore form %d.', (int) $form_id ),
				array( 'status' => 500 )
			);
		}

		// WordPress restores to whatever wp_untrash_post_set_previous_status
		// decides, which on older behaviour is 'draft'. Report the real result
		// rather than assuming it came back published.
		$restored = get_post( $form_id );

		return array(
			'id'       => (int) $form_id,
			'title'    => (string) get_the_title( $form_id ),
			'status'   => (string) $restored->post_status,
			'restored' => true,
		);
	}

	/**
	 * Merge attributes into one block anywhere in the tree.
	 *
	 * @since 5.8.0
	 *
	 * @param array   $blocks   Blocks.
	 * @param string  $block_id Target id.
	 * @param array   $patch    Attributes to merge.
	 * @param boolean $found    Set to true when the target is reached.
	 * @return array
	 */
	private static function patch_block( array $blocks, $block_id, array $patch, &$found ) {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( isset( $block['id'] ) && (string) $block['id'] === $block_id ) {
				$existing = isset( $block['attributes'] ) && is_array( $block['attributes'] )
					? $block['attributes']
					: array();

				// Merge rather than replace: an agent patching one attribute
				// must not silently drop the rest of the field's config.
				$blocks[ $index ]['attributes'] = array_replace( $existing, $patch );
				$found                          = true;

				return $blocks;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = self::patch_block(
					$block['innerBlocks'],
					$block_id,
					$patch,
					$found
				);

				if ( $found ) {
					return $blocks;
				}
			}
		}

		return $blocks;
	}

	/**
	 * Remove one block from anywhere in the tree.
	 *
	 * @since 5.8.0
	 *
	 * @param array   $blocks   Blocks.
	 * @param string  $block_id Target id.
	 * @param boolean $found    Set to true when the target is reached.
	 * @return array
	 */
	private static function remove_block( array $blocks, $block_id, &$found ) {
		$remaining = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( isset( $block['id'] ) && (string) $block['id'] === $block_id ) {
				$found = true;
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::remove_block( $block['innerBlocks'], $block_id, $found );
			}

			$remaining[] = $block;
		}

		return $remaining;
	}

	/**
	 * Flatten a block tree into a single list, children included.
	 *
	 * Used for comparisons that must treat a nested field as a real field —
	 * counting, and diffing what a write would remove.
	 *
	 * @since 5.8.0
	 *
	 * @param array $blocks Blocks, possibly containing innerBlocks.
	 * @return array
	 */
	private static function flatten_blocks( array $blocks ) {
		$flat = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$flat[] = $block;

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$flat = array_merge( $flat, self::flatten_blocks( $block['innerBlocks'] ) );
			}
		}

		return $flat;
	}

	/**
	 * Normalize a caller-supplied blocks array.
	 *
	 * Fills in missing ids so an agent can post blocks without inventing them,
	 * guarantees every block has an attributes object, and preserves nested
	 * children.
	 *
	 * @since 1.0.0
	 *
	 * @param array $blocks Blocks.
	 * @return array
	 */
	private static function prepare_blocks( array $blocks ) {
		$prepared = array();
		$seen     = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['name'] ) ) {
				continue;
			}

			$id = isset( $block['id'] ) ? (string) $block['id'] : '';
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				$id = self::generate_block_id( $prepared );
			}
			$seen[ $id ] = true;

			// Start from the block as supplied rather than rebuilding it from
			// three known keys. Group blocks carry their children in
			// `innerBlocks`, and any key this method does not know about would
			// otherwise be silently dropped on write — turning an edit to one
			// field into the deletion of every field nested under it.
			$normalized               = $block;
			$normalized['id']         = $id;
			$normalized['name']       = (string) $block['name'];
			$normalized['attributes'] = isset( $block['attributes'] ) && is_array( $block['attributes'] )
				? $block['attributes']
				: array();

			if ( isset( $block['innerBlocks'] ) ) {
				$normalized['innerBlocks'] = is_array( $block['innerBlocks'] )
					? self::prepare_blocks( $block['innerBlocks'] )
					: array();
			}

			$prepared[] = $normalized;
		}

		return $prepared;
	}

	/**
	 * Work out where a new block should be inserted.
	 *
	 * @since 1.0.0
	 *
	 * @param array $blocks Existing blocks.
	 * @param array $input  Input.
	 * @return int|WP_Error Position, or an error if an anchor block id is unknown.
	 */
	private static function resolve_index( array $blocks, array $input ) {
		$count = count( $blocks );

		foreach ( array( 'before_block_id' => 0, 'after_block_id' => 1 ) as $key => $offset ) {
			if ( empty( $input[ $key ] ) ) {
				continue;
			}
			$target = (string) $input[ $key ];
			foreach ( $blocks as $index => $block ) {
				if ( isset( $block['id'] ) && (string) $block['id'] === $target ) {
					return max( 0, min( $count, $index + $offset ) );
				}
			}

			// Silently appending here would put the field somewhere the caller
			// did not ask for, with no signal that the anchor was wrong.
			return new WP_Error(
				'quillforms_mcp_anchor_not_found',
				sprintf( 'No block with id "%s" to position against.', $target ),
				array( 'status' => 404 )
			);
		}

		if ( isset( $input['index'] ) && is_numeric( $input['index'] ) ) {
			return max( 0, min( $count, (int) $input['index'] ) );
		}

		return $count;
	}

	/**
	 * Generate a block id in the same shape the editor produces.
	 *
	 * The editor uses Math.random().toString(36).substr(2, 9); we match the
	 * alphabet and length but source randomness from wp_rand().
	 *
	 * @since 1.0.0
	 *
	 * @param array $existing Blocks already allocated, to avoid collisions.
	 * @return string
	 */
	private static function generate_block_id( array $existing = array() ) {
		$taken = array();
		foreach ( $existing as $block ) {
			if ( isset( $block['id'] ) ) {
				$taken[ (string) $block['id'] ] = true;
			}
		}

		$alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
		$max      = strlen( $alphabet ) - 1;

		do {
			$id = '';
			for ( $i = 0; $i < 9; $i++ ) {
				$id .= $alphabet[ wp_rand( 0, $max ) ];
			}
		} while ( isset( $taken[ $id ] ) );

		return $id;
	}

	/**
	 * Persist form changes through the core REST fields.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $form_id Form id.
	 * @param array $body    Fields to write.
	 * @return true|WP_Error
	 */
	private static function save( $form_id, array $body ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . self::POST_TYPE . '/' . (int) $form_id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body_params( $body );

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$error = $response->as_error();
			return new WP_Error(
				'quillforms_mcp_save_failed',
				$error->get_error_message(),
				array( 'status' => $response->get_status() )
			);
		}

		return true;
	}

	/**
	 * Make a value safe to return through an ability output schema.
	 *
	 * Output schemas here declare these payloads as JSON strings. Form blocks
	 * and settings are deeply nested, heterogeneous and extensible by addons,
	 * so enumerating them in a schema would be both enormous and wrong the
	 * moment an addon registers a new attribute. Encoding keeps the contract
	 * honest and the payload lossless.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function encode( $value ) {
		if ( ! is_array( $value ) && ! is_object( $value ) ) {
			$value = array();
		}
		$json = wp_json_encode( $value );
		return is_string( $json ) ? $json : '[]';
	}
}
