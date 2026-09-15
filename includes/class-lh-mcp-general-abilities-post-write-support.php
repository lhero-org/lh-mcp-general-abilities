<?php
/**
 * Shared validation and conversion helpers for the post-write abilities.
 *
 * @package LH_MCP_General_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything create-post and update-post both need, in one place.
 *
 * Two principles hold throughout:
 *
 * Validation is separated from application. Every method that checks
 * something returns either a normalised value or a WP_Error and writes
 * nothing; the matching apply_*() method assumes it has already been given a
 * validated value. That ordering exists because meta and terms can only be
 * written after wp_insert_post() has produced an ID, so validating them as
 * they are written would leave a created post behind on failure. Validate
 * everything first, insert, then apply.
 *
 * Allowlists, never denylists. The writable post-type list and the editable
 * status list both name what is permitted. A denylist defaults open: the site
 * has 19 public post types - lh-portfolio, lh_tasks-task_post, lh_kb_entry,
 * lh_presentation, product among them - each with a purpose-built ability
 * enforcing invariants a generic writer knows nothing about, and each new CPT
 * would be writable from the moment it is registered until someone remembered
 * to exclude it. The same reasoning applies to statuses: a !== 'publish'
 * check would fail open on lh-membership's restricted, confidential and
 * logged-in statuses, and on every status added after this was written.
 *
 * Declared unguarded, deliberately - see the note on the main plugin class.
 */
final class LH_MCP_General_Abilities_Post_Write_Support {

	/**
	 * Post types create-post and update-post may write to.
	 *
	 * Deliberately separate from the read-side allowlist on
	 * LH_MCP_General_Abilities_Posts::return_readable_post_types(), with its
	 * own filter. Sharing one filter between reading and writing would mean
	 * permitting an AI to write a CPT silently also permitted reading it -
	 * two decisions with very different risk, coupled by accident.
	 *
	 * @return string[]
	 */
	public static function return_writable_post_types(): array {
		return (array) apply_filters(
			LH_MCP_General_Abilities_Plugin::return_plugin_namespace() . '_return_writable_post_types',
			array( 'post', 'page' )
		);
	}

	/**
	 * Statuses an existing post may be in for update-post to touch it.
	 *
	 * Deliberately not filterable. This is the guard that keeps published
	 * and member-visible content out of the unreviewed write path entirely;
	 * a filter would make bypassing it a one-line change in any other
	 * plugin on the network. Published content has a reviewed path already -
	 * lh-agora/propose-changes. Same reasoning as the non-filterable
	 * public-field allowlist on the site-info ability.
	 *
	 * @return string[]
	 */
	public static function editable_statuses(): array {
		return array( 'draft', 'pending', 'auto-draft' );
	}

	/**
	 * Validates a post type against the writable allowlist.
	 *
	 * @param  mixed $post_type Requested post type.
	 * @return string|WP_Error Sanitised post type, or an error.
	 */
	public static function validate_post_type( $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		$allowed   = self::return_writable_post_types();

		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'invalid_post_type',
				sprintf( 'Post type "%s" is not registered on this site.', $post_type ),
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( $post_type, $allowed, true ) ) {
			return new WP_Error(
				'post_type_not_writable',
				sprintf(
					'Post type "%s" cannot be written through this ability. Writable types are: %s. Other post types have purpose-built abilities that enforce their own rules - look for one named after the content type before assuming this is a bug.',
					$post_type,
					implode( ', ', $allowed )
				),
				array( 'status' => 403 )
			);
		}

