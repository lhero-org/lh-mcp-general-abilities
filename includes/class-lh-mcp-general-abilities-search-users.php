<?php
/**
 * The search-users ability.
 *
 * @package LH_MCP_General_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches WordPress users by ID, email, display name, last name or first
 * name, ranked by match quality.
 *
 * Overrides permission_callback() to require list_users rather than the
 * plugin's usual edit_posts gate, because the results include email
 * addresses. That is the only override here.
 *
 * Converted from LH_MCP_General_Abilities_Users, which held this single
 * ability and so was already one class per ability; the file and class are
 * renamed to match the ability, as the other converted classes do.
 *
 * One signature change comes with the conversion, and it fixes a latent
 * fatal. The old execute_search_users() was declared : array but returned a
 * WP_Error on empty input and again from its catch block. The first return
 * threw a TypeError inside the try, which the catch swallowed; the catch then
 * returned a WP_Error itself, from outside the try, throwing a second
 * TypeError that nothing caught. An empty search string therefore produced a
 * fatal rather than the intended 400. The base class declares execute() with
 * no return type, so the declaration here carries none either and both
 * WP_Error returns now behave as written.
 *
 * Declared unguarded, deliberately - see the note on the main plugin class.
 */
final class LH_MCP_General_Abilities_Search_Users extends LH_MCP_General_Abilities_Ability {

	/**
	 * Ability name.
	 *
	 * @return string
	 */
	public static function name(): string {
		return LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() . '/search-users';
	}

