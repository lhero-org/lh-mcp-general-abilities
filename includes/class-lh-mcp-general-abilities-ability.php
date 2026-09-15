<?php
/**
 * Abstract base for a single MCP ability.
 *
 * @package LH_MCP_General_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One subclass per ability.
 *
 * The domain classes in this plugin (users, site, mcp-utilities, posts) each
 * hold several abilities and repeat a full wp_register_ability() argument
 * array for every one of them. That repetition is where the two documented
 * silent-registration failures live: omitting meta.mcp.public registers the
 * ability successfully but leaves it out of the MCP tool list, surfacing much
 * later as an ability_not_public_mcp line in the debug log; and an underscore
 * anywhere in the ability name is rejected by WP_Abilities_Registry with a
 * _doing_it_wrong() notice and no fatal. Neither is visible at the call site.
 *
 * Here the argument array is assembled once, in register(), from abstract
 * methods a subclass must supply. meta.mcp.public is always set, so it cannot
 * be forgotten. A subclass that genuinely differs overrides the specific hook
 * it needs - permission_callback() and meta() are the two that vary in
 * practice - which makes the exception visible as an override rather than
 * invisible as an omission.
 *
 * Late static binding does the work: register() and plugin_init() are declared
 * once here but resolve static:: against the subclass, so each ability hooks
 * and registers itself.
 *
 * Load order matters. A class with an extends clause is not eligible for the
 * PHP/OPcache compile-time early binding described in the LH standing
 * conventions, so this file must be require_once'd before any subclass file.
 * The main plugin class's plugin_init() does that.
 *
 * Declared unguarded, deliberately - see the note on the main plugin class for
 * why a class_exists() wrapper around a plugin's own class is unsafe here.
 */
abstract class LH_MCP_General_Abilities_Ability {

	/**
	 * Fully-qualified ability name, e.g. lh-mcp-general-abilities/create-post.
	 *
	 * Must match ^[a-z0-9-]+/[a-z0-9-]+$ - hyphens only. Build it from
	 * LH_MCP_General_Abilities_Plugin::return_plugin_text_domain(), never
	 * return_plugin_namespace(), which is underscored for hook names.
	 *
	 * @return string
	 */
	abstract public static function name(): string;

	/**
	 * Short human-readable label.
	 *
	 * @return string
	 */
	abstract public static function label(): string;

	/**
	 * Description shown to an MCP caller deciding whether to use this
	 * ability. This is the only documentation most callers will ever read,
	 * so it should say what the ability refuses as well as what it does.
	 *
	 * @return string
	 */
	abstract public static function description(): string;

	/**
	 * Registered ability category slug. The categories themselves are
	 * declared in one place, on the main plugin class; this just names one.
	 *
	 * @return string
	 */
	abstract public static function category(): string;

	/**
	 * JSON Schema for this ability's input.
	 *
	 * @return array<string, mixed>
	 */
	abstract public static function input_schema(): array;

	/**
	 * Execute callback.
	 *
	 * @param  array|null $input Ability input parameters.
	 * @return mixed
	 */
	abstract public static function execute( $input = null );

	/**
	 * JSON Schema for this ability's output. Optional: an empty array means
	 * no output_schema key is passed at all, matching list-post-types, which
	 * registers without one.
	 *
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		return array();
	}

	/**
	 * Permission callback. Defaults to the plugin-wide edit_posts gate.
	 *
	 * A subclass needing something else overrides this - get-site-info
	 * returns __return_true because it is deliberately unauthenticated, and
	 * the users domain requires list_users because it returns email
	 * addresses. Note this returns a *callable*, not a boolean: the
	 * abilities API invokes it later.
	 *
	 * A coarse gate here is not a substitute for a per-object check inside
	 * execute(). Any ability that writes to, or reads, a specific post must
	 * additionally check edit_post or read_post on that ID.
	 *
	 * @return callable
	 */
	public static function permission_callback() {
		return array( 'LH_MCP_General_Abilities_Plugin', 'check_permissions' );
	}

	/**
	 * Extra meta merged over the mandatory mcp.public flag. Override to add
	 * annotations or show_in_rest; get-site-info is the one ability in this
	 * plugin that does.
	 *
	 * @return array<string, mixed>
	 */
	protected static function meta(): array {
		return array();
	}

	/**
	 * Hooks this ability's registration. Called from the main plugin class's
	 * plugin_init(), once per ability class.
	 *
	 * @return void
	 */
	public static function plugin_init(): void {
		add_action( 'wp_abilities_api_init', array( static::class, 'register' ) );
	}

	/**
	 * Assembles and performs the registration. Final: the whole point of
	 * this class is that every ability is registered by the same code path,
	 * so the guarantees above cannot be overridden away. Vary the inputs,
	 * not this.
	 *
	 * @return void
	 */
	final public static function register(): void {
		$args = array(
			'label'               => static::label(),
			'description'         => static::description(),
			'category'            => static::category(),
			'input_schema'        => static::input_schema(),
			'execute_callback'    => array( static::class, 'execute' ),
			'permission_callback' => static::permission_callback(),
			'meta'                => array_replace_recursive(
				array( 'mcp' => array( 'public' => true ) ),
				static::meta()
			),
		);

		$output_schema = static::output_schema();

		if ( ! empty( $output_schema ) ) {
			$args['output_schema'] = $output_schema;
		}

		wp_register_ability( static::name(), $args );
	}
}
