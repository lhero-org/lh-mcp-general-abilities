<?php
/**
 * Plugin Name: LH MCP General Abilities
 * Plugin URI:  https://lhero.org/portfolio/lh-mcp-general-abilities/
 * Description: General-purpose MCP Abilities for WordPress: reading posts, authoring drafts, user search and site information. Part of the LH MCP Abilities suite alongside LH MCP Performance Abilities.
 * Version:     1.9.7
 * Author:      Peter Shaw
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lh-mcp-general-abilities
 * Domain Path: /languages
 * Author URI:  https://shawfactor.com
 *
 * Renamed from LH MCP Abilities (lh-mcp-abilities) v1.00 to LH MCP General Abilities
 * to distinguish it clearly from LH MCP Performance Abilities within the LH MCP suite.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declared unguarded, deliberately. A top-level
 * if ( ! class_exists( __CLASS__ ) ) wrapper around a plugin's own class
 * is unsafe on this server: a class with no extends/implements is
 * eligible for PHP/OPcache compile-time early binding, so it enters the
 * class table when the file is compiled, independent of the runtime
 * branch around it. class_exists() then reports true before the
 * declaration would logically have run, the block is skipped on every
 * request, and nothing registers - silently, with no fatal and nothing
 * in the debug log. WordPress already include_once's each active plugin
 * file once per request, so the guard protected nothing. Reserve that
 * pattern strictly for genuinely shared third-party library code.
 */
class LH_MCP_General_Abilities_Plugin {

	private static $instance;

	static function return_plugin_namespace() {
		return 'lh_mcp_general_abilities';
	}

	static function return_plugin_text_domain() {
		return 'lh-mcp-general-abilities';
	}

