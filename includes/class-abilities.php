<?php
/**
 * SWPS Abilities — registry, validator, redactor, execute pipeline, and activity log.
 *
 * Exposes headless SEO operations as machine-callable abilities.  Three integration
 * layers (all feature-detected):
 *  1. WP Abilities API (WP ≥ 6.9) — hooked on wp_abilities_api_init.
 *  2. REST fallback — namespace swps/v1/abilities (see class-abilities-rest.php).
 *  3. Settings panel — per-ability enable toggles (see class-abilities-settings.php).
 *
 * Ability definitions and callbacks live in trait SWPS_Ability_Defs
 * (includes/trait-ability-defs.php) to keep this file under 500 lines.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abilities registry, validator, redactor, execute pipeline, and activity log.
 */
class SWPS_Abilities {

	use SWPS_Ability_Defs;

	/** DB schema version — bump whenever the log table DDL changes. */
	public const DB_VERSION = '1';

	/** Option key for the log table schema version. */
	public const OPT_DB_VER = 'swps_abilities_db_version';

	/** Option key: array of ability names the admin has explicitly enabled/disabled. */
	public const OPT_ENABLED = 'swps_abilities_enabled';

	/** Log table name (without prefix). */
	public const LOG_TABLE = 'swps_ability_log';

	/** Pattern to detect sensitive input field names for redaction. */
	private const REDACT_PATTERN = '/(?:key|secret|token|password)/i';

	/**
	 * Canonical ability definitions.
	 *
	 * Populated by SWPS_Ability_Defs::build_defs() in the constructor.
	 * Each def: name, label, description, write (bool), input_schema, output_schema, callback.
	 *
	 * @var array[]
	 */
	private array $defs = array();

	/**
	 * Constructor — build ability definitions via trait.
	 */
	public function __construct() {
		$this->defs = $this->build_defs();
	}

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Return all ability definitions.
	 *
	 * @return array[]
	 */
	public function get_defs(): array {
		return $this->defs;
	}

	/**
	 * Execute an ability by name.
	 *
	 * Pipeline: enabled-check → validate → callback → log → result.
	 *
	 * @param string $name  Ability name (e.g. 'stratawp-seo/get-bot-stats').
	 * @param array  $input Raw caller-supplied input.
	 * @return array|WP_Error
	 */
	public function execute( string $name, array $input = array() ) {
		$def = $this->find_def( $name );
		if ( null === $def ) {
			return new WP_Error( 'swps_ability_not_found', __( 'Unknown ability.', 'stratawp-seo' ) );
		}

		if ( ! $this->is_enabled( $name ) ) {
			$this->log_activity( $name, $input, 'denied:disabled' );
			return new WP_Error( 'swps_ability_disabled', __( 'This ability is disabled.', 'stratawp-seo' ) );
		}

		$validated = self::validate_input( $input, $def['input_schema'] ?? array() );
		if ( is_wp_error( $validated ) ) {
			$this->log_activity( $name, $input, 'denied:validation' );
			return $validated;
		}

		$result = call_user_func( $def['callback'], $validated );

		if ( is_wp_error( $result ) ) {
			$this->log_activity( $name, $validated, 'error:' . $result->get_error_code() );
			return $result;
		}

		$this->log_activity( $name, $validated, 'ok' );

		return is_array( $result ) ? $result : array( 'result' => $result );
	}

	/**
	 * Whether an ability is currently enabled.
	 *
	 * Read abilities default ON; write abilities default OFF.
	 * Explicit per-ability override stored in OPT_ENABLED takes priority.
	 *
	 * @param string $name Ability name.
	 * @return bool
	 */
	public function is_enabled( string $name ): bool {
		$overrides = (array) get_option( self::OPT_ENABLED, array() );

		if ( array_key_exists( $name, $overrides ) ) {
			return (bool) $overrides[ $name ];
		}

		return $this->is_enabled_by_default( $name );
	}

	/**
	 * Default enabled state: READ=true, WRITE=false.
	 *
	 * @param string $name Ability name.
	 * @return bool
	 */
	public function is_enabled_by_default( string $name ): bool {
		$def = $this->find_def( $name );
		if ( null === $def ) {
			return false;
		}
		return ! $def['write'];
	}

	// =========================================================================
	// Pure static helpers (TDD-tested)
	// =========================================================================

