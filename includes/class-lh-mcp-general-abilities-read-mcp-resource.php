<?php
/**
 * The read-mcp-resource ability.
 *
 * @package LH_MCP_General_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the content of a public MCP resource-typed ability by uri.
 *
 * Invokes the target ability exactly the way
 * WP\MCP\Domain\Resources\McpResource::execute() does for ability-backed
 * resources: check_permissions() first, then execute() with no arguments -
 * never an empty object. Mirroring that wiring rather than reimplementing it
 * is the whole point. The target ability's own permission callback is what
 * authorises the read; this class deliberately does not second-guess it,
 * because a resource that declines a caller must decline here too.
 *
 * The ability-level gate is therefore the plugin-wide edit_posts check with no
 * override: it establishes that the caller is an editor of some kind, and the
 * target's callback makes the actual decision about that specific resource.
 *
 * Split out of LH_MCP_General_Abilities_MCP_Utilities, which held this and
 * list-mcp-resources; the shared public-resource test now lives on
 * LH_MCP_General_Abilities_MCP_Resource_Support rather than being duplicated
 * in both. That matters here more than on the listing side: if the two copies
 * ever disagreed, a resource could stay readable by uri after it had stopped
 * being listed.
 *
 * Declared unguarded, deliberately - see the note on the main plugin class.
 */
final class LH_MCP_General_Abilities_Read_MCP_Resource extends LH_MCP_General_Abilities_Ability {

	/**
	 * Ability name.
	 *
	 * @return string
	 */
	public static function name(): string {
		return LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() . '/read-mcp-resource';
	}

	/**
	 * Ability label.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'Read MCP Resource', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
	}

	/**
	 * Ability description.
	 *
	 * @return string
	 */
	public static function description(): string {
		return __( 'Reads the content of a public MCP resource-typed ability by uri (as listed by list-mcp-resources). Invokes the target ability exactly as the MCP resources/read handler does: its own permission_callback via check_permissions(), then its execute_callback via execute() with no arguments.', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
	}

	/**
	 * Ability category.
	 *
	 * @return string
	 */
	public static function category(): string {
		return 'mcp-utilities';
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
				'uri' => array(
					'type'        => 'string',
					'description' => 'The resource URI to read, as returned by list-mcp-resources.',
				),
			),
			'required'   => array( 'uri' ),
		);
	}

	/**
	 * Output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'uri'     => array( 'type' => 'string' ),
				'ability' => array( 'type' => 'string', 'description' => 'The full ability name that served this resource.' ),
				'content' => array( 'description' => 'The raw return value of the target ability\'s execute_callback - shape varies per resource.' ),
			),
		);
	}

	/**
	 * Finds the public resource with this uri and invokes it.
	 *
	 * @param  array|null $input Ability input parameters. Requires 'uri'.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$uri   = isset( $input['uri'] ) ? trim( (string) $input['uri'] ) : '';

		if ( '' === $uri ) {
			return new WP_Error( 'missing_param', 'uri is required.', array( 'status' => 400 ) );
		}

		$target = null;

		foreach ( wp_get_abilities() as $ability ) {
			$mcp = LH_MCP_General_Abilities_MCP_Resource_Support::resource_meta( $ability );

			if ( null === $mcp ) {
				continue;
			}

			if ( ( $mcp['uri'] ?? null ) === $uri ) {
				$target = $ability;
				break;
			}
		}

		if ( ! $target ) {
			return new WP_Error(
				'not_found',
				sprintf( "No public resource-typed ability is registered with uri '%s'.", $uri ),
				array( 'status' => 404 )
			);
		}

		try {
			$permission = $target->check_permissions();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'permission_check_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		if ( false === $permission ) {
			return new WP_Error( 'forbidden', 'You do not have permission to read this resource.', array( 'status' => 403 ) );
		}

		try {
			$result = $target->execute();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'execution_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'uri'     => $uri,
			'ability' => $target->get_name(),
			'content' => $result,
		);
	}
}
