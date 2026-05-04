<?php
/**
 * Local SEO — NAP (name/address/phone), opening hours, geo, area served,
 * and LocalBusiness JSON-LD output for brick-and-mortar / service-area sites.
 *
 * Settings page lives at admin.php?page=swps-local-seo. Schema is injected
 * on the homepage (and optionally a designated "contact" page) via wp_head.
 * Defers entirely when Yoast / RankMath / AIOSEO are active (same as
 * SWPS_Schema).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Local_SEO {

    public const OPTION_KEY = 'swps_local_seo';

    /**
     * Schema.org LocalBusiness sub-types we surface in the picker.
     * Not exhaustive — covers the most common small-business cases.
     */
    public const BUSINESS_TYPES = [
        'LocalBusiness'        => 'Local business (generic)',
        'Restaurant'           => 'Restaurant',
        'CafeOrCoffeeShop'     => 'Cafe / coffee shop',
        'Bakery'               => 'Bakery',
        'Store'                => 'Store / retail',
        'ClothingStore'        => 'Clothing store',
        'GroceryStore'         => 'Grocery store',
        'Dentist'              => 'Dentist',
        'MedicalClinic'        => 'Medical clinic',
        'Physician'            => 'Physician',
        'HealthAndBeautyBusiness' => 'Health & beauty',
        'BeautySalon'          => 'Beauty salon',
        'HairSalon'            => 'Hair salon',
        'DaySpa'               => 'Day spa',
        'AutoRepair'           => 'Auto repair',
        'Plumber'              => 'Plumber',
        'Electrician'          => 'Electrician',
        'HVACBusiness'         => 'HVAC',
        'RoofingContractor'    => 'Roofing contractor',
        'GeneralContractor'    => 'General contractor',
        'HomeAndConstructionBusiness' => 'Home & construction',
        'RealEstateAgent'      => 'Real estate agent',
        'LegalService'         => 'Legal services',
        'Attorney'             => 'Attorney',
        'AccountingService'    => 'Accounting',
        'FinancialService'     => 'Financial services',
        'InsuranceAgency'      => 'Insurance',
        'TravelAgency'         => 'Travel agency',
        'Hotel'                => 'Hotel',
        'LodgingBusiness'      => 'Lodging',
        'GymLocation'          => 'Gym',
        'SportsActivityLocation' => 'Sports / fitness',
        'ChildCare'            => 'Childcare',
        'EducationOrganization' => 'Education',
        'ProfessionalService'  => 'Professional services',
    ];

    public const DAYS = [
        'Monday'    => 'Mon',
        'Tuesday'   => 'Tue',
        'Wednesday' => 'Wed',
        'Thursday'  => 'Thu',
        'Friday'    => 'Fri',
        'Saturday'  => 'Sat',
        'Sunday'    => 'Sun',
    ];

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ], 20 );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        if ( ! is_admin() && $this->is_enabled() && ! $this->other_seo_plugin_active() ) {
            add_action( 'wp_head', [ $this, 'output_schema' ], 2 );
        }
    }

    public function register_menu(): void {
        add_submenu_page(
            'stratawp-seo',
            __( 'Local SEO', 'stratawp-seo' ),
            __( 'Local SEO', 'stratawp-seo' ),
            'manage_options',
            'swps-local-seo',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'stratawp-seo' ) );
        }
        require SWPS_PLUGIN_DIR . 'templates/local-seo-page.php';
    }

    public function enqueue_assets( string $hook ): void {
        if ( 'stratawp-seo_page_swps-local-seo' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'swps-page-local-seo',
            SWPS_PLUGIN_URL . 'admin/css/pages/local-seo.css',
            [ 'swps-tokens', 'swps-components', 'swps-templates' ],
            SWPS_VERSION
        );
    }

    // ---------- Settings ----------

    public function register_settings(): void {
        register_setting(
            'swps_local_seo_group',
            self::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize' ],
                'default'           => $this->defaults(),
            ]
        );
    }

    public function defaults(): array {
        return [
            'enabled'        => 0,
            'business_type'  => 'LocalBusiness',
            'name'           => '',
            'phone'          => '',
            'email'          => '',
            'price_range'    => '',
            'street'         => '',
            'locality'       => '',
            'region'         => '',
            'postal_code'    => '',
            'country'        => '',
            'latitude'       => '',
            'longitude'      => '',
            'area_served'    => '', // newline-separated list
            'hours'          => [], // day => [ 'open' => 'HH:MM', 'close' => 'HH:MM', 'closed' => 0 ]
            'place_id'       => '', // Google Business Profile place ID (optional)
        ];
    }

    public function get(): array {
        $stored = get_option( self::OPTION_KEY, [] );
        return wp_parse_args( is_array( $stored ) ? $stored : [], $this->defaults() );
    }

    public function is_enabled(): bool {
        $data = $this->get();
        if ( empty( $data['enabled'] ) ) {
            return false;
        }
        // Need at least a name + locality to publish anything meaningful.
        return '' !== trim( (string) $data['name'] ) && '' !== trim( (string) $data['locality'] );
    }

    public function sanitize( $input ): array {
        $defaults = $this->defaults();
        $clean    = $defaults;

        if ( ! is_array( $input ) ) {
            return $clean;
        }

        $clean['enabled']       = empty( $input['enabled'] ) ? 0 : 1;
        $clean['business_type'] = isset( $input['business_type'], self::BUSINESS_TYPES[ $input['business_type'] ] )
            ? sanitize_text_field( $input['business_type'] )
            : 'LocalBusiness';

        foreach ( [ 'name', 'phone', 'price_range', 'street', 'locality', 'region', 'postal_code', 'country', 'place_id' ] as $key ) {
            $clean[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : '';
        }

        $clean['email'] = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '';

        // Geo — must be valid float-ish (or empty). Stored as string to preserve formatting.
        foreach ( [ 'latitude', 'longitude' ] as $geo ) {
            $val = isset( $input[ $geo ] ) ? trim( (string) $input[ $geo ] ) : '';
            if ( '' === $val ) {
                $clean[ $geo ] = '';
            } elseif ( is_numeric( $val ) ) {
                $clean[ $geo ] = (string) (float) $val;
            } else {
                $clean[ $geo ] = '';
            }
        }

        // Area served — newline-separated list, one location per line.
        $area_raw = isset( $input['area_served'] ) ? (string) $input['area_served'] : '';
        $lines    = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $area_raw ) ?: [] ) );
        $lines    = array_map( 'sanitize_text_field', $lines );
        $clean['area_served'] = implode( "\n", array_slice( $lines, 0, 25 ) );

        // Hours — keyed by canonical day name.
        $clean['hours'] = [];
        $hours_in       = isset( $input['hours'] ) && is_array( $input['hours'] ) ? $input['hours'] : [];
        foreach ( array_keys( self::DAYS ) as $day ) {
            $row    = isset( $hours_in[ $day ] ) && is_array( $hours_in[ $day ] ) ? $hours_in[ $day ] : [];
            $closed = ! empty( $row['closed'] ) ? 1 : 0;
            $open   = isset( $row['open'] )  ? $this->normalize_time( $row['open'] )  : '';
            $close  = isset( $row['close'] ) ? $this->normalize_time( $row['close'] ) : '';
            $clean['hours'][ $day ] = [
                'closed' => $closed,
                'open'   => $closed ? '' : $open,
                'close'  => $closed ? '' : $close,
            ];
        }

        return $clean;
    }

    private function normalize_time( string $val ): string {
        $val = trim( $val );
        if ( '' === $val ) {
            return '';
        }
        if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $val, $m ) ) {
            return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
        }
        return '';
    }

    // ---------- Schema ----------

    private function other_seo_plugin_active(): bool {
        return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' );
    }

    /**
     * Output LocalBusiness JSON-LD on the homepage (and any page slug listed
     * via the `swps_local_seo_schema_pages` filter — defaults to homepage only).
     */
    public function output_schema(): void {
        $show_on_home = is_front_page() || is_home();

        /**
         * Filter which contexts get the LocalBusiness schema.
         *
         * @param bool $show True to emit on this request.
         */
        $show = (bool) apply_filters( 'swps_local_seo_emit', $show_on_home );

        if ( ! $show ) {
            return;
        }

        $schema = $this->build_schema();
        if ( empty( $schema ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD.
        echo '<script type="application/ld+json">'
            . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
            . '</script>' . "\n";
    }

    public function build_schema(): array {
        $d = $this->get();

        if ( '' === trim( (string) $d['name'] ) ) {
            return [];
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => $d['business_type'] ?: 'LocalBusiness',
            'name'     => $d['name'],
            'url'      => home_url( '/' ),
        ];

        if ( '' !== trim( $d['phone'] ) ) {
            $schema['telephone'] = $d['phone'];
        }
        if ( '' !== trim( $d['email'] ) ) {
            $schema['email'] = $d['email'];
        }
        if ( '' !== trim( $d['price_range'] ) ) {
            $schema['priceRange'] = $d['price_range'];
        }

        $address = [];
        if ( '' !== trim( $d['street'] ) )      { $address['streetAddress']    = $d['street']; }
        if ( '' !== trim( $d['locality'] ) )    { $address['addressLocality']  = $d['locality']; }
        if ( '' !== trim( $d['region'] ) )      { $address['addressRegion']    = $d['region']; }
        if ( '' !== trim( $d['postal_code'] ) ) { $address['postalCode']       = $d['postal_code']; }
        if ( '' !== trim( $d['country'] ) )     { $address['addressCountry']   = $d['country']; }
        if ( ! empty( $address ) ) {
            $address['@type']   = 'PostalAddress';
            $schema['address']  = $address;
        }

        if ( '' !== $d['latitude'] && '' !== $d['longitude'] ) {
            $schema['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $d['latitude'],
                'longitude' => (float) $d['longitude'],
            ];
        }

        // Logo / image: reuse the existing organization logo setting if present.
        $logo = get_option( 'swps_schema_logo', '' );
        if ( ! empty( $logo ) ) {
            $schema['image'] = $logo;
            $schema['logo']  = $logo;
        }

        // Hours.
        $opening = [];
        foreach ( array_keys( self::DAYS ) as $day ) {
            $row = $d['hours'][ $day ] ?? [];
            if ( empty( $row ) || ! empty( $row['closed'] ) ) {
                continue;
            }
            $open  = (string) ( $row['open']  ?? '' );
            $close = (string) ( $row['close'] ?? '' );
            if ( '' === $open || '' === $close ) {
                continue;
            }
            $opening[] = [
                '@type'     => 'OpeningHoursSpecification',
                'dayOfWeek' => 'https://schema.org/' . $day,
                'opens'     => $open,
                'closes'    => $close,
            ];
        }
        if ( ! empty( $opening ) ) {
            $schema['openingHoursSpecification'] = $opening;
        }

        // Area served.
        $area_lines = array_filter( array_map( 'trim', explode( "\n", (string) $d['area_served'] ) ) );
        if ( ! empty( $area_lines ) ) {
            $schema['areaServed'] = array_values( $area_lines );
        }

        // Same-as: reuse existing social profiles.
        $social_raw = (string) get_option( 'swps_schema_social_profiles', '' );
        if ( '' !== trim( $social_raw ) ) {
            $urls = array_filter( array_map( 'trim', explode( "\n", $social_raw ) ) );
            if ( ! empty( $urls ) ) {
                $schema['sameAs'] = array_values( $urls );
            }
        }

        // Google Place ID — optional hasMap.
        if ( '' !== trim( (string) $d['place_id'] ) ) {
            $schema['hasMap'] = 'https://www.google.com/maps/place/?q=place_id:' . rawurlencode( $d['place_id'] );
        }

        /**
         * Filter the LocalBusiness schema before output.
         *
         * @param array $schema  The assembled schema array.
         * @param array $data    Raw settings.
         */
        return (array) apply_filters( 'swps_local_seo_schema', $schema, $d );
    }
}