		return $post_type;
	}

	/**
	 * Validates a requested post_parent.
	 *
	 * WordPress stores post_parent for a non-hierarchical type and then
	 * ignores it everywhere, so silently accepting one would tell the caller
	 * their parent was honoured when nothing will ever read it. That is an
	 * error here, not a dropped field.
	 *
	 * @param  mixed  $parent_id Requested parent ID. 0 means no parent.
	 * @param  string $post_type Post type of the post being written.
	 * @param  int    $post_id   ID of the post being updated, 0 when creating.
	 * @return int|WP_Error Validated parent ID, or an error.
	 */
	public static function validate_parent( $parent_id, string $post_type, int $post_id = 0 ) {
		$parent_id = (int) $parent_id;

		if ( 0 === $parent_id ) {
			return 0;
		}

		if ( ! is_post_type_hierarchical( $post_type ) ) {
			return new WP_Error(
				'post_type_not_hierarchical',
				sprintf(
					'Post type "%s" is not hierarchical, so it cannot have a parent. WordPress would store post_parent and then ignore it. Omit post_parent.',
					$post_type
				),
				array( 'status' => 400 )
			);
		}

		$parent = get_post( $parent_id );

		if ( ! $parent ) {
			return new WP_Error(
				'parent_not_found',
				sprintf( 'Parent post %d does not exist.', $parent_id ),
				array( 'status' => 400 )
			);
		}

		if ( $parent->post_type !== $post_type ) {
			return new WP_Error(
				'parent_type_mismatch',
				sprintf(
					'Parent post %d is a "%s", not a "%s". A parent must be the same post type as its child.',
					$parent_id,
					$parent->post_type,
					$post_type
				),
				array( 'status' => 400 )
			);
		}

		if ( $post_id > 0 ) {
			if ( $parent_id === $post_id ) {
				return new WP_Error(
					'parent_is_self',
					'A post cannot be its own parent.',
					array( 'status' => 400 )
				);
			}

			if ( in_array( $post_id, get_post_ancestors( $parent_id ), true ) ) {
				return new WP_Error(
					'parent_would_cycle',
					sprintf(
						'Post %d is already an ancestor of post %d, so making it the child would create a loop.',
						$post_id,
						$parent_id
					),
					array( 'status' => 400 )
				);
			}
		}

		return $parent_id;
	}

	/**
	 * Converts supplied Markdown or HTML into serialized block markup.
	 *
	 * Both formats go through LH MCP Block Transformer, which accepts a
	 * source_format of either. Routing Markdown through a second engine
	 * would mean the same document submitted as Markdown and as HTML
	 * produced different blocks, and would lose the fallbacks array naming
	 * each element that degraded to a generic wrapper. get-post already
	 * reads through this same transformer, so a get-post/edit/update-post
	 * round trip stays on one engine in both directions.
	 *
	 * There is deliberately no local fallback converter. Storing
	 * approximately-converted content is worse than refusing: the caller
	 * cannot see the difference, and the post is written either way.
	 *
	 * @param  string $content       Source content.
	 * @param  string $source_format Either "markdown" or "html".
	 * @return array{content: string, block_count: int, fallback_count: int, fallbacks: array}|WP_Error
	 */
	public static function convert_content( string $content, string $source_format ) {
		if ( ! class_exists( 'LH_MCP_Block_Transformer' ) ) {
			return new WP_Error(
				'transformer_unavailable',
				'Content cannot be converted to block markup because the LH MCP Block Transformer plugin (lh-mcp-block-transformer) is not active on this site. Nothing has been written. Tell the user this directly and ask them to install and activate it, then retry. Do not attempt to produce block markup yourself.',
				array( 'status' => 503 )
			);
		}

		$result = LH_MCP_Block_Transformer::execute_convert_to_blocks(
			array(
				'content'       => $content,
				'source_format' => $source_format,
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'conversion_failed',
				'Content could not be converted to block markup. LH MCP Block Transformer returned: ' . $result->get_error_message() . ' Nothing has been written. This often means composer install needs to be run in the lh-mcp-block-transformer plugin directory, which a zip reinstall wipes.',
				array( 'status' => 502 )
			);
		}

		if ( 'success' !== ( $result['status'] ?? '' ) ) {
			$messages = array();

			foreach ( (array) ( $result['diagnostics'] ?? array() ) as $diagnostic ) {
				if ( is_array( $diagnostic ) && ! empty( $diagnostic['message'] ) ) {
					$messages[] = (string) $diagnostic['message'];
				}
			}

			return new WP_Error(
				'conversion_failed',
				'Content could not be converted to block markup. LH MCP Block Transformer reported: '
					. ( empty( $messages ) ? 'a failed conversion, with no diagnostics explaining why.' : implode( ' ', $messages ) )
					. ' Nothing has been written.',
				array( 'status' => 502 )
			);
		}

		$blocks = (string) ( $result['serialized_blocks'] ?? '' );

		if ( '' === trim( $blocks ) ) {
			return new WP_Error(
				'conversion_empty',
				'Content converted to empty block markup. Nothing has been written, since saving this would have silently emptied the post.',
				array( 'status' => 502 )
			);
		}

		return array(
			'content'        => $blocks,
			'block_count'    => (int) ( $result['block_count'] ?? 0 ),
			'fallback_count' => (int) ( $result['fallback_count'] ?? 0 ),
			'fallbacks'      => (array) ( $result['fallbacks'] ?? array() ),
		);
	}

	/**
	 * Resolves the content parameters into block markup.
	 *
	 * content_md and content_html are mutually exclusive rather than
	 * merged or precedence-ordered: supplying both is a caller mistake, and
	 * silently picking one would write content the caller did not review.
	 *
	 * @param  array $input Raw ability input.
	 * @return array{supplied: bool, content: string, block_count: int, fallback_count: int, fallbacks: array}|WP_Error
	 */
	public static function resolve_content( array $input ) {
		$has_md   = isset( $input['content_md'] ) && '' !== trim( (string) $input['content_md'] );
		$has_html = isset( $input['content_html'] ) && '' !== trim( (string) $input['content_html'] );

		if ( $has_md && $has_html ) {
			return new WP_Error(
				'ambiguous_content',
				'Pass either content_md or content_html, not both. Nothing has been written.',
				array( 'status' => 400 )
			);
		}

		if ( ! $has_md && ! $has_html ) {
			return array(
				'supplied'       => false,
				'content'        => '',
				'block_count'    => 0,
				'fallback_count' => 0,
				'fallbacks'      => array(),
			);
		}

		$converted = self::convert_content(
			$has_md ? (string) $input['content_md'] : (string) $input['content_html'],
			$has_md ? 'markdown' : 'html'
		);

		if ( is_wp_error( $converted ) ) {
			return $converted;
		}

		$converted['supplied'] = true;

		return $converted;
	}

	/**
	 * Validates a meta payload, mirroring how core's REST API decides what
	 * an editor may write.
	 *
	 * A key that is_protected_meta() reports as protected is refused unless
	 * it has been registered with show_in_rest - that registration is the
	 * site's own statement that the key is safe to write through an API.
	 * Without this check, update_post_meta() would cheerfully set
	 * _wp_page_template or lh-membership's access-control meta, changing how
	 * content is gated from an ability whose stated scope is drafting.
	 *
	 * A null value means delete the key.
	 *
	 * @param  mixed  $meta      Raw meta payload.
	 * @param  string $post_type Post type, for subtype-registered keys.
	 * @return array<string, mixed>|WP_Error Normalised key => value map.
	 */
	public static function validate_meta( $meta, string $post_type ) {
		if ( empty( $meta ) ) {
			return array();
		}

		if ( ! is_array( $meta ) ) {
			return new WP_Error(
				'invalid_meta',
				'meta must be an object of key => value pairs.',
				array( 'status' => 400 )
			);
		}

		$registered = array_merge(
			(array) get_registered_meta_keys( 'post', '' ),
			(array) get_registered_meta_keys( 'post', $post_type )
		);

		$validated = array();

		foreach ( $meta as $key => $value ) {
			$key = (string) $key;

			if ( '' === $key ) {
				return new WP_Error(
					'invalid_meta_key',
					'A meta key cannot be an empty string.',
					array( 'status' => 400 )
				);
			}

			if ( is_object( $value ) ) {
				return new WP_Error(
					'invalid_meta_value',
					sprintf( 'Meta key "%s" must be a scalar, an array, or null. Objects are not accepted.', $key ),
					array( 'status' => 400 )
				);
			}

			if ( is_protected_meta( $key, 'post' ) && empty( $registered[ $key ]['show_in_rest'] ) ) {
				return new WP_Error(
					'protected_meta',
					sprintf(
						'Meta key "%s" is protected and has not been registered with show_in_rest, so it cannot be written through this ability. Nothing has been written. Protected keys usually control how WordPress or another plugin treats the post - templates, access control, internal state - rather than holding editorial content.',
						$key
					),
					array( 'status' => 403 )
				);
			}

			$validated[ $key ] = $value;
		}

		return $validated;
	}

	/**
	 * Writes validated meta. A null value deletes the key.
	 *
	 * @param  int                  $post_id Post ID.
	 * @param  array<string, mixed> $meta    Validated meta map.
	 * @return string[] Keys written or deleted.
	 */
	public static function apply_meta( int $post_id, array $meta ): array {
		$applied = array();

		foreach ( $meta as $key => $value ) {
			if ( null === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}

			$applied[] = $key;
		}

		return $applied;
	}

	/**
	 * Validates a terms payload and resolves every slug or ID to a term ID.
	 *
	 * Resolution happens here rather than in wp_set_object_terms() so that a
	 * typo in a slug is an error the caller sees, not a silently created
	 * term or a silently dropped assignment.
	 *
	 * @param  mixed  $terms     Raw terms payload: taxonomy => array of slugs or IDs.
	 * @param  string $post_type Post type, to check the taxonomy applies to it.
	 * @return array<string, int[]>|WP_Error Taxonomy => term IDs.
	 */
	public static function validate_terms( $terms, string $post_type ) {
		if ( empty( $terms ) ) {
			return array();
		}

		if ( ! is_array( $terms ) ) {
			return new WP_Error(
				'invalid_terms',
				'terms must be an object of taxonomy => array of term slugs or IDs.',
				array( 'status' => 400 )
			);
		}

		$validated = array();

		foreach ( $terms as $taxonomy => $requested ) {
			$taxonomy = sanitize_key( (string) $taxonomy );

			if ( ! taxonomy_exists( $taxonomy ) ) {
				return new WP_Error(
					'invalid_taxonomy',
					sprintf( 'Taxonomy "%s" is not registered on this site.', $taxonomy ),
					array( 'status' => 400 )
				);
			}

			if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				return new WP_Error(
					'taxonomy_not_for_post_type',
					sprintf( 'Taxonomy "%s" is not registered for post type "%s".', $taxonomy, $post_type ),
					array( 'status' => 400 )
				);
			}

			$taxonomy_object = get_taxonomy( $taxonomy );

			if ( isset( $taxonomy_object->cap->assign_terms ) && ! current_user_can( $taxonomy_object->cap->assign_terms ) ) {
				return new WP_Error(
					'cannot_assign_terms',
					sprintf( 'You do not have permission to assign terms in the "%s" taxonomy.', $taxonomy ),
					array( 'status' => 403 )
				);
			}

			$term_ids = array();

			foreach ( (array) $requested as $identifier ) {
				$term = null;

				if ( is_int( $identifier ) || ( is_string( $identifier ) && ctype_digit( $identifier ) ) ) {
					$term = get_term_by( 'id', (int) $identifier, $taxonomy );
				}

				if ( ! $term ) {
					$term = get_term_by( 'slug', (string) $identifier, $taxonomy );
				}

				if ( ! $term ) {
					return new WP_Error(
						'term_not_found',
						sprintf(
							'No term matching "%s" exists in taxonomy "%s". Nothing has been written. Terms are never created by this ability - create it first if it should exist.',
							(string) $identifier,
							$taxonomy
						),
						array( 'status' => 400 )
					);
				}

				$term_ids[] = (int) $term->term_id;
			}

			$validated[ $taxonomy ] = array_values( array_unique( $term_ids ) );
		}

		return $validated;
	}

	/**
	 * Applies validated terms, replacing rather than appending.
	 *
	 * Replace semantics mean a taxonomy named in the payload ends up with
	 * exactly the terms given. A taxonomy not named is left untouched.
	 *
	 * @param  int                  $post_id Post ID.
	 * @param  array<string, int[]> $terms   Validated taxonomy => term IDs.
	 * @return array<string, int[]> What was applied.
	 */
	public static function apply_terms( int $post_id, array $terms ): array {
		foreach ( $terms as $taxonomy => $term_ids ) {
			wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
		}

		return $terms;
	}

	/**
	 * Shared input-schema fragments, so create-post and update-post cannot
	 * drift in how they describe the same parameter.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function shared_input_properties(): array {
		return array(
			'content_md'   => array(
				'type'        => 'string',
				'description' => 'Body content as Markdown. Converted to Gutenberg block markup before saving. Mutually exclusive with content_html.',
			),
			'content_html' => array(
				'type'        => 'string',
				'description' => 'Body content as HTML. Converted to Gutenberg block markup before saving. Use this rather than content_md when the content needs layout, media, buttons or other structure Markdown cannot express - call lh-mcp-block-transformer/get-pattern-guide to learn which HTML shapes produce which blocks. Mutually exclusive with content_md.',
			),
			'excerpt'      => array(
				'type'        => 'string',
				'description' => 'Plain-text excerpt.',
			),
			'post_parent'  => array(
				'type'        => 'integer',
				'description' => 'Parent post ID, for hierarchical post types only. Must be the same post type. Passing this for a non-hierarchical type is an error rather than a silently ignored field. 0 means no parent.',
			),
			'meta'         => array(
				'type'                 => 'object',
				'description'          => 'Post meta as key => value. Protected keys (those starting with an underscore) are refused unless registered with show_in_rest. A null value deletes the key.',
				'additionalProperties' => true,
			),
			'terms'        => array(
				'type'                 => 'object',
				'description'          => 'Taxonomy => array of term slugs or term IDs, e.g. {"category": ["news"], "post_tag": ["touch", "season-2026"]}. Replaces the post\'s terms in each taxonomy named; taxonomies not named are left alone. Terms must already exist - none are created.',
				'additionalProperties' => true,
			),
		);
	}

	/**
	 * Shared output-schema fragments describing the result of a write.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function shared_output_properties(): array {
		return array(
			'id'             => array( 'type' => 'integer' ),
			'post_type'      => array(
				'type'        => 'string',
				'description' => 'The post type actually written. Echoed back so a caller who meant "page" and omitted the parameter can see they got a "post".',
			),
			'status'         => array( 'type' => 'string' ),
			'title'          => array( 'type' => 'string' ),
			'slug'           => array( 'type' => 'string' ),
			'edit_link'      => array( 'type' => 'string' ),
			'preview_link'   => array( 'type' => 'string' ),
			'block_count'    => array(
				'type'        => 'integer',
				'description' => 'Blocks produced from the supplied content. 0 when no content was supplied on this call.',
			),
			'fallback_count' => array(
				'type'        => 'integer',
				'description' => 'How many elements could not be matched to a specific block and degraded to a generic wrapper. Non-zero is not a failure - the content saved correctly - but it means the markup did not produce the blocks it may have been intended to.',
			),
			'fallbacks'      => array(
				'type'        => 'array',
				'description' => 'Each degraded element, with its source tag, selector and sanitized HTML. Reshape these per lh-mcp-block-transformer/get-pattern-guide if a specific block was intended.',
				'items'       => array( 'type' => 'object' ),
			),
			'meta_applied'   => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'terms_applied'  => array(
				'type'                 => 'object',
				'description'          => 'Taxonomy => term IDs actually set.',
				'additionalProperties' => true,
			),
			'notice'         => array(
				'type'        => 'string',
				'description' => 'Empty in the normal case. When non-empty it describes something the caller should pass on to the user.',
			),
		);
	}
}
