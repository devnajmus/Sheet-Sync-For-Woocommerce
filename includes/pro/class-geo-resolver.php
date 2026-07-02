<?php
/**
 * Global geographic coordinate resolver for the sales dashboard map.
 *
 * Uses ISO country centroids, optional OpenStreetMap geocoding (cached), and
 * deterministic fallbacks so any billing location worldwide can be plotted.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Geo_Resolver' ) ) :

class SheetSync_Geo_Resolver {

    /** @var int */
    private static int $geocode_calls = 0;

    private const MAX_GEOCODE_CALLS = 5;

    /**
     * Resolve latitude / longitude for a billing location.
     *
     * @return array{lat:float,lon:float,source:string}
     */
    public static function resolve( string $city, string $state, string $country_code, ?callable $country_name_fn = null ): array {
        $country = strtoupper( trim( $country_code ) ?: 'XX' );
        $city    = trim( $city );
        $state   = trim( $state );

        if ( $city !== '' ) {
            $cached = self::get_cached( $city, $state, $country );
            if ( $cached ) {
                return $cached;
            }

            if ( self::$geocode_calls < self::MAX_GEOCODE_CALLS ) {
                $geocoded = self::geocode_nominatim( $city, $state, $country, $country_name_fn );
                if ( $geocoded ) {
                    self::set_cached( $city, $state, $country, $geocoded );
                    return $geocoded;
                }
            }

            $fallback = self::city_hash_offset( $city, $state, $country );
            self::set_cached( $city, $state, $country, $fallback, WEEK_IN_SECONDS );
            return $fallback;
        }

        if ( $state !== '' ) {
            $cached = self::get_cached( '', $state, $country );
            if ( $cached ) {
                return $cached;
            }

            if ( self::$geocode_calls < self::MAX_GEOCODE_CALLS ) {
                $geocoded = self::geocode_nominatim( '', $state, $country, $country_name_fn );
                if ( $geocoded ) {
                    self::set_cached( '', $state, $country, $geocoded );
                    return $geocoded;
                }
            }
        }

        return self::country_centroid_coords( $country );
    }

    /**
     * Convert WGS84 coordinates to map pin percentages (equirectangular).
     *
     * @return array{left:float,top:float}
     */
    public static function to_map_percent( float $lat, float $lon ): array {
        $lat = max( -85.0, min( 85.0, $lat ) );
        $lon = max( -180.0, min( 180.0, $lon ) );

        return array(
            'left' => round( ( ( $lon + 180 ) / 360 ) * 100, 1 ),
            'top'  => round( ( ( 90 - $lat ) / 180 ) * 100, 1 ),
        );
    }

    /**
     * @return array{lat:float,lon:float,source:string}
     */
    public static function country_centroid_coords( string $country_code ): array {
        $centroids = self::country_centroids();
        $code      = strtoupper( $country_code ?: 'XX' );

        if ( isset( $centroids[ $code ] ) ) {
            return array(
                'lat'    => (float) $centroids[ $code ]['lat'],
                'lon'    => (float) $centroids[ $code ]['lon'],
                'source' => 'country',
            );
        }

        return array(
            'lat'    => 0.0,
            'lon'    => 0.0,
            'source' => 'unknown',
        );
    }

    /**
     * @return array{lat:float,lon:float,source:string}
     */
    private static function city_hash_offset( string $city, string $state, string $country_code ): array {
        $base     = self::country_centroid_coords( $country_code );
        $seed     = strtolower( $country_code . '|' . $state . '|' . $city );
        $hash     = crc32( $seed );
        $lon_off  = ( ( $hash & 0xFF ) - 128 ) / 128 * 4.5;
        $lat_off  = ( ( ( $hash >> 8 ) & 0xFF ) - 128 ) / 128 * 3.0;

        return array(
            'lat'    => max( -85.0, min( 85.0, $base['lat'] + $lat_off ) ),
            'lon'    => max( -180.0, min( 180.0, $base['lon'] + $lon_off ) ),
            'source' => 'estimate',
        );
    }

    /**
     * @return array{lat:float,lon:float,source:string}|null
     */
    private static function geocode_nominatim( string $city, string $state, string $country_code, ?callable $country_name_fn ): ?array {
        if ( ! apply_filters( 'sheetsync_enable_geo_geocoding', true ) ) {
            return null;
        }

        $parts = array_filter(
            array(
                $city,
                $state,
                is_callable( $country_name_fn ) ? (string) call_user_func( $country_name_fn, $country_code ) : $country_code,
            )
        );

        if ( empty( $parts ) ) {
            return null;
        }

        $url = add_query_arg(
            array(
                'q'              => implode( ', ', $parts ),
                'format'         => 'json',
                'limit'          => 1,
                'addressdetails' => 0,
            ),
            'https://nominatim.openstreetmap.org/search'
        );

        if ( self::$geocode_calls > 0 ) {
            usleep( 1100000 );
        }

        self::$geocode_calls++;

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 8,
                'headers' => array(
                    'User-Agent' => self::geocode_user_agent(),
                ),
            )
        );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( empty( $body[0]['lat'] ) || empty( $body[0]['lon'] ) ) {
            return null;
        }

        return array(
            'lat'    => (float) $body[0]['lat'],
            'lon'    => (float) $body[0]['lon'],
            'source' => 'geocoded',
        );
    }

    private static function geocode_user_agent(): string {
        $version = defined( 'SHEETSYNC_VERSION' ) ? SHEETSYNC_VERSION : '1.0';
        $home    = home_url( '/' );

        return 'SheetSync-WooCommerce/' . $version . ' (' . $home . ')';
    }

    private static function cache_key( string $city, string $state, string $country ): string {
        return 'ss_geo_' . md5( strtolower( trim( $country ) . '|' . trim( $state ) . '|' . trim( $city ) ) );
    }

    /**
     * @return array{lat:float,lon:float,source:string}|null
     */
    private static function get_cached( string $city, string $state, string $country ): ?array {
        $cached = get_transient( self::cache_key( $city, $state, $country ) );
        if ( ! is_array( $cached ) || ! isset( $cached['lat'], $cached['lon'] ) ) {
            return null;
        }

        return array(
            'lat'    => (float) $cached['lat'],
            'lon'    => (float) $cached['lon'],
            'source' => (string) ( $cached['source'] ?? 'cache' ),
        );
    }

    /**
     * @param array{lat:float,lon:float,source:string} $coords
     */
    private static function set_cached( string $city, string $state, string $country, array $coords, int $ttl = MONTH_IN_SECONDS ): void {
        set_transient(
            self::cache_key( $city, $state, $country ),
            array(
                'lat'    => $coords['lat'],
                'lon'    => $coords['lon'],
                'source' => $coords['source'],
            ),
            $ttl
        );
    }

    /**
     * ISO 3166-1 alpha-2 country centroids (lat / lon).
     *
     * @return array<string, array{lat:float,lon:float}>
     */
    private static function country_centroids(): array {
        static $centroids = null;

        if ( is_array( $centroids ) ) {
            return $centroids;
        }

        $centroids = array(
            'AD' => array( 'lat' => 42.546245, 'lon' => 1.601554 ),
            'AE' => array( 'lat' => 23.424076, 'lon' => 53.847818 ),
            'AF' => array( 'lat' => 33.93911, 'lon' => 67.709953 ),
            'AG' => array( 'lat' => 17.060816, 'lon' => -61.796428 ),
            'AL' => array( 'lat' => 41.153332, 'lon' => 20.168331 ),
            'AM' => array( 'lat' => 40.069099, 'lon' => 45.038189 ),
            'AO' => array( 'lat' => -11.202692, 'lon' => 17.873887 ),
            'AR' => array( 'lat' => -38.416097, 'lon' => -63.616672 ),
            'AT' => array( 'lat' => 47.516231, 'lon' => 14.550072 ),
            'AU' => array( 'lat' => -25.274398, 'lon' => 133.775136 ),
            'AZ' => array( 'lat' => 40.143105, 'lon' => 47.576927 ),
            'BA' => array( 'lat' => 43.915886, 'lon' => 17.679076 ),
            'BB' => array( 'lat' => 13.193887, 'lon' => -59.543198 ),
            'BD' => array( 'lat' => 23.684994, 'lon' => 90.356331 ),
            'BE' => array( 'lat' => 50.503887, 'lon' => 4.469936 ),
            'BF' => array( 'lat' => 12.238333, 'lon' => -1.561593 ),
            'BG' => array( 'lat' => 42.733883, 'lon' => 25.48583 ),
            'BH' => array( 'lat' => 26.0667, 'lon' => 50.5577 ),
            'BI' => array( 'lat' => -3.373056, 'lon' => 29.918886 ),
            'BJ' => array( 'lat' => 9.30769, 'lon' => 2.315834 ),
            'BN' => array( 'lat' => 4.535277, 'lon' => 114.727669 ),
            'BO' => array( 'lat' => -16.290154, 'lon' => -63.588653 ),
            'BR' => array( 'lat' => -14.235004, 'lon' => -51.92528 ),
            'BS' => array( 'lat' => 25.03428, 'lon' => -77.39628 ),
            'BT' => array( 'lat' => 27.514162, 'lon' => 90.433601 ),
            'BW' => array( 'lat' => -22.328474, 'lon' => 24.684866 ),
            'BY' => array( 'lat' => 53.709807, 'lon' => 27.953389 ),
            'BZ' => array( 'lat' => 17.189877, 'lon' => -88.49765 ),
            'CA' => array( 'lat' => 56.130366, 'lon' => -106.346771 ),
            'CD' => array( 'lat' => -4.038333, 'lon' => 21.758664 ),
            'CF' => array( 'lat' => 6.611111, 'lon' => 20.939444 ),
            'CG' => array( 'lat' => -0.228021, 'lon' => 15.827659 ),
            'CH' => array( 'lat' => 46.818188, 'lon' => 8.227512 ),
            'CI' => array( 'lat' => 7.539989, 'lon' => -5.54708 ),
            'CL' => array( 'lat' => -35.675147, 'lon' => -71.542969 ),
            'CM' => array( 'lat' => 7.369722, 'lon' => 12.354722 ),
            'CN' => array( 'lat' => 35.86166, 'lon' => 104.195397 ),
            'CO' => array( 'lat' => 4.570868, 'lon' => -74.297333 ),
            'CR' => array( 'lat' => 9.748917, 'lon' => -83.753428 ),
            'CU' => array( 'lat' => 21.521757, 'lon' => -77.781167 ),
            'CV' => array( 'lat' => 16.002082, 'lon' => -24.013197 ),
            'CY' => array( 'lat' => 35.126413, 'lon' => 33.429859 ),
            'CZ' => array( 'lat' => 49.817492, 'lon' => 15.472962 ),
            'DE' => array( 'lat' => 51.165691, 'lon' => 10.451526 ),
            'DJ' => array( 'lat' => 11.825138, 'lon' => 42.590275 ),
            'DK' => array( 'lat' => 56.26392, 'lon' => 9.501785 ),
            'DM' => array( 'lat' => 15.414999, 'lon' => -61.370976 ),
            'DO' => array( 'lat' => 18.735693, 'lon' => -70.162651 ),
            'DZ' => array( 'lat' => 28.033886, 'lon' => 1.659626 ),
            'EC' => array( 'lat' => -1.831239, 'lon' => -78.183406 ),
            'EE' => array( 'lat' => 58.595272, 'lon' => 25.013607 ),
            'EG' => array( 'lat' => 26.820553, 'lon' => 30.802498 ),
            'ER' => array( 'lat' => 15.179384, 'lon' => 39.782334 ),
            'ES' => array( 'lat' => 40.463667, 'lon' => -3.74922 ),
            'ET' => array( 'lat' => 9.145, 'lon' => 40.489673 ),
            'FI' => array( 'lat' => 61.92411, 'lon' => 25.748151 ),
            'FJ' => array( 'lat' => -16.578193, 'lon' => 179.414413 ),
            'FR' => array( 'lat' => 46.227638, 'lon' => 2.213749 ),
            'GA' => array( 'lat' => -0.803689, 'lon' => 11.609444 ),
            'GB' => array( 'lat' => 55.378051, 'lon' => -3.435973 ),
            'GD' => array( 'lat' => 12.1165, 'lon' => -61.679 ),
            'GE' => array( 'lat' => 42.315407, 'lon' => 43.356892 ),
            'GH' => array( 'lat' => 7.946527, 'lon' => -1.023194 ),
            'GM' => array( 'lat' => 13.443182, 'lon' => -15.310139 ),
            'GN' => array( 'lat' => 9.945587, 'lon' => -9.696645 ),
            'GQ' => array( 'lat' => 1.650801, 'lon' => 10.267895 ),
            'GR' => array( 'lat' => 39.074208, 'lon' => 21.824312 ),
            'GT' => array( 'lat' => 15.783471, 'lon' => -90.230759 ),
            'GW' => array( 'lat' => 11.803749, 'lon' => -15.180413 ),
            'GY' => array( 'lat' => 4.860416, 'lon' => -58.93018 ),
            'HN' => array( 'lat' => 15.199999, 'lon' => -86.241905 ),
            'HR' => array( 'lat' => 45.1, 'lon' => 15.2 ),
            'HT' => array( 'lat' => 18.971187, 'lon' => -72.285215 ),
            'HU' => array( 'lat' => 47.162494, 'lon' => 19.503304 ),
            'ID' => array( 'lat' => -0.789275, 'lon' => 113.921327 ),
            'IE' => array( 'lat' => 53.41291, 'lon' => -8.24389 ),
            'IL' => array( 'lat' => 31.046051, 'lon' => 34.851612 ),
            'IN' => array( 'lat' => 20.593684, 'lon' => 78.96288 ),
            'IQ' => array( 'lat' => 33.223191, 'lon' => 43.679291 ),
            'IR' => array( 'lat' => 32.427908, 'lon' => 53.688046 ),
            'IS' => array( 'lat' => 64.963051, 'lon' => -19.020835 ),
            'IT' => array( 'lat' => 41.87194, 'lon' => 12.56738 ),
            'JM' => array( 'lat' => 18.109581, 'lon' => -77.297508 ),
            'JO' => array( 'lat' => 30.585164, 'lon' => 36.238414 ),
            'JP' => array( 'lat' => 36.204824, 'lon' => 138.252924 ),
            'KE' => array( 'lat' => -0.023559, 'lon' => 37.906193 ),
            'KG' => array( 'lat' => 41.20438, 'lon' => 74.766098 ),
            'KH' => array( 'lat' => 12.565679, 'lon' => 104.990963 ),
            'KM' => array( 'lat' => -11.6455, 'lon' => 43.3333 ),
            'KN' => array( 'lat' => 17.357822, 'lon' => -62.782998 ),
            'KP' => array( 'lat' => 40.339852, 'lon' => 127.510093 ),
            'KR' => array( 'lat' => 35.907757, 'lon' => 127.766922 ),
            'KW' => array( 'lat' => 29.31166, 'lon' => 47.481766 ),
            'KZ' => array( 'lat' => 48.019573, 'lon' => 66.923684 ),
            'LA' => array( 'lat' => 19.85627, 'lon' => 102.495496 ),
            'LB' => array( 'lat' => 33.854721, 'lon' => 35.862285 ),
            'LC' => array( 'lat' => 13.909444, 'lon' => -60.978893 ),
            'LK' => array( 'lat' => 7.873054, 'lon' => 80.771797 ),
            'LR' => array( 'lat' => 6.428055, 'lon' => -9.429499 ),
            'LS' => array( 'lat' => -29.609988, 'lon' => 28.233608 ),
            'LT' => array( 'lat' => 55.169438, 'lon' => 23.881275 ),
            'LU' => array( 'lat' => 49.815273, 'lon' => 6.129583 ),
            'LV' => array( 'lat' => 56.879635, 'lon' => 24.603189 ),
            'LY' => array( 'lat' => 26.3351, 'lon' => 17.228331 ),
            'MA' => array( 'lat' => 31.791702, 'lon' => -7.09262 ),
            'MC' => array( 'lat' => 43.738418, 'lon' => 7.424616 ),
            'MD' => array( 'lat' => 47.411631, 'lon' => 28.369885 ),
            'ME' => array( 'lat' => 42.708678, 'lon' => 19.37439 ),
            'MG' => array( 'lat' => -18.766947, 'lon' => 46.869107 ),
            'MK' => array( 'lat' => 41.608635, 'lon' => 21.745275 ),
            'ML' => array( 'lat' => 17.570692, 'lon' => -3.996166 ),
            'MM' => array( 'lat' => 21.913965, 'lon' => 95.956223 ),
            'MN' => array( 'lat' => 46.862496, 'lon' => 103.846656 ),
            'MR' => array( 'lat' => 21.00789, 'lon' => -10.940835 ),
            'MT' => array( 'lat' => 35.937496, 'lon' => 14.375416 ),
            'MU' => array( 'lat' => -20.348404, 'lon' => 57.552152 ),
            'MV' => array( 'lat' => 3.202778, 'lon' => 73.22068 ),
            'MW' => array( 'lat' => -13.254308, 'lon' => 34.301525 ),
            'MX' => array( 'lat' => 23.634501, 'lon' => -102.552784 ),
            'MY' => array( 'lat' => 4.210484, 'lon' => 101.975766 ),
            'MZ' => array( 'lat' => -18.665695, 'lon' => 35.529562 ),
            'NA' => array( 'lat' => -22.95764, 'lon' => 18.49041 ),
            'NE' => array( 'lat' => 17.607789, 'lon' => 8.081666 ),
            'NG' => array( 'lat' => 9.081999, 'lon' => 8.675277 ),
            'NI' => array( 'lat' => 12.865416, 'lon' => -85.207229 ),
            'NL' => array( 'lat' => 52.132633, 'lon' => 5.291266 ),
            'NO' => array( 'lat' => 60.472024, 'lon' => 8.468946 ),
            'NP' => array( 'lat' => 28.394857, 'lon' => 84.124008 ),
            'NZ' => array( 'lat' => -40.900557, 'lon' => 174.885971 ),
            'OM' => array( 'lat' => 21.473533, 'lon' => 55.975413 ),
            'PA' => array( 'lat' => 8.537981, 'lon' => -80.782127 ),
            'PE' => array( 'lat' => -9.189967, 'lon' => -75.015152 ),
            'PG' => array( 'lat' => -6.314993, 'lon' => 143.95555 ),
            'PH' => array( 'lat' => 12.879721, 'lon' => 121.774017 ),
            'PK' => array( 'lat' => 30.375321, 'lon' => 69.345116 ),
            'PL' => array( 'lat' => 51.919438, 'lon' => 19.145136 ),
            'PR' => array( 'lat' => 18.220833, 'lon' => -66.590149 ),
            'PS' => array( 'lat' => 31.952162, 'lon' => 35.233154 ),
            'PT' => array( 'lat' => 39.399872, 'lon' => -8.224454 ),
            'PY' => array( 'lat' => -23.442503, 'lon' => -58.443832 ),
            'QA' => array( 'lat' => 25.354826, 'lon' => 51.183884 ),
            'RO' => array( 'lat' => 45.943161, 'lon' => 24.96676 ),
            'RS' => array( 'lat' => 44.016521, 'lon' => 21.005859 ),
            'RU' => array( 'lat' => 61.52401, 'lon' => 105.318756 ),
            'RW' => array( 'lat' => -1.940278, 'lon' => 29.873888 ),
            'SA' => array( 'lat' => 23.885942, 'lon' => 45.079162 ),
            'SB' => array( 'lat' => -9.64571, 'lon' => 160.156194 ),
            'SC' => array( 'lat' => -4.679574, 'lon' => 55.491977 ),
            'SD' => array( 'lat' => 12.862807, 'lon' => 30.217636 ),
            'SE' => array( 'lat' => 60.128161, 'lon' => 18.643501 ),
            'SG' => array( 'lat' => 1.352083, 'lon' => 103.819836 ),
            'SI' => array( 'lat' => 46.151241, 'lon' => 14.995463 ),
            'SK' => array( 'lat' => 48.669026, 'lon' => 19.699024 ),
            'SL' => array( 'lat' => 8.460555, 'lon' => -11.779889 ),
            'SN' => array( 'lat' => 14.497401, 'lon' => -14.452362 ),
            'SO' => array( 'lat' => 5.152149, 'lon' => 46.199616 ),
            'SR' => array( 'lat' => 3.919305, 'lon' => -56.027783 ),
            'SS' => array( 'lat' => 6.876992, 'lon' => 31.306979 ),
            'ST' => array( 'lat' => 0.18636, 'lon' => 6.613081 ),
            'SV' => array( 'lat' => 13.794185, 'lon' => -88.89653 ),
            'SY' => array( 'lat' => 34.802075, 'lon' => 38.996815 ),
            'SZ' => array( 'lat' => -26.522503, 'lon' => 31.465866 ),
            'TD' => array( 'lat' => 15.454166, 'lon' => 18.732207 ),
            'TG' => array( 'lat' => 8.619543, 'lon' => 0.824782 ),
            'TH' => array( 'lat' => 15.870032, 'lon' => 100.992541 ),
            'TJ' => array( 'lat' => 38.861034, 'lon' => 71.276093 ),
            'TL' => array( 'lat' => -8.874217, 'lon' => 125.727539 ),
            'TM' => array( 'lat' => 38.969719, 'lon' => 59.556278 ),
            'TN' => array( 'lat' => 33.886917, 'lon' => 9.537499 ),
            'TR' => array( 'lat' => 38.963745, 'lon' => 35.243322 ),
            'TT' => array( 'lat' => 10.691803, 'lon' => -61.222503 ),
            'TW' => array( 'lat' => 23.69781, 'lon' => 120.960515 ),
            'TZ' => array( 'lat' => -6.369028, 'lon' => 34.888822 ),
            'UA' => array( 'lat' => 48.379433, 'lon' => 31.16558 ),
            'UG' => array( 'lat' => 1.373333, 'lon' => 32.290275 ),
            'US' => array( 'lat' => 37.09024, 'lon' => -95.712891 ),
            'UY' => array( 'lat' => -32.522779, 'lon' => -55.765835 ),
            'UZ' => array( 'lat' => 41.377491, 'lon' => 64.585262 ),
            'VC' => array( 'lat' => 12.984305, 'lon' => -61.287228 ),
            'VE' => array( 'lat' => 6.42375, 'lon' => -66.58973 ),
            'VN' => array( 'lat' => 14.058324, 'lon' => 108.277199 ),
            'VU' => array( 'lat' => -15.376706, 'lon' => 166.959158 ),
            'WS' => array( 'lat' => -13.759029, 'lon' => -172.104629 ),
            'XK' => array( 'lat' => 42.602636, 'lon' => 20.902977 ),
            'YE' => array( 'lat' => 15.552727, 'lon' => 48.516388 ),
            'ZA' => array( 'lat' => -30.559482, 'lon' => 22.937506 ),
            'ZM' => array( 'lat' => -13.133897, 'lon' => 27.849332 ),
            'ZW' => array( 'lat' => -19.015438, 'lon' => 29.154857 ),
            'XX' => array( 'lat' => 0.0, 'lon' => 0.0 ),
        );

        return $centroids;
    }
}

endif;
