<?php
/**
 * Shared helpers for the post-read abilities.
 *
 * @package LH_MCP_General_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What get-post and list-posts both need: the readable post-type allowlist,
 * date formatting, and the Markdown rendering of stored block markup.
 *
 * The counterpart to LH_MCP_General_Abilities_Post_Write_Support. Read and
 * write are kept apart deliberately, and the allowlists especially so: each
 * has its own filter, because permitting an AI to write a post type is a
 * different decision, with different risk, from permitting it to read one.
 * Sharing one filter would couple them by accident.
 *
 * Declared unguarded, deliberately - see the note on the main plugin class.
 */
final class LH_MCP_General_Abilities_Post_Read_Support {

	/**
	 * Post types get-post will return.
	 *
	 * Separate from
	 * LH_MCP_General_Abilities_Post_Write_Support::return_writable_post_types(),
	 * with its own filter, for the reason in the class docblock.
	 *
	 * Note list-posts is deliberately not constrained by this: it accepts any
	 * registered post type today, and whether it should keep doing so is its
	 * own decision, not a side effect of tidying get-post.
	 *
	 * @return string[]
	 */
	public static function return_readable_post_types(): array {
		return (array) apply_filters(
			LH_MCP_General_Abilities_Plugin::return_plugin_namespace() . '_return_readable_post_types',
			array( 'post', 'page' )
		);
	}

