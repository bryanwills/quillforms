<?php
/**
 * Ability categories.
 *
 * @since 1.0.0
 * @package QuillForms
 * @subpackage MCP
 */

namespace QuillForms\MCP\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the ability category our abilities belong to.
 *
 * Must run on wp_abilities_api_categories_init, which fires before
 * wp_abilities_api_init. An ability naming an unregistered category is dropped
 * silently by WordPress, so ordering here is load-bearing.
 *
 * @since 1.0.0
 */
final class Categories {

	/**
	 * Category slug.
	 *
	 * @since 1.0.0
	 */
	public const SLUG = 'quillforms';

	/**
	 * Register the category.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::SLUG,
			array(
				'label'       => __( 'Quill Forms', 'quillforms' ),
				'description' => __( 'Abilities that read and manage Quill Forms forms, fields and responses.', 'quillforms' ),
			)
		);
	}
}