	/**
	 * Whether this plugin's own debug logging is switched on.
	 *
	 * The filter name is derived from return_plugin_namespace() rather
	 * than written out, per LH convention - the identity method is the
	 * single source of truth for the prefix. PrefixAllGlobals resolves
	 * hook names statically and cannot follow a method call, so it
	 * reports the name as unprefixed when at runtime it is exactly
	 * lh_mcp_general_abilities_return_plugin_debugging_status. Accepted
	 * false positive, same shape and same cause as the
	 * NonSingularStringLiteralDomain notices on the text domain - do not
	 * silence it by inlining a literal.
	 *
	 * @return bool
	 */
	static function return_plugin_debugging_status() {
		return apply_filters( self::return_plugin_namespace() . '_return_plugin_debugging_status', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	static function write_log( $log ) {
		if ( ( true === WP_DEBUG ) && self::return_plugin_debugging_status() ) {
			if ( is_array( $log ) || is_object( $log ) ) {
				error_log( plugin_basename( __FILE__ ) . ' - ' . print_r( $log, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			} else {
				error_log( plugin_basename( __FILE__ ) . ' - ' . $log ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Shared permission check for this plugin's content and MCP-utility
	 * abilities: get-post, list-posts, list-post-types, list-mcp-resources
	 * and read-mcp-resource. An ability with a different requirement
	 * carries its own check instead - see
	 * LH_MCP_General_Abilities_Search_Users, which requires list_users
	 * because it returns email addresses, and
	 * LH_MCP_General_Abilities_Get_Site_Info, which requires nothing at all.
	 *
	 * This is a coarse gate: it establishes that the caller edits content
	 * on this site, nothing more. The write abilities layer a per-object
	 * capability check on top of it - see
	 * LH_MCP_General_Abilities_Update_Post, which checks edit_post, and
	 * LH_MCP_General_Abilities_Get_Post, which checks read_post - and
	 * LH_MCP_General_Abilities_Read_MCP_Resource defers to the target
	 * resource's own permission callback.
	 *
	 * @param array|null $input Ability input (unused).
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to view posts.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Registers the four ability categories, in one place, so an ability
	 * class only has to name the slug it belongs to.
	 *
	 * Both label and description are required on every category:
	 * wp_register_ability_category() fails silently without a description,
	 * surfacing much later as a "category not registered" notice when an
	 * ability tries to reference it.
	 *
	 * The text domain comes from return_plugin_text_domain() rather than a
	 * string literal, per LH convention - the identity method is the single
	 * source of truth. WordPress.WP.I18n.NonSingularStringLiteralDomain is
	 * the accepted false positive that comes with it.
	 */
	public function ability_category_functions() {
		wp_register_ability_category(
			'content-management',
			array(
				'label'       => __( 'Content Management', self::return_plugin_text_domain() ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
				'description' => __( 'Abilities related to posts and pages.', self::return_plugin_text_domain() ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
			)
		);
		wp_register_ability_category(
			'user-management',
			array(
				'label'       => __( 'User Management', self::return_plugin_text_domain() ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
				'description' => __( 'Abilities related to WordPress users.', self::return_plugin_text_domain() ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
			)
		);
		wp_register_ability_category(
			'site-info',
			array(
				'label'       => __( 'Site Information', self::return_plugin_text_domain() ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
				'description' => __( 'Abilities that return public information about the site.', self::return_plugin_text_domain() ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
			)
		);
		wp_register_ability_category(
			'mcp-utilities',
			array(
				'label'       => __( 'MCP Utilities', self::return_plugin_text_domain() ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
				'description' => __( 'Generic cross-cutting abilities for introspecting and interacting with the MCP layer itself.', self::return_plugin_text_domain() ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
			)
		);
	}

	/**
	 * Bootstrap, on plugins_loaded.
	 *
	 * One class per ability, each a subclass of
	 * LH_MCP_General_Abilities_Ability, loaded here and bootstrapping
	 * itself by hooking its own registration on wp_abilities_api_init. The
	 * base assembles every wp_register_ability() argument array from the
	 * subclass's identity and schema methods, so no individual ability can
	 * omit meta.mcp.public and silently vanish from the MCP tool list - the
	 * failure that motivated the base in the first place, since it
	 * registers successfully and only surfaces much later as an
	 * ability_not_public_mcp line in the debug log.
	 *
	 * The ability categories stay in this class so all four are declared in
	 * one place; an ability class just references the slug it needs. What
	 * remains here is identity, the shared permission gate, those
	 * categories, and this bootstrap.
	 */
	public function plugin_init() {
		$includes = plugin_dir_path( __FILE__ ) . 'includes/';

		/*
		 * The abstract base and the shared support classes load first. Load
		 * order is load-bearing: a class with an extends clause is not
		 * eligible for the compile-time early binding described above, so
		 * its parent has to already be in the class table when the subclass
		 * file is compiled. Requiring the base ahead of everything makes
		 * the order of the ability classes below irrelevant. None of these
		 * register an ability, so none is bootstrapped.
		 */
		require_once $includes . 'class-lh-mcp-general-abilities-ability.php';
		require_once $includes . 'class-lh-mcp-general-abilities-post-read-support.php';
		require_once $includes . 'class-lh-mcp-general-abilities-post-write-support.php';
		require_once $includes . 'class-lh-mcp-general-abilities-mcp-resource-support.php';

		require_once $includes . 'class-lh-mcp-general-abilities-search-users.php';
		LH_MCP_General_Abilities_Search_Users::plugin_init();

		require_once $includes . 'class-lh-mcp-general-abilities-list-mcp-resources.php';
		LH_MCP_General_Abilities_List_MCP_Resources::plugin_init();

		require_once $includes . 'class-lh-mcp-general-abilities-read-mcp-resource.php';
		LH_MCP_General_Abilities_Read_MCP_Resource::plugin_init();

		require_once $includes . 'class-lh-mcp-general-abilities-get-site-info.php';
		LH_MCP_General_Abilities_Get_Site_Info::plugin_init();

		require_once $includes . 'class-lh-mcp-general-abilities-get-post.php';
		LH_MCP_General_Abilities_Get_Post::plugin_init();

		require_once $includes . 'class-lh-mcp-general-abilities-list-post-types.php';
		LH_MCP_General_Abilities_List_Post_Types::plugin_init();

		require_once $includes . 'class-lh-mcp-general-abilities-list-posts.php';
		LH_MCP_General_Abilities_List_Posts::plugin_init();

		require_once $includes . 'class-lh-mcp-general-abilities-create-post.php';
		LH_MCP_General_Abilities_Create_Post::plugin_init();

		require_once $includes . 'class-lh-mcp-general-abilities-update-post.php';
		LH_MCP_General_Abilities_Update_Post::plugin_init();

		add_action( 'wp_abilities_api_categories_init', array( $this, 'ability_category_functions' ) );
	}

	/**
	 * Gets an instance of our plugin (singleton pattern).
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'plugin_init' ) );
	}
}

$lh_mcp_general_abilities_instance = LH_MCP_General_Abilities_Plugin::get_instance();