	/**
	 * Ability label.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'Search Users', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
	}

	/**
	 * Ability description.
	 *
	 * @return string
	 */
	public static function description(): string {
		return __( 'Search WordPress users by ID, email, display name, last name, or first name. Uses a priority-ranked approach: exact matches beat partial matches, and last name beats first name. Multi-word searches run an AND pass first, falling back to OR if no results are found.', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
	}

	/**
	 * Ability category.
	 *
	 * @return string
	 */
	public static function category(): string {
		return 'user-management';
	}

	/**
	 * Requires list_users rather than the plugin-wide edit_posts gate,
	 * because this ability returns email addresses.
	 *
	 * @return callable
	 */
	public static function permission_callback() {
		return array( self::class, 'check_permissions' );
	}

	/**
	 * Permission check for this ability.
	 *
	 * @param  array|null $input Ability input (unused).
	 * @return true|WP_Error
	 */
	public static function check_permissions( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'list_users' ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to search users.', array( 'status' => 403 ) );
		}
		return true;
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
				'search'  => array(
					'type'        => 'string',
					'description' => 'Search string. May be a user ID, email address, display name, first name, last name, or multiple words (e.g. "Peter Shaw").',
				),
				'limit'   => array(
					'type'        => 'integer',
					'description' => 'Maximum number of results to return. Default 20, max 100.',
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'site_id' => array(
					'type'        => 'integer',
					'description' => 'Multisite only: scope user meta lookup to this site ID.',
				),
			),
			'required'   => array( 'search' ),
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
				'search'    => array( 'type' => 'string', 'description' => 'The search string used.' ),
				'pass_used' => array( 'type' => 'string', 'description' => 'AND, OR (fallback), or single-term - indicates which matching pass produced results.' ),
				'total'     => array( 'type' => 'integer', 'description' => 'Total matching users (before limit).' ),
				'users'     => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'           => array( 'type' => 'integer' ),
							'user_login'   => array( 'type' => 'string' ),
							'user_email'   => array( 'type' => 'string' ),
							'display_name' => array( 'type' => 'string' ),
							'first_name'   => array( 'type' => 'string' ),
							'last_name'    => array( 'type' => 'string' ),
							'roles'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
							'profile_url'  => array( 'type' => 'string' ),
							'admin_url'    => array( 'type' => 'string' ),
							'matched_on'   => array( 'type' => 'string', 'description' => 'The field and match type that determined this result\'s rank.' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Execute the search.
	 *
	 * Priority order per term:
	 *   1. Exact ID (numeric input only)
	 *   2. Exact email
	 *   3. Exact display_name (case-insensitive)
	 *   4. Exact last_name (usermeta)
	 *   5. Exact first_name (usermeta)
	 *   6. Partial email  (LIKE %term%)
	 *   7. Partial display_name
	 *   8. Partial last_name
	 *   9. Partial first_name
	 *
	 * Multi-word input: AND pass first (user must match all terms at any
	 * priority), OR fallback if AND yields nothing. Each user is ranked by
	 * their best (lowest) priority across all matching terms.
	 *
	 * @param  array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		global $wpdb;

		$switched = false;

		try {

			$input   = is_array( $input ) ? $input : array();
			$search  = isset( $input['search'] ) ? trim( $input['search'] ) : '';
			$limit   = isset( $input['limit'] ) ? min( 100, max( 1, (int) $input['limit'] ) ) : 20;
			$site_id = isset( $input['site_id'] ) ? (int) $input['site_id'] : null;

			if ( '' === $search ) {
				return new WP_Error( 'missing_param', 'search is required.', array( 'status' => 400 ) );
			}

			// On multisite, scope to the correct usermeta table prefix.
			if ( $site_id && is_multisite() ) {
				switch_to_blog( $site_id );
				$switched = true;
			}

			$users_table    = $wpdb->users;
			$usermeta_table = $wpdb->usermeta;

			// Split into terms; single-word searches produce one term.
			$raw_terms = array_values( array_filter( array_map( 'trim', explode( ' ', $search ) ) ) );

			/**
			 * Build the UNION ALL priority query for a single term.
			 *
			 * Each of the nine fragments below interpolates $users_table or
			 * $usermeta_table into the SQL before prepare() sees it, which is
			 * what WordPress.DB.PreparedSQL.InterpolatedNotPrepared reports
			 * on every one of them. Both variables come straight from
			 * $wpdb->users and $wpdb->usermeta - WordPress's own prefixed
			 * table names, never caller input - and a table name cannot be
			 * passed as a prepare() placeholder in any case. Every value that
			 * does originate with the caller is already a %d or %s.
			 *
			 * The annotations are per line rather than one block disable, so
			 * a later edit that interpolated something untrusted would still
			 * be reported instead of landing inside a silenced region.
			 */
			$build_term_query = function ( string $term ) use ( $wpdb, $users_table, $usermeta_table ): string {
				$is_numeric   = ctype_digit( $term );
				$exact        = $term;
				$partial_like = '%' . $wpdb->esc_like( $term ) . '%';
				$parts        = array();

				// Priority 1 - exact ID (numeric only).
				if ( $is_numeric ) {
					$parts[] = $wpdb->prepare( "SELECT 1 AS priority, ID FROM {$users_table} WHERE ID = %d", (int) $term ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}

				// Priority 2 - exact email.
				$parts[] = $wpdb->prepare( "SELECT 2 AS priority, ID FROM {$users_table} WHERE LOWER(user_email) = LOWER(%s)", $exact ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				// Priority 3 - exact display_name.
				$parts[] = $wpdb->prepare( "SELECT 3 AS priority, ID FROM {$users_table} WHERE LOWER(display_name) = LOWER(%s)", $exact ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				// Priority 4 - exact last_name.
				$parts[] = $wpdb->prepare( "SELECT 4 AS priority, user_id AS ID FROM {$usermeta_table} WHERE meta_key = 'last_name'  AND LOWER(meta_value) = LOWER(%s)", $exact ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				// Priority 5 - exact first_name.
				$parts[] = $wpdb->prepare( "SELECT 5 AS priority, user_id AS ID FROM {$usermeta_table} WHERE meta_key = 'first_name' AND LOWER(meta_value) = LOWER(%s)", $exact ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				// Priority 6 - partial email.
				$parts[] = $wpdb->prepare( "SELECT 6 AS priority, ID FROM {$users_table} WHERE user_email LIKE %s", $partial_like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				// Priority 7 - partial display_name.
				$parts[] = $wpdb->prepare( "SELECT 7 AS priority, ID FROM {$users_table} WHERE display_name LIKE %s", $partial_like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				// Priority 8 - partial last_name.
				$parts[] = $wpdb->prepare( "SELECT 8 AS priority, user_id AS ID FROM {$usermeta_table} WHERE meta_key = 'last_name'  AND meta_value LIKE %s", $partial_like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				// Priority 9 - partial first_name.
				$parts[] = $wpdb->prepare( "SELECT 9 AS priority, user_id AS ID FROM {$usermeta_table} WHERE meta_key = 'first_name' AND meta_value LIKE %s", $partial_like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				return 'SELECT MIN(priority) AS best_priority, ID FROM ( ' . implode( ' UNION ALL ', $parts ) . ' ) AS u GROUP BY ID';
			};

			$priority_labels = array(
				1 => 'exact ID',
				2 => 'exact email',
				3 => 'exact display_name',
				4 => 'exact last_name',
				5 => 'exact first_name',
				6 => 'partial email',
				7 => 'partial display_name',
				8 => 'partial last_name',
				9 => 'partial first_name',
			);

			/**
			 * Run an AND or OR pass across all terms.
			 */
			$run_pass = function ( array $terms, string $mode ) use ( $wpdb, $build_term_query ): array {
				$term_results = array();

				foreach ( $terms as $term ) {
					$sql = $build_term_query( $term );

					/*
					 * $sql is assembled entirely from $wpdb->prepare()
					 * fragments joined by UNION ALL, so nothing unprepared
					 * from the caller reaches it - but the sniffs cannot see
					 * through the closure that built it, and report the
					 * variable as unsafely assigned. The direct query is
					 * deliberate: this is a nine-way priority ranking across
					 * two tables, which WP_User_Query cannot express. Its
					 * results are deliberately not cached either, because the
					 * search string is effectively unbounded, so a cache entry
					 * would almost never be read a second time.
					 */
					$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$map  = array();

					foreach ( $rows as $row ) {
						$map[ (int) $row['ID'] ] = (int) $row['best_priority'];
					}

					$term_results[] = $map;
				}

				if ( 'AND' === $mode ) {
					// Intersect IDs present in ALL terms.
					$common_ids = null;

					foreach ( $term_results as $map ) {
						$ids        = array_keys( $map );
						$common_ids = null === $common_ids ? $ids : array_intersect( $common_ids, $ids );
					}

					$common_ids = $common_ids ?? array();

					// Best priority per user = minimum across all terms.
					$result = array();

					foreach ( $common_ids as $id ) {
						$best = PHP_INT_MAX;

						foreach ( $term_results as $map ) {
							if ( isset( $map[ $id ] ) ) {
								$best = min( $best, $map[ $id ] );
							}
						}

						$result[ $id ] = $best;
					}
				} else {
					// OR - union all, best priority wins per user.
					$result = array();

					foreach ( $term_results as $map ) {
						foreach ( $map as $id => $priority ) {
							if ( ! isset( $result[ $id ] ) || $priority < $result[ $id ] ) {
								$result[ $id ] = $priority;
							}
						}
					}
				}

				asort( $result ); // Sort by priority ascending.

				return $result;
			};

			// Run AND pass first; fall back to OR if no results.
			if ( count( $raw_terms ) > 1 ) {
				$pass_used = 'AND';
				$ranked    = $run_pass( $raw_terms, 'AND' );

				if ( empty( $ranked ) ) {
					$ranked    = $run_pass( $raw_terms, 'OR' );
					$pass_used = 'OR (fallback)';
				}
			} else {
				$ranked    = $run_pass( $raw_terms, 'OR' );
				$pass_used = 'single-term';
			}

			if ( $switched ) {
				restore_current_blog();
				$switched = false;
			}

			if ( empty( $ranked ) ) {
				return array(
					'search'    => $search,
					'pass_used' => $pass_used,
					'total'     => 0,
					'users'     => array(),
				);
			}

			// Fetch full user objects for top $limit results.
			$top_ids = array_slice( array_keys( $ranked ), 0, $limit, true );
			$users   = array();

			foreach ( $top_ids as $user_id ) {
				$user = get_userdata( $user_id );

				if ( ! $user ) {
					continue;
				}

				$users[] = array(
					'id'           => $user->ID,
					'user_login'   => $user->user_login,
					'user_email'   => $user->user_email,
					'display_name' => $user->display_name,
					'first_name'   => $user->first_name,
					'last_name'    => $user->last_name,
					'roles'        => $user->roles,
					'profile_url'  => get_author_posts_url( $user->ID ),
					'admin_url'    => admin_url( 'user-edit.php?user_id=' . $user->ID ),
					'matched_on'   => $priority_labels[ $ranked[ $user_id ] ] ?? 'unknown',
				);
			}

			return array(
				'search'    => $search,
				'pass_used' => $pass_used,
				'total'     => count( $ranked ),
				'users'     => $users,
			);

		} catch ( \Throwable $e ) {
			/*
			 * Restore the blog context before returning. Without this a
			 * failure after switch_to_blog() leaves the whole request on the
			 * wrong site, which affects everything that runs afterwards and
			 * is far harder to diagnose than the original error.
			 */
			if ( $switched ) {
				restore_current_blog();
			}

			/*
			 * Not debug code, despite the sniff: this is the only record
			 * anywhere that this ability returned a 500, and the caller gets
			 * the message but no file or line. Removing it would make a
			 * production failure here effectively invisible.
			 */
			error_log( 'lh-mcp-general-abilities/search-users error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return new WP_Error( 'search_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}
