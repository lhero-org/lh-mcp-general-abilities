<?php
/**
 * Shared helper for the MCP resource abilities.
 *
 * @package LH_MCP_General_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one thing list-mcp-resources and read-mcp-resource both need: deciding
 * whether a registered ability is a public MCP resource, and handing back its
 * mcp meta if so.
 *
 * Before the split into one class per ability, both methods carried their own
 * copy of this test. Two copies of a visibility predicate is the kind of
 * duplication that goes wrong quietly: change what counts as public in one
 * place and the lister and the reader start disagreeing, so a resource shows
 * up in the list and then refuses to be read, or worse, stops being listed
 * while remaining readable by anyone who already knows its uri.
 *
 * Deliberately not filterable. This is a visibility test - an ability's own
 * registration is what declares it a public resource, and a filter here would
 * let another plugin expose or hide resources without touching the
 * registrations that are supposed to be the source of truth.
 *
 * Declared unguarded, deliberately - see the note on the main plugin class.
 */
final class LH_MCP_General_Abilities_MCP_Resource_Support {

	/**
	 * Returns an ability's mcp meta if it is a public resource, else null.
	 *
	 * An ability with no mcp.type is a tool, not a resource - that default
	 * matters, since most abilities never declare a type at all.
	 *
	 * @param  WP_Ability $ability Registered ability to test.
	 * @return array<string, mixed>|null The mcp meta array, or null if this is
	 *                                   not a public resource.
	 */
	public static function resource_meta( $ability ): ?array {
		$meta = $ability->get_meta();
		$mcp  = is_array( $meta ) && isset( $meta['mcp'] ) ? $meta['mcp'] : array();

		if ( empty( $mcp['public'] ) || 'resource' !== ( $mcp['type'] ?? 'tool' ) ) {
			return null;
		}

		return $mcp;
	}
}
