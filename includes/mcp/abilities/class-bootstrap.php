<?php
/**
 * Wires the abilities layer into WordPress.
 *
 * @since 5.8.0
 * @package QuillForms
 * @subpackage MCP
 */

namespace QuillForms\MCP\Abilities;

use QuillForms\MCP\Adapter_Server;
use QuillForms\MCP\Server;
use QuillForms\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The only file in the abilities layer that touches add_action().
 *
 * Keeping the hooks in one place makes the guard rails auditable: the
 * function_exists() check for WordPress older than 6.9, the admin opt-in, and
 * the mandatory categories-before-abilities ordering.
 *
 * @since 5.8.0
 */
final class Bootstrap {

	/**
	 * Settings key: whether the MCP surface is on at all.
	 *
	 * @since 5.8.0
	 */
	public const ENABLED_KEY = 'mcp_enabled';

	/**
	 * Settings key: whether mutating abilities may be registered.
	 *
	 * @since 5.8.0
	 */
	public const UPDATES_KEY = 'mcp_allow_updates';

	/**
	 * Called from QuillForms::init_hooks() on plugins_loaded.
	 *
	 * @since 5.8.0
	 *
	 * @return void
	 */
	public static function init() {
		// init:4 so the listeners exist before WordPress lazily builds the
		// ability registry and fires wp_abilities_api_init during init:10.
		// Priority 4 rather than 5 leaves room for a plugin that wants to
		// filter our definitions in between.
		add_action( 'init', array( self::class, 'maybe_hook' ), 4 );
	}

	/**
	 * Attach the registration hooks unless something says otherwise.
	 *
	 * @since 5.8.0
	 *
	 * @return void
	 */
	public static function maybe_hook() {
		if ( ! self::is_available() ) {
			return;
		}

		// Categories MUST be registered on their own earlier hook: an ability
		// naming an unregistered category is dropped silently by WordPress.
		add_action( 'wp_abilities_api_categories_init', array( Categories::class, 'register' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );

		// Our own MCP endpoint, plus optional pickup by an MCP adapter plugin.
		Server::init();
		Adapter_Server::init();
	}

	/**
	 * Whether the Abilities API exists on this install.
	 *
	 * Quill Forms still supports WordPress versions older than 6.9, where the
	 * Abilities API is absent. Everything MCP is therefore additive: on an
	 * older site these checks fail and nothing else in the plugin notices.
	 *
	 * @since 5.8.0
	 *
	 * @return boolean
	 */
	public static function has_abilities_api() {
		return function_exists( 'wp_register_ability' )
			&& function_exists( 'wp_register_ability_category' );
	}

	/**
	 * Whether the abilities layer should register at all.
	 *
	 * @since 5.8.0
	 *
	 * @return boolean
	 */
	public static function is_available() {
		if ( ! self::has_abilities_api() ) {
			return false;
		}

		if ( ! Settings::get( self::ENABLED_KEY, false ) ) {
			return false;
		}

		/**
		 * Filter whether the Quill Forms MCP abilities layer registers at all.
		 *
		 * Last-resort switch for hosts that must block registration before any
		 * work happens.
		 *
		 * @since 5.8.0
		 *
		 * @param bool $enabled Whether to register abilities.
		 */
		return (bool) apply_filters( 'quillforms_mcp_abilities_enabled', true );
	}

	/**
	 * Whether mutating abilities may be registered.
	 *
	 * @since 5.8.0
	 *
	 * @return boolean
	 */
	public static function updates_allowed() {
		if ( ! Settings::get( self::UPDATES_KEY, false ) ) {
			return false;
		}

		/**
		 * Filter whether write abilities are registered.
		 *
		 * @since 5.8.0
		 *
		 * @param bool $allowed Whether to register mutating abilities.
		 */
		return (bool) apply_filters( 'quillforms_mcp_updates_allowed', true );
	}

	/**
	 * Register the abilities.
	 *
	 * @since 5.8.0
	 *
	 * @return void
	 */
	public static function register_abilities() {
		$definitions = Form_Abilities::get_read_definitions();
		$definitions = array_merge( $definitions, Entry_Abilities::get_definitions() );

		if ( self::updates_allowed() ) {
			$definitions = array_merge( $definitions, Form_Abilities::get_write_definitions() );
		}

		Registrar::register_definitions( $definitions );

		/**
		 * Fires after the Quill Forms MCP abilities are registered.
		 *
		 * Use Registrar::register_definitions() so the meta and gate stack is
		 * applied; never call wp_register_ability() directly.
		 *
		 * @since 5.8.0
		 */
		do_action( 'quillforms_mcp_abilities_registered' );
	}
}
