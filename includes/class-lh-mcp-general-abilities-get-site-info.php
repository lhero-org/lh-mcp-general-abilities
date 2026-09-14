<?php
/**
 * The get-site-info ability.
 *
 * @package LH_MCP_General_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns public site information, without authentication.
 *
 * This is the one ability in the plugin with no capability check at all:
 * permission_callback() returns __return_true, because everything it can
 * return is already public on the front end - title, tagline, home URL,
 * charset and locale appear in the markup of every page.
 *
 * What keeps that safe is public_fields(), the fixed allowlist the requested
 * fields are intersected against before anything reaches get_bloginfo(),
 * which would otherwise happily hand back admin_email, the WordPress version,
 * and the template and stylesheet paths. That list is deliberately not
 * filterable: widening it is not a configuration decision, it is a decision to
 * expose new data with no capability check in front of it, and a filter would
 * put that one line away in any other plugin on the network.
 *
 * Both overrides on this class exist for that reason and are the only two in
 * the plugin. permission_callback() replaces the shared edit_posts gate, and
 * meta() adds MCP annotations plus show_in_rest, which no other ability here
 * carries. Both are preserved verbatim from the registration this replaces -
 * the base class merges meta() over the mandatory mcp.public flag rather than
 * replacing it, so that flag survives the override.
 *
 * Converted from LH_MCP_General_Abilities_Site, which held this single
 * ability and so was already one class per ability; the file and class are
 * renamed to match the ability, as the write-side classes do.
 *
 * Declared unguarded, deliberately - see the note on the main plugin class.
 */
final class LH_MCP_General_Abilities_Get_Site_Info extends LH_MCP_General_Abilities_Ability {

	/**
	 * Ability name.
	 *
	 * @return string
	 */
	public static function name(): string {
		return LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() . '/get-site-info';
	}

	/**
	 * Ability label.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'Get Site Info', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
	}

	/**
	 * Ability description.
	 *
	 * @return string
	 */
	public static function description(): string {
		return __( 'Returns public site information: title, tagline, home URL, charset, and language. No authentication required.', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
	}

	/**
	 * Ability category.
	 *
	 * @return string
	 */
	public static function category(): string {
		return 'site-info';
	}

	/**
	 * No capability check - see the class docblock for why this is safe, and
	 * for what is doing the work instead.
	 *
	 * @return callable
	 */
	public static function permission_callback() {
		return '__return_true';
	}

	/**
	 * Extra meta, merged by the base class over the mandatory mcp.public
	 * flag. Unique to this ability within the plugin.
	 *
	 * @return array<string, mixed>
	 */
	protected static function meta(): array {
		return array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * The complete set of fields this ability may return.
	 *
	 * Single source of truth for both the input schema's enum and the
	 * intersection in execute(). Not filterable - see the class docblock.
	 *
	 * @return string[]
	 */
	private static function public_fields(): array {
		return array( 'name', 'description', 'url', 'charset', 'language' );
	}

	/**
	 * Input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'fields' => array(
					'type'        => 'array',
					'description' => __( 'Optional subset of fields to return. Available: name, description, url, charset, language. Defaults to all.', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
					'items'       => array(
						'type' => 'string',
						'enum' => self::public_fields(),
					),
				),
			),
		);
	}

	/**
	 * Output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'        => array( 'type' => 'string', 'description' => 'The site title.' ),
				'description' => array( 'type' => 'string', 'description' => 'The site tagline.' ),
				'url'         => array( 'type' => 'string', 'description' => 'The site home URL.' ),
				'charset'     => array( 'type' => 'string', 'description' => 'The site character encoding.' ),
				'language'    => array( 'type' => 'string', 'description' => 'The site language locale code.' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the requested public fields.
	 *
	 * @param  array|null $input Ability input parameters.
	 * @return array<string, string>
	 */
	public static function execute( $input = null ) {
		$input            = is_array( $input ) ? $input : array();
		$public_fields    = self::public_fields();
		$requested_fields = ! empty( $input['fields'] ) ? array_intersect( (array) $input['fields'], $public_fields ) : $public_fields;

		$result = array();

		foreach ( $requested_fields as $field ) {
			$result[ $field ] = get_bloginfo( $field );
		}

		return $result;
	}
}
