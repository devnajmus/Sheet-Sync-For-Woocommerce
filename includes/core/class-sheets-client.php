<?php
/**
 * Google Sheets API v4 client — uses WordPress HTTP API only.
 * Zero dependencies. No Composer. Works on any shared hosting.
 * @package SheetSync_For_WooCommerce
 * @since   1.0.0
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Sheets_Client' ) ) :

class SheetSync_Sheets_Client {

    private const BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    /** @var array<string, array{ids: array<string, int>, grids: array<string, array{rows: int, columns: int}>}> */
    private static array $props_request_cache = array();

    /**
     * Wrap a sheet tab name in single quotes for A1 notation (required when the name has spaces, etc.).
     */
    public static function quote_sheet_tab( string $sheet_name ): string {
        return "'" . str_replace( "'", "''", $sheet_name ) . "'";
    }

    /**
     * Build an A1 range for a tab, e.g. 'all order'!A1:L10
     */
    public static function tab_range( string $sheet_name, string $a1_part ): string {
        return self::quote_sheet_tab( $sheet_name ) . '!' . $a1_part;
    }

    /**
     * Clip overflowing text in data cells (stops long descriptions spilling into empty image columns).
     */
    public function apply_export_data_cell_clip(
        string $spreadsheet_id,
        string $sheet_name,
        int $first_data_row_1based,
        int $data_row_count,
        int $col_count
    ): void {
        if ( $data_row_count < 1 || $col_count < 1 ) {
            return;
        }

        $sheet_id   = $this->get_sheet_id( $spreadsheet_id, $sheet_name );
        $first_data = max( 0, $first_data_row_1based - 1 );

        $this->batch_update_requests(
            $spreadsheet_id,
            array(
                array(
                    'repeatCell' => array(
                        'range'  => array(
                            'sheetId'          => $sheet_id,
                            'startRowIndex'    => $first_data,
                            'endRowIndex'      => $first_data + $data_row_count,
                            'startColumnIndex' => 0,
                            'endColumnIndex'   => $col_count,
                        ),
                        'cell'   => array(
                            'userEnteredFormat' => array(
                                'wrapStrategy'      => 'CLIP',
                                'verticalAlignment' => 'MIDDLE',
                            ),
                        ),
                        'fields' => 'userEnteredFormat(wrapStrategy,verticalAlignment)',
                    ),
                ),
            )
        );
    }

    /**
     * Run batchUpdate in chunks (Google allows ~100 requests per call; large catalogs need many row groups).
     *
     * @param array<int, array<string, mixed>> $requests
     */
    public function batch_update_requests( string $spreadsheet_id, array $requests ): void {
        if ( empty( $requests ) ) {
            return;
        }
        $url = self::BASE . '/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate';
        foreach ( array_chunk( $requests, 75 ) as $chunk ) {
            SheetSync_Google_Auth::api_post( $url, array( 'requests' => $chunk ) );
        }
    }

    /**
     * Ensure sheet names in a range string are quoted (idempotent).
     */
    public static function normalize_range( string $range ): string {
        if ( preg_match( "/^'/", $range ) ) {
            return $range;
        }
        if ( preg_match( '/^([^!]+)!(.+)$/', $range, $matches ) ) {
            return self::quote_sheet_tab( $matches[1] ) . '!' . $matches[2];
        }
        return $range;
    }

    /**
     * Read all rows from a range.
     * Returns array of rows, each row is array of cell values (strings).
     */
    public function get_rows( string $spreadsheet_id, string $range ): array {
        $url      = self::BASE . '/' . rawurlencode( $spreadsheet_id )
                  . '/values/' . rawurlencode( self::normalize_range( $range ) );
        $response = SheetSync_Google_Auth::api_get( $url );
        return $response['values'] ?? [];
    }

    /**
     * Overwrite rows at a given range.
     */
    public function set_rows( string $spreadsheet_id, string $range, array $data ): void {
        $url = self::BASE . '/' . rawurlencode( $spreadsheet_id )
             . '/values/' . rawurlencode( self::normalize_range( $range ) )
             . '?valueInputOption=USER_ENTERED';

        SheetSync_Google_Auth::api_put( $url, [ 'values' => $data ] );
    }

    /**
     * Batch update multiple ranges in one API request.
     *
     * @param array<int, array{range: string, values: array<int, array<int, mixed>>}> $data
     */
    public function values_batch_update( string $spreadsheet_id, array $data ): void {
        if ( empty( $data ) ) {
            return;
        }

        $url  = self::BASE . '/' . rawurlencode( $spreadsheet_id ) . '/values:batchUpdate';
        $body = array(
            'valueInputOption' => 'USER_ENTERED',
            'data'             => array(),
        );

        foreach ( $data as $item ) {
            $body['data'][] = array(
                'range'  => $item['range'],
                'values' => $item['values'],
            );
        }

        SheetSync_Google_Auth::api_post( $url, $body );
    }

    /**
     * Append rows to the end of existing data.
     *
     * @return int 1-based row number of the first appended row, or 0 when unknown.
     */
    public function append_rows( string $spreadsheet_id, string $range, array $data ): int {
        $url = self::BASE . '/' . rawurlencode( $spreadsheet_id )
             . '/values/' . rawurlencode( self::normalize_range( $range ) )
             . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';

        $response = SheetSync_Google_Auth::api_post( $url, [ 'values' => $data ] );
        $updated  = (string) ( $response['updates']['updatedRange'] ?? '' );

        return self::parse_a1_start_row( $updated );
    }

    /**
     * Parse the 1-based starting row from an A1 range (e.g. Sheet1!A951:Z952 → 951).
     */
    public static function parse_a1_start_row( string $a1_range ): int {
        if ( preg_match( '/!(?:[A-Za-z]+)(\d+)/', $a1_range, $matches ) ) {
            return max( 0, (int) $matches[1] );
        }
        return 0;
    }

    /**
     * Update a single cell.
     */
    public function update_cell( string $spreadsheet_id, string $sheet_name, int $row, string $col, $value ): void {
        $range = self::tab_range( $sheet_name, $col . $row );
        $this->set_rows( $spreadsheet_id, $range, [ [ (string) $value ] ] );
    }

    /**
     * Get spreadsheet metadata (title, sheet tab names).
     */
    public function get_metadata( string $spreadsheet_id ): array {
        $url      = self::BASE . '/' . rawurlencode( $spreadsheet_id );
        $response = SheetSync_Google_Auth::api_get( $url );
        $sheets   = array_map(
            fn( $s ) => $s['properties']['title'],
            $response['sheets'] ?? []
        );
        return [
            'title'  => $response['properties']['title'] ?? '',
            'sheets' => $sheets,
        ];
    }

    /**
     * Delete a single row from a sheet using the batchUpdate API.
     * Row index is 0-based for the API (so pass row_num - 1).
     *
     * @param string $spreadsheet_id
     * @param string $sheet_name      Tab name (used to look up sheetId).
     * @param int    $row_num         1-based row number to delete.
     */
    public function delete_row( string $spreadsheet_id, string $sheet_name, int $row_num ): void {
        $sheet_id = $this->get_sheet_id( $spreadsheet_id, $sheet_name );

        $url  = self::BASE . '/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate';
        $body = array(
            'requests' => array(
                array(
                    'deleteDimension' => array(
                        'range' => array(
                            'sheetId'    => $sheet_id,
                            'dimension'  => 'ROWS',
                            'startIndex' => $row_num - 1, // 0-based
                            'endIndex'   => $row_num,     // exclusive
                        ),
                    ),
                ),
            ),
        );

        SheetSync_Google_Auth::api_post( $url, $body );
    }

    /**
     * Status dropdown on column C so merchants pick valid WooCommerce slugs.
     *
     * @param list<string> $status_slugs e.g. pending, processing, completed.
     */
    public function apply_order_status_dropdown(
        string $spreadsheet_id,
        string $sheet_name,
        array $status_slugs,
        int $data_start_row = 3,
        int $row_capacity = 500
    ): void {
        if ( empty( $status_slugs ) ) {
            return;
        }

        $sheet_id    = $this->get_sheet_id( $spreadsheet_id, $sheet_name );
        $start_index = max( 0, $data_start_row - 1 );
        $end_index   = $start_index + max( 50, $row_capacity );
        $col_index   = 2; // Column C.

        $url = self::BASE . '/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate';
        SheetSync_Google_Auth::api_post(
            $url,
            array(
                'requests' => array(
                    array(
                        'setDataValidation' => array(
                            'range' => array(
                                'sheetId'          => $sheet_id,
                                'startRowIndex'    => $start_index,
                                'endRowIndex'      => $end_index,
                                'startColumnIndex' => $col_index,
                                'endColumnIndex'   => $col_index + 1,
                            ),
                            'rule'  => array(
                                'condition' => array(
                                    'type'   => 'ONE_OF_LIST',
                                    'values' => array_map(
                                        static fn( string $v ) => array( 'userEnteredValue' => $v ),
                                        $status_slugs
                                    ),
                                ),
                                'showCustomUi' => true,
                                'strict'       => false,
                            ),
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * Get the numeric sheetId for a named tab.
     * Caches result per spreadsheet per request.
     *
     * @param string $spreadsheet_id
     * @param string $sheet_name
     * @return int sheetId (0 = first sheet)
     * @throws RuntimeException if tab not found.
     */
    public function get_sheet_id( string $spreadsheet_id, string $sheet_name, bool $allow_cache_refresh = true ): int {
        $props = $this->load_sheet_properties( $spreadsheet_id );
        $key   = $spreadsheet_id . '::' . $sheet_name;

        if ( ! isset( $props['ids'][ $key ] ) ) {
            if ( $allow_cache_refresh ) {
                $this->invalidate_sheet_grid_cache( $spreadsheet_id, $sheet_name );
                return $this->get_sheet_id( $spreadsheet_id, $sheet_name, false );
            }
            throw new RuntimeException(
                esc_html(
                    sprintf(
                        /* translators: %s: Google Sheet tab name */
                        __( "Sheet tab '%s' not found in spreadsheet.", 'sheetsync-for-woocommerce' ),
                        $sheet_name
                    )
                )
            );
        }

        return (int) $props['ids'][ $key ];
    }

    /**
     * Load sheet tab properties once per spreadsheet per request (IDs + grid sizes).
     *
     * @return array{ids: array<string, int>, grids: array<string, array{rows: int, columns: int}>}
     */
    private function load_sheet_properties( string $spreadsheet_id ): array {
        if ( isset( self::$props_request_cache[ $spreadsheet_id ] ) ) {
            return self::$props_request_cache[ $spreadsheet_id ];
        }

        $transient_key = 'sheetsync_sheet_props_' . md5( $spreadsheet_id );
        $stored        = get_transient( $transient_key );
        if ( is_array( $stored )
            && isset( $stored['ids'], $stored['grids'] )
            && is_array( $stored['ids'] )
            && is_array( $stored['grids'] ) ) {
            self::$props_request_cache[ $spreadsheet_id ] = $stored;
            return self::$props_request_cache[ $spreadsheet_id ];
        }

        $url      = self::BASE . '/' . rawurlencode( $spreadsheet_id ) . '?fields=sheets.properties';
        $response = SheetSync_Google_Auth::api_get( $url );

        $ids   = array();
        $grids = array();
        foreach ( $response['sheets'] ?? array() as $sheet ) {
            $props = $sheet['properties'] ?? array();
            $title = (string) ( $props['title'] ?? '' );
            if ( '' === $title ) {
                continue;
            }
            $cache_key    = $spreadsheet_id . '::' . $title;
            $ids[ $cache_key ] = (int) ( $props['sheetId'] ?? 0 );
            $grid              = $props['gridProperties'] ?? array();
            $grids[ $cache_key ] = array(
                'rows'    => max( 1, (int) ( $grid['rowCount'] ?? 1000 ) ),
                'columns' => max( 1, (int) ( $grid['columnCount'] ?? 26 ) ),
            );
        }

        self::$props_request_cache[ $spreadsheet_id ] = array(
            'ids'   => $ids,
            'grids' => $grids,
        );
        set_transient( $transient_key, self::$props_request_cache[ $spreadsheet_id ], HOUR_IN_SECONDS );

        return self::$props_request_cache[ $spreadsheet_id ];
    }

    /**
     * Drop cached grid dimensions after expanding a tab (next read refetches API).
     */
    public function invalidate_sheet_grid_cache( string $spreadsheet_id, string $sheet_name = '' ): void {
        unset( self::$props_request_cache[ $spreadsheet_id ] );
        delete_transient( 'sheetsync_sheet_props_' . md5( $spreadsheet_id ) );
        if ( '' !== $sheet_name ) {
            delete_transient(
                'sheetsync_sheet_rows_' . md5( $spreadsheet_id . '|' . $sheet_name )
            );
        }
    }

    /**
     * Ensure a sheet tab exists; create it if it doesn't.
     * Returns the sheetId (numeric) of the tab.
     */
    public function ensure_sheet_exists( string $spreadsheet_id, string $sheet_name ): int {
        // Try to get existing sheet id first
        try {
            return $this->get_sheet_id( $spreadsheet_id, $sheet_name );
        } catch ( RuntimeException $e ) {
            // Tab not found — create it
        }

        $url  = self::BASE . '/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate';
        $body = array(
            'requests' => array(
                array(
                    'addSheet' => array(
                        'properties' => array(
                            'title' => $sheet_name,
                        ),
                    ),
                ),
            ),
        );

        $result   = SheetSync_Google_Auth::api_post( $url, $body );
        $sheet_id = (int) ( $result['replies'][0]['addSheet']['properties']['sheetId'] ?? 0 );

        $this->invalidate_sheet_grid_cache( $spreadsheet_id, $sheet_name );

        try {
            return $this->get_sheet_id( $spreadsheet_id, $sheet_name, false );
        } catch ( RuntimeException $e ) {
            return $sheet_id;
        }
    }

    /**
     * Current grid size for a tab (rows × columns).
     *
     * @return array{rows: int, columns: int}
     */
    public function get_sheet_grid_size( string $spreadsheet_id, string $sheet_name ): array {
        $props = $this->load_sheet_properties( $spreadsheet_id );
        $key   = $spreadsheet_id . '::' . $sheet_name;

        if ( ! isset( $props['grids'][ $key ] ) ) {
            throw new RuntimeException(
                esc_html(
                    sprintf(
                        /* translators: %s: Google Sheet tab name */
                        __( "Sheet tab '%s' not found in spreadsheet.", 'sheetsync-for-woocommerce' ),
                        $sheet_name
                    )
                )
            );
        }

        return $props['grids'][ $key ];
    }

    /**
     * Grow the tab grid so writes to $min_rows / $min_columns do not hit "exceeds grid limits".
     *
     * @return bool True when rows or columns were added.
     */
    public function ensure_sheet_grid_capacity(
        string $spreadsheet_id,
        string $sheet_name,
        int $min_rows,
        int $min_columns
    ): bool {
        $min_rows    = max( 1, $min_rows );
        $min_columns = max( 1, $min_columns );
        $this->ensure_sheet_exists( $spreadsheet_id, $sheet_name );
        $size        = $this->get_sheet_grid_size( $spreadsheet_id, $sheet_name );
        $sheet_id    = $this->get_sheet_id( $spreadsheet_id, $sheet_name );
        $requests    = array();

        if ( $size['rows'] < $min_rows ) {
            $requests[] = array(
                'appendDimension' => array(
                    'sheetId'   => $sheet_id,
                    'dimension' => 'ROWS',
                    'length'    => $min_rows - $size['rows'],
                ),
            );
        }

        if ( $size['columns'] < $min_columns ) {
            $requests[] = array(
                'appendDimension' => array(
                    'sheetId'   => $sheet_id,
                    'dimension' => 'COLUMNS',
                    'length'    => $min_columns - $size['columns'],
                ),
            );
        }

        if ( empty( $requests ) ) {
            return false;
        }

        $url = self::BASE . '/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate';
        SheetSync_Google_Auth::api_post( $url, array( 'requests' => $requests ) );

        $this->invalidate_sheet_grid_cache( $spreadsheet_id, $sheet_name );

        return true;
    }

    /**
     * Clear all content from a sheet tab (preserves formatting).
     * Uses the correct empty-object body that the Sheets API expects.
     */
    public function clear_sheet( string $spreadsheet_id, string $sheet_name ): void {
        $url = self::BASE . '/' . rawurlencode( $spreadsheet_id )
             . '/values/' . rawurlencode( self::quote_sheet_tab( $sheet_name ) ) . ':clear';

        // The Sheets clear endpoint requires an empty JSON object body {}, not [].
        SheetSync_Google_Auth::api_post( $url, new stdClass() );
    }

    /**
     * Clear values in a specific A1 range (e.g. Sheet1!A501:Z1000).
     */
    public function clear_range( string $spreadsheet_id, string $range ): void {
        $url = self::BASE . '/' . rawurlencode( $spreadsheet_id )
             . '/values/' . rawurlencode( $range ) . ':clear';

        SheetSync_Google_Auth::api_post( $url, new stdClass() );
    }

    /**
     * Reset a dashboard export tab: clear values, unmerge cells, and strip stale formatting.
     *
     * clear_sheet() alone leaves merged cells and colors from prior exports, which breaks
     * re-exports when section row counts change between runs.
     *
     * @param int $data_row_count Rows written in the new export (used to size the reset window).
     */
    public function reset_sheet_for_dashboard_export( string $spreadsheet_id, string $sheet_name, int $data_row_count = 200 ): void {
        $this->clear_sheet( $spreadsheet_id, $sheet_name );

        $sheet_id   = $this->get_sheet_id( $spreadsheet_id, $sheet_name );
        $reset_rows = max( 200, min( $data_row_count + 100, 2000 ) );
        $reset_cols = 12;

        $white = array( 'red' => 1.0, 'green' => 1.0, 'blue' => 1.0, 'alpha' => 1.0 );
        $black = array( 'red' => 0.0, 'green' => 0.0, 'blue' => 0.0, 'alpha' => 1.0 );

        $grid_range = array(
            'sheetId'          => $sheet_id,
            'startRowIndex'    => 0,
            'endRowIndex'      => $reset_rows,
            'startColumnIndex' => 0,
            'endColumnIndex'   => $reset_cols,
        );

        $requests = array(
            array(
                'unmergeCells' => array(
                    'range' => array( 'sheetId' => $sheet_id ),
                ),
            ),
            array(
                'updateSheetProperties' => array(
                    'properties' => array(
                        'sheetId'        => $sheet_id,
                        'gridProperties' => array(
                            'frozenRowCount'    => 0,
                            'frozenColumnCount' => 0,
                        ),
                    ),
                    'fields' => 'gridProperties.frozenRowCount,gridProperties.frozenColumnCount',
                ),
            ),
            array(
                'repeatCell' => array(
                    'range'  => $grid_range,
                    'cell'   => array(
                        'userEnteredFormat' => array(
                            'backgroundColor'     => $white,
                            'textFormat'          => array(
                                'bold'            => false,
                                'italic'          => false,
                                'fontSize'        => 10,
                                'foregroundColor' => $black,
                            ),
                            'horizontalAlignment' => 'LEFT',
                            'verticalAlignment'   => 'MIDDLE',
                            'wrapStrategy'        => 'CLIP',
                        ),
                    ),
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment,wrapStrategy)',
                ),
            ),
            array(
                'updateCells' => array(
                    'range'  => $grid_range,
                    'fields' => 'userEnteredValue',
                ),
            ),
        );

        $this->batch_update_requests( $spreadsheet_id, $requests );
    }

    /**
     * Write a styled header row to the Google Sheet.
     *
     * Writes the header values, then applies formatting:
     *  - Bold text, white foreground
     *  - Dark green background (#1e7e34)
     *  - Frozen first row
     *  - Auto-resize columns
     *
     * @param string   $spreadsheet_id
     * @param string   $sheet_name
     * @param int      $header_row    1-based row number for headers.
     * @param string[] $headers       Ordered list of header label strings.
     */
    public function write_styled_headers(
        string $spreadsheet_id,
        string $sheet_name,
        int    $header_row,
        array  $headers
    ): void {

        $sheet_id   = $this->get_sheet_id( $spreadsheet_id, $sheet_name );
        $col_count  = count( $headers );
        $row_index  = $header_row - 1; // 0-based

        // ── 1. Write header values ────────────────────────────────────────
        $col_end = SheetSync_Field_Mapper::index_to_col( $col_count - 1 );
        $range   = self::tab_range( $sheet_name, 'A' . $header_row . ':' . $col_end . $header_row );
        $this->set_rows( $spreadsheet_id, $range, [ $headers ] );

        // ── 2. Build batchUpdate requests ─────────────────────────────────
        $requests = array();

        // 2a. Style: bold + white text + dark green background + center align
        $requests[] = array(
            'repeatCell' => array(
                'range' => array(
                    'sheetId'          => $sheet_id,
                    'startRowIndex'    => $row_index,
                    'endRowIndex'      => $row_index + 1,
                    'startColumnIndex' => 0,
                    'endColumnIndex'   => $col_count,
                ),
                'cell' => array(
                    'userEnteredFormat' => array(
                        'backgroundColor' => array(
                            'red'   => 0.1176,  // #1e  = 30/255
                            'green' => 0.4941,  // #7e  = 126/255
                            'blue'  => 0.2039,  // #34  = 52/255
                        ),
                        'textFormat' => array(
                            'bold'       => true,
                            'fontSize'   => 11,
                            'foregroundColor' => array(
                                'red'   => 1.0,
                                'green' => 1.0,
                                'blue'  => 1.0,
                            ),
                        ),
                        'horizontalAlignment' => 'CENTER',
                        'verticalAlignment'   => 'MIDDLE',
                        'wrapStrategy'        => 'CLIP',
                    ),
                ),
                'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment,wrapStrategy)',
            ),
        );

        // 2b. Freeze the header row
        $requests[] = array(
            'updateSheetProperties' => array(
                'properties' => array(
                    'sheetId'     => $sheet_id,
                    'gridProperties' => array(
                        'frozenRowCount' => $header_row,
                    ),
                ),
                'fields' => 'gridProperties.frozenRowCount',
            ),
        );

        // 2c. Auto-resize all header columns
        $requests[] = array(
            'autoResizeDimensions' => array(
                'dimensions' => array(
                    'sheetId'    => $sheet_id,
                    'dimension'  => 'COLUMNS',
                    'startIndex' => 0,
                    'endIndex'   => $col_count,
                ),
            ),
        );

        // 2d. Set row height for the header row to 32px
        $requests[] = array(
            'updateDimensionProperties' => array(
                'range' => array(
                    'sheetId'    => $sheet_id,
                    'dimension'  => 'ROWS',
                    'startIndex' => $row_index,
                    'endIndex'   => $row_index + 1,
                ),
                'properties' => array(
                    'pixelSize' => 32,
                ),
                'fields' => 'pixelSize',
            ),
        );

        $url = self::BASE . '/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate';
        SheetSync_Google_Auth::api_post( $url, array( 'requests' => $requests ) );

        // ── Also style any existing data rows below the header ───────────
        try {
            $all_rows = $this->get_rows( $spreadsheet_id, self::tab_range( $sheet_name, 'A:A' ) );
            $data_count = count( $all_rows ) - $header_row;
            if ( $data_count > 0 ) {
                $this->apply_row_colors( $spreadsheet_id, $sheet_name, $header_row + 1, $data_count, $col_count );
            }
        } catch ( \Exception $e ) {
            // Non-fatal
        }
    }

    /**
     * Apply alternating row colors to data rows using Google Sheets BandedRange API.
     *
     * Uses a single batchUpdate with:
     *  1. addBanding  — server-side alternating row colors (1 request, works for any row count)
     *  2. updateDimensionProperties — row height (24px) for all data rows at once
     *  3. updateDimensionProperties — column widths (one request per column)
     *  4. updateSheetProperties — freeze header row
     *
     * Total: ~4 requests regardless of row count. Replaces the old O(N) per-row approach
     * that caused quota exhaustion for large catalogs.
     *
     * Colors:
     *  - First band (odd rows)  : white       (#FFFFFF)
     *  - Second band (even rows): light green  (#EAF7EE)
     * Header: dark green (#1e7e34) with white bold text — set by write_styled_headers().
     *
     * @param string $spreadsheet_id
     * @param string $sheet_name
     * @param int    $first_data_row_1based 1-based row number of the first data row (e.g. 2 below header on row 1).
     * @param int    $data_row_count        Number of data rows to style.
     * @param int    $col_count             Number of columns.
     */
    public function apply_row_colors(
        string $spreadsheet_id,
        string $sheet_name,
        int    $first_data_row_1based,
        int    $data_row_count,
        int    $col_count
    ): void {

        if ( $data_row_count < 1 || $col_count < 1 ) return;

        $sheet_id   = $this->get_sheet_id( $spreadsheet_id, $sheet_name );
        $first_data = max( 0, $first_data_row_1based - 1 ); // 0-based index for the Sheets API

        $c = fn( float $r, float $g, float $b ) => array( 'red' => $r, 'green' => $g, 'blue' => $b );

        $white       = $c( 1.0,   1.0,   1.0   );   // odd rows  — #FFFFFF
        $light_green = $c( 0.918, 0.969, 0.933 );   // even rows — #EAF7EE
        $dark_text   = $c( 0.102, 0.102, 0.102 );   // text      — #1a1a1a

        $requests = array();

        // ── 1. Remove existing banded ranges on this sheet first ──────────
        // Prevents duplicate bands stacking up on repeated syncs.
        try {
            $meta = SheetSync_Google_Auth::api_get(
                self::BASE . '/' . rawurlencode( $spreadsheet_id )
                . '?fields=sheets(properties(sheetId,title),bandedRanges)'
            );
            foreach ( $meta['sheets'] ?? array() as $s ) {
                if ( ( $s['properties']['sheetId'] ?? -1 ) !== $sheet_id ) continue;
                foreach ( $s['bandedRanges'] ?? array() as $band ) {
                    if ( isset( $band['bandedRangeId'] ) ) {
                        $requests[] = array(
                            'deleteBanding' => array( 'bandedRangeId' => $band['bandedRangeId'] ),
                        );
                    }
                }
            }
        } catch ( \Exception $e ) {
            // Non-fatal — proceed without deleting old bands
        }

        // ── 2. Add BandedRange for alternating row colors ─────────────────
        // BandedRange is applied server-side by Google Sheets — zero extra API
        // calls per row. Works correctly for any number of rows.
        $requests[] = array(
            'addBanding' => array(
                'bandedRange' => array(
                    'range' => array(
                        'sheetId'          => $sheet_id,
                        'startRowIndex'    => $first_data,        // 0-based, first data row
                        'endRowIndex'      => $first_data + $data_row_count,
                        'startColumnIndex' => 0,
                        'endColumnIndex'   => $col_count,
                    ),
                    'rowProperties' => array(
                        'headerColor'     => null,                 // no header (header styled separately)
                        'firstBandColor'  => $white,               // odd rows — white
                        'secondBandColor' => $light_green,         // even rows — #EAF7EE
                    ),
                ),
            ),
        );

        // ── 3. Apply text formatting to ALL data rows (single repeatCell) ──
        // BandedRange handles background only; text format needs repeatCell.
        $requests[] = array(
            'repeatCell' => array(
                'range' => array(
                    'sheetId'          => $sheet_id,
                    'startRowIndex'    => $first_data,
                    'endRowIndex'      => $first_data + $data_row_count,
                    'startColumnIndex' => 0,
                    'endColumnIndex'   => $col_count,
                ),
                'cell' => array(
                    'userEnteredFormat' => array(
                        'textFormat'    => array(
                            'bold'            => false,
                            'fontSize'        => 10,
                            'foregroundColor' => $dark_text,
                        ),
                        'verticalAlignment' => 'MIDDLE',
                        'wrapStrategy'      => 'CLIP',
                    ),
                ),
                'fields' => 'userEnteredFormat(textFormat,verticalAlignment,wrapStrategy)',
            ),
        );

        // ── 4. Force ALL data rows to exactly 24px height (one request) ───
        $requests[] = array(
            'updateDimensionProperties' => array(
                'range' => array(
                    'sheetId'    => $sheet_id,
                    'dimension'  => 'ROWS',
                    'startIndex' => $first_data,
                    'endIndex'   => $first_data + $data_row_count,
                ),
                'properties' => array(
                    'pixelSize'    => 24,
                    'hiddenByUser' => false,
                ),
                'fields' => 'pixelSize',
            ),
        );

        // ── 5. Freeze header row ──────────────────────────────────────────
        $frozen_rows = max( 1, $first_data_row_1based - 1 );
        $requests[] = array(
            'updateSheetProperties' => array(
                'properties' => array(
                    'sheetId'        => $sheet_id,
                    'gridProperties' => array(
                        'frozenRowCount' => $frozen_rows,
                    ),
                ),
                'fields' => 'gridProperties.frozenRowCount',
            ),
        );

        // ── 6. Set column widths ──────────────────────────────────────────
        // Field-aware widths: SKU narrow, Title wide, descriptions wider.
        // Map column index to field name for smart widths.
        $col_field_map = array();
        // We don't have $maps here, so use position-based heuristics:
        // col 0 = SKU (narrow), col 1 = Title (wide), rest = medium
        for ( $c_idx = 0; $c_idx < $col_count; $c_idx++ ) {
            if ( $c_idx === 0 ) {
                $px = 100;   // SKU
            } elseif ( $c_idx === 1 ) {
                $px = 180;   // Product Title — wider
            } elseif ( $c_idx === 2 || $c_idx === 3 ) {
                $px = 110;   // Price / Stock
            } elseif ( $c_idx === 4 ) {
                $px = 110;   // Status
            } else {
                $px = 130;   // Pro fields — slightly wider
            }
            $requests[] = array(
                'updateDimensionProperties' => array(
                    'range' => array(
                        'sheetId'    => $sheet_id,
                        'dimension'  => 'COLUMNS',
                        'startIndex' => $c_idx,
                        'endIndex'   => $c_idx + 1,
                    ),
                    'properties' => array( 'pixelSize' => $px ),
                    'fields'     => 'pixelSize',
                ),
            );
        }

        $url = self::BASE . '/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate';
        SheetSync_Google_Auth::api_post( $url, array( 'requests' => $requests ) );
    }

    /**
     * Beautiful styling for the product import template (Write template to Google Sheet).
     *
     * @param int $example_rows Number of example data rows (rows 2…N).
     * @param int $guide_row_1based 1-based row number for the merged guide banner (e.g. 5). 0 = none.
     * @param int $header_row_1based 1-based row for column titles (usually 1).
     */
    public function format_product_template_sheet(
        string $spreadsheet_id,
        string $sheet_name,
        int    $col_count = 23,
        int    $example_rows = 3,
        int    $guide_row_1based = 5,
        int    $header_row_1based = 1
    ): void {
        $sheet_id   = $this->get_sheet_id( $spreadsheet_id, $sheet_name );
        $c          = fn( float $r, float $g, float $b ) => array( 'red' => $r, 'green' => $g, 'blue' => $b );
        $h          = max( 0, $header_row_1based - 1 );
        $data_start = $h + 1;

        $header_sections = array(
            array( 0, 6, $c( 0.102, 0.337, 0.529 ) ),   // Core A–F — blue
            array( 6, 14, $c( 0.153, 0.322, 0.529 ) ),  // Details G–N
            array( 14, 16, $c( 0.827, 0.329, 0.0 ) ),   // Images O–P — orange
            array( 16, 20, $c( 0.478, 0.153, 0.565 ) ), // Taxonomy Q–T — purple
            array( 20, $col_count, $c( 0.710, 0.475, 0.039 ) ), // Variable U–W — gold
        );

        $requests = array();

        foreach ( $header_sections as $section ) {
            list( $start, $end, $bg ) = $section;
            if ( $start >= $col_count ) {
                continue;
            }
            $end = min( $end, $col_count );
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => array(
                        'sheetId'          => $sheet_id,
                        'startRowIndex'    => $h,
                        'endRowIndex'      => $h + 1,
                        'startColumnIndex' => $start,
                        'endColumnIndex'   => $end,
                    ),
                    'cell'   => array(
                        'userEnteredFormat' => array(
                            'backgroundColor'     => $bg,
                            'textFormat'          => array(
                                'bold'            => true,
                                'fontSize'        => 10,
                                'foregroundColor' => $c( 1.0, 1.0, 1.0 ),
                            ),
                            'horizontalAlignment' => 'CENTER',
                            'verticalAlignment'   => 'MIDDLE',
                            'wrapStrategy'        => 'WRAP',
                        ),
                    ),
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment,wrapStrategy)',
                ),
            );
        }

        $requests[] = array(
            'updateSheetProperties' => array(
                'properties' => array(
                    'sheetId'        => $sheet_id,
                    'gridProperties' => array(
                        'frozenRowCount' => $header_row_1based,
                    ),
                ),
                'fields' => 'gridProperties.frozenRowCount',
            ),
        );

        $requests[] = array(
            'updateDimensionProperties' => array(
                'range'      => array(
                    'sheetId'    => $sheet_id,
                    'dimension'  => 'ROWS',
                    'startIndex' => $h,
                    'endIndex'   => $h + 1,
                ),
                'properties' => array( 'pixelSize' => 44 ),
                'fields'     => 'pixelSize',
            ),
        );

        $widths = array( 115, 200, 95, 95, 130, 72, 95, 150, 110, 68, 58, 58, 58, 160, 210, 210, 105, 115, 95, 145, 175, 88, 88 );
        for ( $i = 0; $i < $col_count; $i++ ) {
            $px = $widths[ $i ] ?? 120;
            $requests[] = array(
                'updateDimensionProperties' => array(
                    'range'      => array(
                        'sheetId'    => $sheet_id,
                        'dimension'  => 'COLUMNS',
                        'startIndex' => $i,
                        'endIndex'   => $i + 1,
                    ),
                    'properties' => array( 'pixelSize' => $px ),
                    'fields'     => 'pixelSize',
                ),
            );
        }

        $header_notes = array(
            0  => 'Required. Unique product ID (e.g. DEMO-TSH-001).',
            1  => 'Product name shown in your store.',
            2  => 'Numbers only — no currency symbol (e.g. 29.99).',
            14 => 'Featured image: Media Library URL, any https image link, or attachment ID (e.g. 1234).',
            15 => 'Gallery: comma-separated URLs or IDs. Reuses Media Library files when possible.',
            16 => 'simple = one product | variable = parent | leave empty on variation rows.',
            19 => 'Variation rows only: parent SKU (e.g. DEMO-HOODIE-01).',
            21 => 'Parent: red,black | Variation: single value red. No pa_color needed.',
            22 => 'Parent: s,m | Variation: single value s. No pa_size needed.',
        );
        foreach ( $header_notes as $col_idx => $note ) {
            if ( $col_idx >= $col_count ) {
                continue;
            }
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => array(
                        'sheetId'          => $sheet_id,
                        'startRowIndex'    => $h,
                        'endRowIndex'      => $h + 1,
                        'startColumnIndex' => $col_idx,
                        'endColumnIndex'   => $col_idx + 1,
                    ),
                    'cell'   => array( 'note' => $note ),
                    'fields' => 'note',
                ),
            );
        }

        $example_styles = array(
            1 => array( $c( 0.922, 0.965, 0.996 ), 'SIMPLE — 1 row = 1 product' ),      // row 2
            2 => array( $c( 1.0, 0.976, 0.902 ), 'PARENT — variable product' ),         // row 3
            3 => array( $c( 0.925, 0.980, 0.941 ), 'VARIATION — under parent SKU' ),    // row 4
        );
        for ( $r = 1; $r <= $example_rows; $r++ ) {
            if ( ! isset( $example_styles[ $r ] ) ) {
                continue;
            }
            list( $bg, $label ) = $example_styles[ $r ];
            $row_idx = $h + $r;
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => array(
                        'sheetId'          => $sheet_id,
                        'startRowIndex'    => $row_idx,
                        'endRowIndex'      => $row_idx + 1,
                        'startColumnIndex' => 0,
                        'endColumnIndex'   => $col_count,
                    ),
                    'cell'   => array(
                        'userEnteredFormat' => array(
                            'backgroundColor' => $bg,
                            'textFormat'      => array(
                                'fontSize'        => 10,
                                'foregroundColor' => $c( 0.15, 0.15, 0.15 ),
                            ),
                            'verticalAlignment' => 'MIDDLE',
                            'wrapStrategy'      => 'CLIP',
                        ),
                    ),
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat,verticalAlignment,wrapStrategy)',
                ),
            );
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => array(
                        'sheetId'          => $sheet_id,
                        'startRowIndex'    => $row_idx,
                        'endRowIndex'      => $row_idx + 1,
                        'startColumnIndex' => 0,
                        'endColumnIndex'   => 1,
                    ),
                    'cell'   => array(
                        'userEnteredFormat' => array(
                            'textFormat' => array( 'bold' => true, 'fontSize' => 9 ),
                        ),
                        'note'       => $label,
                    ),
                    'fields' => 'userEnteredFormat(textFormat),note',
                ),
            );
            $requests[] = array(
                'updateDimensionProperties' => array(
                    'range'      => array(
                        'sheetId'    => $sheet_id,
                        'dimension'  => 'ROWS',
                        'startIndex' => $row_idx,
                        'endIndex'   => $row_idx + 1,
                    ),
                    'properties' => array( 'pixelSize' => 28 ),
                    'fields'     => 'pixelSize',
                ),
            );
        }

        if ( $guide_row_1based > 0 ) {
            $guide_idx = $guide_row_1based - 1;
            $requests[] = array(
                'mergeCells' => array(
                    'range' => array(
                        'sheetId'          => $sheet_id,
                        'startRowIndex'    => $guide_idx,
                        'endRowIndex'      => $guide_idx + 1,
                        'startColumnIndex' => 0,
                        'endColumnIndex'   => $col_count,
                    ),
                    'mergeType' => 'MERGE_ALL',
                ),
            );
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => array(
                        'sheetId'          => $sheet_id,
                        'startRowIndex'    => $guide_idx,
                        'endRowIndex'      => $guide_idx + 1,
                        'startColumnIndex' => 0,
                        'endColumnIndex'   => 1,
                    ),
                    'cell'   => array(
                        'userEnteredFormat' => array(
                            'backgroundColor'     => $c( 1.0, 0.973, 0.882 ),
                            'textFormat'          => array(
                                'bold'            => true,
                                'fontSize'        => 10,
                                'foregroundColor' => $c( 0.4, 0.25, 0.0 ),
                                'italic'          => true,
                            ),
                            'horizontalAlignment' => 'LEFT',
                            'verticalAlignment'   => 'MIDDLE',
                            'wrapStrategy'        => 'WRAP',
                        ),
                    ),
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment,wrapStrategy)',
                ),
            );
            $requests[] = array(
                'updateDimensionProperties' => array(
                    'range'      => array(
                        'sheetId'    => $sheet_id,
                        'dimension'  => 'ROWS',
                        'startIndex' => $guide_idx,
                        'endIndex'   => $guide_idx + 1,
                    ),
                    'properties' => array( 'pixelSize' => 36 ),
                    'fields'     => 'pixelSize',
                ),
            );
        }

        // Sample template only — real exports use apply_export_sheet_filters() after sync.
        if ( $example_rows > 0 ) {
            $validation_end = $data_start + $example_rows + 50;
            $validations    = array(
                array( 4, array( 'publish', 'draft' ) ),
                array( 8, array( 'instock', 'outofstock', 'onbackorder' ) ),
                array( 16, array( 'simple', 'variable' ) ),
            );
            foreach ( $validations as $vd ) {
                list( $col_idx, $values ) = $vd;
                if ( $col_idx >= $col_count ) {
                    continue;
                }
                $requests[] = array(
                    'setDataValidation' => array(
                        'range' => array(
                            'sheetId'          => $sheet_id,
                            'startRowIndex'    => $data_start,
                            'endRowIndex'      => $validation_end,
                            'startColumnIndex' => $col_idx,
                            'endColumnIndex'   => $col_idx + 1,
                        ),
                        'rule'  => array(
                            'condition' => array(
                                'type'   => 'ONE_OF_LIST',
                                'values' => array_map(
                                    static fn( string $v ) => array( 'userEnteredValue' => $v ),
                                    $values
                                ),
                            ),
                            'showCustomUi' => true,
                            'strict'       => false,
                        ),
                    ),
                );
            }

            $requests[] = array(
                'setBasicFilter' => array(
                    'filter' => array(
                        'range' => array(
                            'sheetId'          => $sheet_id,
                            'startRowIndex'    => $h,
                            'endRowIndex'      => $validation_end,
                            'startColumnIndex' => 0,
                            'endColumnIndex'   => $col_count,
                        ),
                    ),
                ),
            );
        }

        if ( empty( $requests ) ) {
            return;
        }

        $url = self::BASE . '/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate';
        SheetSync_Google_Auth::api_post( $url, array( 'requests' => $requests ) );
    }

    /**
     * Style the SheetSync Help tab.
     */
    public function format_help_tab( string $spreadsheet_id, string $sheet_name ): void {
        $sheet_id = $this->get_sheet_id( $spreadsheet_id, $sheet_name );
        $c        = fn( float $r, float $g, float $b ) => array( 'red' => $r, 'green' => $g, 'blue' => $b );

        $requests = array(
            array(
                'repeatCell' => array(
                    'range'  => array(
                        'sheetId'          => $sheet_id,
                        'startRowIndex'    => 0,
                        'endRowIndex'      => 1,
                        'startColumnIndex' => 0,
                        'endColumnIndex'   => 2,
                    ),
                    'cell'   => array(
                        'userEnteredFormat' => array(
                            'backgroundColor' => $c( 0.1176, 0.4941, 0.2039 ),
                            'textFormat'      => array(
                                'bold'            => true,
                                'fontSize'        => 14,
                                'foregroundColor' => $c( 1.0, 1.0, 1.0 ),
                            ),
                            'wrapStrategy'    => 'WRAP',
                        ),
                    ),
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat,wrapStrategy)',
                ),
            ),
            array(
                'updateDimensionProperties' => array(
                    'range'      => array(
                        'sheetId'    => $sheet_id,
                        'dimension'  => 'COLUMNS',
                        'startIndex' => 0,
                        'endIndex'   => 1,
                    ),
                    'properties' => array( 'pixelSize' => 720 ),
                    'fields'     => 'pixelSize',
                ),
            ),
            array(
                'repeatCell' => array(
                    'range'  => array(
                        'sheetId'          => $sheet_id,
                        'startRowIndex'    => 1,
                        'endRowIndex'      => 20,
                        'startColumnIndex' => 0,
                        'endColumnIndex'   => 1,
                    ),
                    'cell'   => array(
                        'userEnteredFormat' => array(
                            'textFormat'   => array( 'fontSize' => 11 ),
                            'wrapStrategy' => 'WRAP',
                        ),
                    ),
                    'fields' => 'userEnteredFormat(textFormat,wrapStrategy)',
                ),
            ),
        );

        $url = self::BASE . '/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate';
        SheetSync_Google_Auth::api_post( $url, array( 'requests' => $requests ) );
    }

    /**
     * Find the row number (1-based) where a column contains a specific value.
     * Returns 0 if not found.
     */
    public function find_row_by_value(
        string $spreadsheet_id,
        string $sheet_name,
        string $column,
        string $value,
        int    $header_rows = 1
    ): int {
        $range    = $sheet_name . '!' . $column . ':' . $column;
        $col_data = $this->get_rows( $spreadsheet_id, $range );

        foreach ( $col_data as $i => $row ) {
            if ( $i < $header_rows ) continue;
            if ( ( $row[0] ?? '' ) === $value ) {
                return $i + 1; // 1-based
            }
        }
        return 0;
    }

    /**
     * Enable header filters + dropdowns on key columns so users can find products in large sheets.
     *
     * @param array<string, array{sheet_column: string, is_key_field: bool}> $maps
     */
    public function apply_export_sheet_filters(
        string $spreadsheet_id,
        string $sheet_name,
        int $header_row_1based,
        int $data_row_count,
        array $maps,
        int $connection_id = 0
    ): void {
        if ( empty( $maps ) ) {
            return;
        }

        $col_count = SheetSync_Field_Mapper::col_to_index( SheetSync_Field_Mapper::max_column_letter( $maps ) ) + 1;
        if ( $col_count < 1 ) {
            return;
        }

        $sheet_id = $this->get_sheet_id( $spreadsheet_id, $sheet_name );
        $h        = max( 0, $header_row_1based - 1 );

        $last_mapped_row = 0;
        if ( $connection_id > 0 && class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
            $last_mapped_row = ( new SheetSync_Product_Map_Repository() )->get_max_sheet_row( $connection_id );
        }
        $filter_end = max(
            $h + 1 + max( $data_row_count, 1 ) + 25,
            $last_mapped_row + 10,
            $h + 2
        );

        $requests = array();

        try {
            $meta = SheetSync_Google_Auth::api_get(
                self::BASE . '/' . rawurlencode( $spreadsheet_id )
                . '?fields=sheets(properties(sheetId,title),basicFilter)'
            );
            foreach ( $meta['sheets'] ?? array() as $s ) {
                if ( ( $s['properties']['sheetId'] ?? -1 ) !== $sheet_id ) {
                    continue;
                }
                if ( ! empty( $s['basicFilter'] ) ) {
                    $requests[] = array(
                        'clearBasicFilter' => array( 'sheetId' => $sheet_id ),
                    );
                }
                break;
            }
        } catch ( \Exception $e ) {
            // Non-fatal.
        }

        // Remove legacy dropdown rules that break native Sort A→Z / Filter by condition.
        $data_start = $h + 1;
        if ( $filter_end > $data_start ) {
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => array(
                        'sheetId'          => $sheet_id,
                        'startRowIndex'    => $data_start,
                        'endRowIndex'      => $filter_end,
                        'startColumnIndex' => 0,
                        'endColumnIndex'   => $col_count,
                    ),
                    'cell'   => new \stdClass(),
                    'fields' => 'dataValidation',
                ),
            );
        }

        $requests[] = array(
            'setBasicFilter' => array(
                'filter' => array(
                    'range' => array(
                        'sheetId'          => $sheet_id,
                        'startRowIndex'    => $h,
                        'endRowIndex'      => $filter_end,
                        'startColumnIndex' => 0,
                        'endColumnIndex'   => $col_count,
                    ),
                ),
            ),
        );

        $header_notes = array(
            'primary_category' => 'Filter ▼ → Filter by values, or Sort A→Z. One category per row.',
            'sheet_row_role'       => 'Simple product | Variable (main) | Variation (option). Filter ▼ here.',
            'sheet_product_group'  => 'Same SKU on parent + all variations — filter to see one product family.',
            'sheet_option_summary' => 'Readable options (Color, Size). Parent row explains variation count.',
            'sheet_belongs_to'     => 'Variation only: which main product this row belongs to.',
            '_product_cats'    => 'Many categories in one cell: use Filter by condition → Text contains.',
            '_stock_status'    => 'Filter ▼ → instock / outofstock / onbackorder.',
            'post_status'      => 'Filter ▼ → publish / draft.',
            '_sku'             => 'Sort A→Z works here. Or Ctrl+F to find a SKU.',
            'post_title'       => 'Sort A→Z on product names.',
        );

        foreach ( $maps as $field => $info ) {
            if ( empty( $header_notes[ $field ] ) ) {
                continue;
            }
            $col_idx = SheetSync_Field_Mapper::col_to_index( $info['sheet_column'] ?? 'A' );
            if ( $col_idx >= $col_count ) {
                continue;
            }
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => array(
                        'sheetId'          => $sheet_id,
                        'startRowIndex'    => $h,
                        'endRowIndex'      => $h + 1,
                        'startColumnIndex' => $col_idx,
                        'endColumnIndex'   => $col_idx + 1,
                    ),
                    'cell'   => array( 'note' => $header_notes[ $field ] ),
                    'fields' => 'note',
                ),
            );
        }

        if ( empty( $requests ) ) {
            return;
        }

        $this->batch_update_requests( $spreadsheet_id, $requests );
    }

    /**
     * Color-code export rows: PARENT (amber), VARIATION (light green). SIMPLE keeps banding.
     */
    public function apply_export_role_row_highlights(
        string $spreadsheet_id,
        string $sheet_name,
        int $first_data_row_1based,
        int $col_count,
        int $connection_id
    ): void {
        if ( $col_count < 1 || ! class_exists( 'SheetSync_Export_Order', false ) ) {
            return;
        }

        $map_repo = new SheetSync_Product_Map_Repository();
        $maps     = $map_repo->list_ordered_by_sheet_row( $connection_id );
        if ( empty( $maps ) ) {
            return;
        }

        $c = static fn( float $r, float $g, float $b ) => array( 'red' => $r, 'green' => $g, 'blue' => $b );

        $role_colors = array(
            'PARENT'    => $c( 1.0, 0.969, 0.902 ),
            'VARIATION' => $c( 0.925, 0.972, 0.941 ),
        );

        $sheet_id   = $this->get_sheet_id( $spreadsheet_id, $sheet_name );
        $segments   = array();
        $current    = null;

        foreach ( $maps as $map ) {
            $sheet_row = (int) ( $map->sheet_row ?? 0 );
            if ( $sheet_row < $first_data_row_1based ) {
                continue;
            }
            $product = wc_get_product( (int) ( $map->product_id ?? 0 ) );
            if ( ! $product ) {
                continue;
            }
            $role_key = 'SIMPLE';
            if ( $product->is_type( 'variable' ) ) {
                $role_key = 'PARENT';
            } elseif ( $product instanceof WC_Product_Variation ) {
                $role_key = 'VARIATION';
            }
            if ( ! isset( $role_colors[ $role_key ] ) ) {
                $current = null;
                continue;
            }
            if ( $current && $current['role'] === $role_key && $current['end'] + 1 === $sheet_row ) {
                $current['end'] = $sheet_row;
            } else {
                if ( $current ) {
                    $segments[] = $current;
                }
                $current = array(
                    'role'  => $role_key,
                    'start' => $sheet_row,
                    'end'   => $sheet_row,
                );
            }
        }
        if ( $current ) {
            $segments[] = $current;
        }

        $requests = array();
        foreach ( $segments as $seg ) {
            $bg = $role_colors[ $seg['role'] ];
            $fmt = array(
                'backgroundColor' => $bg,
            );
            if ( 'PARENT' === $seg['role'] ) {
                $fmt['textFormat'] = array(
                    'bold'     => true,
                    'fontSize' => 10,
                );
            }
            $requests[] = array(
                'repeatCell' => array(
                    'range'  => array(
                        'sheetId'          => $sheet_id,
                        'startRowIndex'    => $seg['start'] - 1,
                        'endRowIndex'      => $seg['end'],
                        'startColumnIndex' => 0,
                        'endColumnIndex'   => $col_count,
                    ),
                    'cell'   => array(
                        'userEnteredFormat' => $fmt,
                    ),
                    'fields' => 'PARENT' === $seg['role']
                        ? 'userEnteredFormat(backgroundColor,textFormat)'
                        : 'userEnteredFormat.backgroundColor',
                ),
            );
        }

        if ( empty( $requests ) ) {
            return;
        }

        try {
            $this->batch_update_requests( $spreadsheet_id, $requests );
        } catch ( \Exception $e ) {
            // Non-fatal — banding/filters may still apply.
        }
    }

    /**
     * Collapsible row groups: variation rows tuck under each variable parent (▸ in row gutter).
     */
    public function apply_variable_product_row_groups(
        string $spreadsheet_id,
        string $sheet_name,
        int $first_data_row_1based,
        int $connection_id
    ): void {
        if ( $connection_id < 1 || ! class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
            return;
        }

        $maps = ( new SheetSync_Product_Map_Repository() )->list_ordered_by_sheet_row( $connection_id );
        if ( empty( $maps ) ) {
            return;
        }

        $sheet_id = $this->get_sheet_id( $spreadsheet_id, $sheet_name );
        $requests = array();
        $this->append_variable_row_group_requests( $maps, $sheet_id, $first_data_row_1based, $requests );

        if ( empty( $requests ) ) {
            return;
        }

        try {
            $this->batch_update_requests( $spreadsheet_id, $requests );
        } catch ( \Exception $e ) {
            // Non-fatal — colors/filters still apply if grouping fails (e.g. duplicate groups).
        }
    }

    /**
     * @param object[]          $maps
     * @param array<int, mixed> $requests
     */
    private function append_variable_row_group_requests( array $maps, int $sheet_id, int $first_data_row_1based, array &$requests ): void {
        $variation_start = 0;
        $variation_end   = 0;
        $flush_group     = static function () use ( &$variation_start, &$variation_end, &$requests, $sheet_id ): void {
            if ( $variation_start < 1 || $variation_end <= $variation_start ) {
                $variation_start = 0;
                $variation_end   = 0;
                return;
            }
            $requests[] = array(
                'addDimensionGroup' => array(
                    'range' => array(
                        'sheetId'    => $sheet_id,
                        'dimension'  => 'ROWS',
                        'startIndex' => $variation_start - 1,
                        'endIndex'   => $variation_end,
                    ),
                ),
            );
            $variation_start = 0;
            $variation_end   = 0;
        };

        foreach ( $maps as $map ) {
            $sheet_row = (int) ( $map->sheet_row ?? 0 );
            if ( $sheet_row < $first_data_row_1based ) {
                continue;
            }
            $product = wc_get_product( (int) ( $map->product_id ?? 0 ) );
            if ( ! $product ) {
                $flush_group();
                continue;
            }

            if ( $product->is_type( 'variable' ) ) {
                $flush_group();
                continue;
            }

            if ( $product instanceof WC_Product_Variation ) {
                if ( $variation_start < 1 ) {
                    $variation_start = $sheet_row;
                }
                $variation_end = $sheet_row;
                continue;
            }

            $flush_group();
        }
        $flush_group();
    }
}

endif; // class_exists SheetSync_Sheets_Client