	/**
	 * Formats one of a post's date fields as an ISO 8601 UTC timestamp.
	 *
	 * Two WordPress behaviours meet here, and both have bitten this code.
	 *
	 * First, WordPress leaves post_date_gmt (and post_modified_gmt) as the
	 * zeroed string '0000-00-00 00:00:00' until a post is first published,
	 * while post_date holds the real authored time in the site's timezone.
	 * The original guard tested truthiness, and a zeroed datetime is a
	 * perfectly non-empty string, so it passed straight through to
	 * mysql2date() and came back as a timestamp in year -1. get_post_datetime()
	 * is used instead because it rejects the zeroed value explicitly rather
	 * than trying to parse it. When the GMT column is unusable this falls
	 * back to the local column rather than returning null: an unpublished
	 * post's authored time is real information, and a caller ordering drafts
	 * needs it. Null means only that neither column holds anything usable.
	 *
	 * Second - and this is the non-obvious one - get_post_datetime()'s
	 * $source argument selects which column is READ and nothing more. Its
	 * final line is return $datetime->setTimezone( $wp_timezone ), so the
	 * object handed back is always in the site's timezone even when 'gmt'
	 * was asked for. Passing 'gmt' therefore does not produce a UTC object,
	 * and the conversion below has to happen unconditionally rather than
	 * only on the fallback path. Doing it only on the fallback is what
	 * 1.7.1 did, and it rendered published posts at +10:00 while drafts came
	 * back at +00:00 - same instants, inconsistent offsets, and a schema
	 * that promised UTC.
	 *
	 * @param  WP_Post $post  Post to read.
	 * @param  string  $field Either 'date' or 'modified'.
	 * @return string|null ISO 8601 UTC timestamp, or null.
	 */
	public static function iso_datetime( WP_Post $post, string $field ): ?string {
		$datetime = get_post_datetime( $post, $field, 'gmt' );

		if ( false === $datetime ) {
			$datetime = get_post_datetime( $post, $field, 'local' );
		}

		if ( false === $datetime ) {
			return null;
		}

		return $datetime->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'c' );
	}

	/**
	 * Convert a post's stored block markup to Markdown.
	 *
	 * Delegates to LH MCP Block Transformer (the same FormatBridge path as
	 * its convert-from-blocks ability) rather than converting here. The
	 * original implementation rendered the_content and ran a regex pass over
	 * the resulting HTML, which was lossy in both directions: block
	 * attributes were discarded, so the Markdown could not be converted back
	 * into equivalent blocks, and anything the regexes did not recognise was
	 * silently flattened by strip_tags().
	 *
	 * Note this reflects what is stored in the editor, not what renders on
	 * the front end: the_content filters, shortcode expansion, and
	 * dynamic-block output do not contribute. For an ability whose purpose is
	 * reading content in order to edit it, the stored markup is the correct
	 * source.
	 *
	 * There is deliberately no local fallback converter. If the transformer
	 * is unavailable, content is returned as null with an actionable notice,
	 * so a caller is told plainly what is missing instead of receiving
	 * degraded output that looks correct. content_raw is unaffected either
	 * way and always carries the full stored content.
	 *
	 * A non-success result is not automatically a fault, and the transformer
	 * says which it is: it returns a diagnostics array naming the reason.
	 * One code, format_bridge_validation_failed, means only that the content
	 * carries no serialized block comments - that is, it has no block markup
	 * at all, so there is nothing to convert. That is not a statement about
	 * the post's age: it is equally true of classic-editor content, a bare
	 * shortcode, a bare embed URL, or plain text saved by any path that does
	 * not produce blocks. That case is reported as its own source, 'classic',
	 * because nothing is broken and there is nothing for anyone to fix;
	 * treating it as breakage sends the caller chasing a non-existent
	 * problem. Any other diagnostic has its message passed through verbatim,
	 * since the transformer's own account of what went wrong is more useful
	 * than a generic one.
	 *
	 * @param  WP_Post $post Post whose content should be converted.
	 * @return array{content: string|null, source: string, notice: string}
	 */
	public static function content_markdown( WP_Post $post ): array {
		$raw = (string) $post->post_content;

		if ( '' === trim( $raw ) ) {
			return array(
				'content' => '',
				'source'  => 'empty',
				'notice'  => '',
			);
		}

		if ( ! class_exists( 'LH_MCP_Block_Transformer' ) ) {
			return array(
				'content' => null,
				'source'  => 'unavailable',
				'notice'  => 'content_markdown could not be produced because the LH MCP Block Transformer plugin (lh-mcp-block-transformer) is not active on this site. Tell the user this directly and ask them to network-activate it, then retry. Do not attempt to convert content_raw to Markdown yourself; content_raw is the complete, unaffected block markup.',
			);
		}

		$result = LH_MCP_Block_Transformer::execute_convert_from_blocks(
			array(
				'content'       => $raw,
				'target_format' => 'markdown',
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'content' => null,
				'source'  => 'failed',
				'notice'  => 'content_markdown could not be produced. LH MCP Block Transformer returned: ' . $result->get_error_message() . ' Tell the user this directly — it usually means composer install needs to be run in the lh-mcp-block-transformer plugin directory, which a zip reinstall wipes. content_raw is the complete, unaffected block markup.',
			);
		}

		if ( 'success' !== ( $result['status'] ?? '' ) ) {
			$codes    = array();
			$messages = array();

			foreach ( (array) ( $result['diagnostics'] ?? array() ) as $diagnostic ) {
				if ( ! is_array( $diagnostic ) ) {
					continue;
				}
				if ( ! empty( $diagnostic['code'] ) ) {
					$codes[] = (string) $diagnostic['code'];
				}
				if ( ! empty( $diagnostic['message'] ) ) {
					$messages[] = (string) $diagnostic['message'];
				}
			}

			if ( in_array( 'format_bridge_validation_failed', $codes, true ) ) {
				return array(
					'content' => null,
					'source'  => 'classic',
					'notice'  => 'This post has no block markup, so there are no blocks to convert to Markdown. That is normal for content written in the classic editor, or saved by any other path that does not produce blocks, and it says nothing about how old the post is. Nothing is broken, nothing needs installing or activating, and there is nothing here to report as a problem. Read and edit content_raw directly: it is the post\'s complete stored content in whatever form it was saved, which may be HTML, plain text, shortcodes or a bare embed URL, so keep that form rather than converting it.',
				);
			}

			return array(
				'content' => null,
				'source'  => 'failed',
				'notice'  => 'content_markdown could not be produced. LH MCP Block Transformer reported: '
					. ( empty( $messages ) ? 'a failed conversion, with no diagnostics explaining why.' : implode( ' ', $messages ) )
					. ' Tell the user this directly. content_raw is the complete, unaffected block markup.',
			);
		}

		return array(
			'content' => (string) ( $result['converted_content'] ?? '' ),
			'source'  => 'lh-mcp-block-transformer',
			'notice'  => '',
		);
	}
}