	/**
	 * Validate caller input against a JSON Schema subset.
	 *
	 * Supported constraints: required (array), type (string/integer/boolean), enum.
	 * Unknown properties are stripped from the returned array.
	 *
	 * @param array $input  Raw input.
	 * @param array $schema JSON Schema object definition.
	 * @return array|WP_Error Cleaned input array, or WP_Error on failure.
	 */
	public static function validate_input( array $input, array $schema ) {
		if ( empty( $schema ) || empty( $schema['properties'] ) ) {
			return $input;
		}

		$properties = (array) $schema['properties'];
		$required   = isset( $schema['required'] ) ? (array) $schema['required'] : array();

		// Required check.
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				return new WP_Error(
					'swps_ability_missing_required',
					/* translators: %s: field name */
					sprintf( __( "Required input field '%s' is missing.", 'stratawp-seo' ), $field )
				);
			}
		}

		$clean = array();

		foreach ( $properties as $field => $rule ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$value = $input[ $field ];

			// Type check.
			if ( isset( $rule['type'] ) ) {
				$type_ok = self::check_type( $value, $rule['type'] );
				if ( ! $type_ok ) {
					return new WP_Error(
						'swps_ability_invalid_type',
						/* translators: 1: field name, 2: expected type */
						sprintf(
							__( "Field '%1\$s' must be of type %2\$s.", 'stratawp-seo' ),
							$field,
							$rule['type']
						)
					);
				}
			}

			// Enum check.
			if ( isset( $rule['enum'] ) && ! in_array( $value, (array) $rule['enum'], true ) ) {
				return new WP_Error(
					'swps_ability_invalid_enum',
					/* translators: %s: field name */
					sprintf( __( "Field '%s' contains an invalid value.", 'stratawp-seo' ), $field )
				);
			}

			$clean[ $field ] = $value;
		}

		return $clean;
	}

	/**
	 * Redact sensitive fields from an input array before logging.
	 *
	 * Removes any key whose name matches /key|secret|token|password/i.
	 *
	 * @param array $input Input array.
	 * @return array Redacted copy.
	 */
	public static function redact( array $input ): array {
		$out = array();
		foreach ( $input as $k => $v ) {
			if ( preg_match( self::REDACT_PATTERN, (string) $k ) ) {
				continue;
			}
			$out[ $k ] = $v;
		}
		return $out;
	}

	// =========================================================================
	// DB: activity log
	// =========================================================================

	/**
	 * Create or upgrade the activity log table via dbDelta (idempotent).
	 */
	public static function create_log_table(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . self::LOG_TABLE;

		$sql = "CREATE TABLE {$table} (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ability        VARCHAR(100)    NOT NULL DEFAULT '',
			user_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			input_json     LONGTEXT                 DEFAULT NULL,
			result_summary VARCHAR(200)    NOT NULL DEFAULT '',
			created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_ability (ability),
			KEY idx_user (user_id),
			KEY idx_created (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Run DB upgrade when schema version is stale.  Called on plugins_loaded.
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::OPT_DB_VER ) ) {
			self::create_log_table();
			update_option( self::OPT_DB_VER, self::DB_VERSION );
		}
	}

	/**
	 * Drop the log table.  Called on uninstall.
	 */
	public static function drop_log_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::LOG_TABLE;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		// phpcs:enable
	}

	/**
	 * Prune log rows older than 90 days.  Hooked into the daily cron.
	 */
	public static function prune_log(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::LOG_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", 90 ) );
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Find a definition by name.
	 *
	 * @param string $name Ability name.
	 * @return array|null
	 */
	private function find_def( string $name ): ?array {
		foreach ( $this->defs as $def ) {
			if ( $def['name'] === $name ) {
				return $def;
			}
		}
		return null;
	}

	/**
	 * Write an activity log row.
	 *
	 * @param string $name           Ability name.
	 * @param array  $input          Validated input (will be redacted before storing).
	 * @param string $result_summary Short status string.
	 */
	private function log_activity( string $name, array $input, string $result_summary ): void {
		global $wpdb;

		if ( ! is_callable( 'get_current_user_id' ) ) {
			return;
		}

		$safe_input = self::redact( $input );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . self::LOG_TABLE,
			array(
				'ability'        => substr( $name, 0, 100 ),
				'user_id'        => (int) get_current_user_id(),
				'input_json'     => wp_json_encode( $safe_input ),
				'result_summary' => substr( $result_summary, 0, 200 ),
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * PHP type check matching JSON Schema primitive type names.
	 *
	 * @param mixed  $value Value to check.
	 * @param string $type  JSON Schema type name.
	 * @return bool
	 */
	private static function check_type( $value, string $type ): bool {
		switch ( $type ) {
			case 'string':
				return is_string( $value );
			case 'integer':
				return is_int( $value );
			case 'boolean':
				return is_bool( $value );
			case 'number':
				return is_int( $value ) || is_float( $value );
			case 'array':
				return is_array( $value );
			case 'object':
				return is_array( $value ) || is_object( $value );
			default:
				return true;
		}
	}
}
