<?php
/**
 * Plugin Name: Genesis Attendance
 * Description: Manual attendance reporting. Use shortcode [attendance_form] to input data.
 * Version: 1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// --- Database setup ---

function genesis_attendance_install() {
    global $wpdb;
    $table   = $wpdb->prefix . 'attendance_log';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        date DATE NOT NULL,
        visitors INT NOT NULL DEFAULT 0,
        PRIMARY KEY (date)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
register_activation_hook( __FILE__, 'genesis_attendance_install' );

// --- Shortcode: [attendance_form] ---

function genesis_attendance_form_shortcode() {
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        return '';
    }

    global $wpdb;
    $table  = $wpdb->prefix . 'attendance_log';
    $notice = '';

    if ( isset( $_POST['genesis_attendance_nonce'] )
        && wp_verify_nonce( $_POST['genesis_attendance_nonce'], 'genesis_attendance_submit' ) ) {

        $date     = sanitize_text_field( $_POST['attendance_date'] ?? '' );
        $visitors = intval( $_POST['attendance_visitors'] ?? 0 );

        if ( $date && $visitors >= 0 ) {
            $wpdb->replace( $table, [
                'date'     => $date,
                'visitors' => $visitors,
            ], [ '%s', '%d' ] );
            $notice = '<p style="color:green;">Saved for ' . esc_html( $date ) . '.</p>';
        } else {
            $notice = '<p style="color:red;">Please enter a valid date and visitor count.</p>';
        }
    }

    $today = date( 'Y-m-d' );

    ob_start(); ?>
    <?php echo $notice; ?>
    <form method="post">
        <?php wp_nonce_field( 'genesis_attendance_submit', 'genesis_attendance_nonce' ); ?>
        <label>Date:<br>
            <input type="date" name="attendance_date" value="<?php echo esc_attr( $today ); ?>" required>
        </label><br><br>
        <label>Visitors:<br>
            <input type="number" name="attendance_visitors" value="" min="0" required>
        </label><br><br>
        <button type="submit">Save</button>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode( 'attendance_form', 'genesis_attendance_form_shortcode' );

// --- Admin menu ---

function genesis_attendance_admin_menu() {
    add_menu_page(
        'Attendance Log',
        'Attendance',
        'manage_options',
        'genesis-attendance',
        'genesis_attendance_admin_page',
        'dashicons-groups',
        30
    );
}
add_action( 'admin_menu', 'genesis_attendance_admin_menu' );

// --- CSV Export ---

function genesis_attendance_export_csv() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
    check_admin_referer( 'genesis_attendance_export' );

    global $wpdb;
    $table = $wpdb->prefix . 'attendance_log';
    $rows  = $wpdb->get_results( "SELECT date, visitors FROM $table ORDER BY date ASC", ARRAY_A );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="attendance-' . date( 'Y-m-d' ) . '.csv"' );

    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, [ 'date', 'visitors' ] );
    foreach ( $rows as $row ) {
        fputcsv( $out, [ $row['date'], $row['visitors'] ] );
    }
    fclose( $out );
    exit;
}
add_action( 'admin_post_genesis_attendance_export', 'genesis_attendance_export_csv' );

// --- CSV Import ---

function genesis_attendance_import_csv() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
    check_admin_referer( 'genesis_attendance_import' );

    $redirect = add_query_arg( 'page', 'genesis-attendance', admin_url( 'admin.php' ) );

    if ( empty( $_FILES['attendance_csv']['tmp_name'] ) ) {
        wp_redirect( add_query_arg( 'import', 'nofile', $redirect ) );
        exit;
    }

    global $wpdb;
    $table    = $wpdb->prefix . 'attendance_log';
    $handle   = fopen( $_FILES['attendance_csv']['tmp_name'], 'r' );
    $imported = 0;
    $skipped  = 0;
    $header   = true;

    while ( ( $row = fgetcsv( $handle ) ) !== false ) {
        if ( $header ) { $header = false; continue; } // skip header row
        if ( count( $row ) < 2 ) { $skipped++; continue; }

        $date = sanitize_text_field( trim( $row[0] ) );

        // Accept DD/MM/YYYY and convert to YYYY-MM-DD
        if ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date, $m ) ) {
            $date = sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
        }

        $visitors = intval( $row[1] );

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { $skipped++; continue; }

        $result = $wpdb->replace( $table, [
            'date'     => $date,
            'visitors' => $visitors,
        ], [ '%s', '%d' ] );

        $result !== false ? $imported++ : $skipped++;
    }
    fclose( $handle );

    wp_redirect( add_query_arg( [ 'import' => 'ok', 'imported' => $imported, 'skipped' => $skipped ], $redirect ) );
    exit;
}
add_action( 'admin_post_genesis_attendance_import', 'genesis_attendance_import_csv' );

// Helper: compute stats for a given set of rows (objects with ->date, ->visitors)
function genesis_attendance_stats( array $rows ) {
    $count = count( $rows );
    if ( $count === 0 ) return null;

    $values = array_map( fn( $r ) => intval( $r->visitors ), $rows );
    $total  = array_sum( $values );
    $mean   = $total / $count;

    $sorted = $values;
    sort( $sorted );
    $mid    = intdiv( $count, 2 );
    $median = ( $count % 2 === 0 )
        ? ( $sorted[ $mid - 1 ] + $sorted[ $mid ] ) / 2
        : $sorted[ $mid ];

    $missing = max( 0, 52 - $count );
    $pct     = round( ( $count / 52 ) * 100, 1 );

    $by_visitors = $rows;
    usort( $by_visitors, fn( $a, $b ) => intval( $b->visitors ) - intval( $a->visitors ) );
    $best  = $by_visitors[0];
    $worst = $by_visitors[ $count - 1 ];

    return compact( 'count', 'total', 'mean', 'median', 'missing', 'pct', 'best', 'worst' );
}

function genesis_attendance_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'attendance_log';

    // Handle delete
    if ( isset( $_GET['delete_date'], $_GET['_wpnonce'] )
        && wp_verify_nonce( $_GET['_wpnonce'], 'genesis_delete_' . $_GET['delete_date'] ) ) {

        $wpdb->delete( $table, [ 'date' => sanitize_text_field( $_GET['delete_date'] ) ], [ '%s' ] );
        echo '<div class="notice notice-success"><p>Entry deleted.</p></div>';
    }

    $years         = $wpdb->get_col( "SELECT DISTINCT YEAR(date) FROM $table ORDER BY 1 DESC" );
    $selected_year = isset( $_GET['year'] ) ? intval( $_GET['year'] ) : ( $years[0] ?? null );

    ?>
    <div class="wrap">
        <h1>Attendance Log</h1>

        <?php
    // Import result notices
    if ( isset( $_GET['import'] ) ) {
        if ( $_GET['import'] === 'ok' ) {
            $imp = intval( $_GET['imported'] ?? 0 );
            $skp = intval( $_GET['skipped'] ?? 0 );
            echo '<div class="notice notice-success"><p>Import complete: ' . $imp . ' rows imported, ' . $skp . ' skipped.</p></div>';
        } elseif ( $_GET['import'] === 'nofile' ) {
            echo '<div class="notice notice-error"><p>No file selected.</p></div>';
        }
    }
    ?>

    <?php if ( empty( $years ) ) : ?>
            <p>No entries yet.</p>
        <?php else : ?>

        <form method="get" style="margin-bottom:20px;">
            <input type="hidden" name="page" value="genesis-attendance">
            <label><strong>Year:</strong>
                <select name="year" onchange="this.form.submit()">
                    <?php foreach ( $years as $y ) : ?>
                        <option value="<?php echo intval( $y ); ?>" <?php selected( $y, $selected_year ); ?>>
                            <?php echo intval( $y ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>

        <?php
        $rows      = $wpdb->get_results( $wpdb->prepare(
            "SELECT date, visitors FROM $table WHERE YEAR(date) = %d ORDER BY date DESC",
            $selected_year
        ) );
        $prev_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT date, visitors FROM $table WHERE YEAR(date) = %d ORDER BY date DESC",
            $selected_year - 1
        ) );

        $s  = genesis_attendance_stats( $rows );
        $sp = genesis_attendance_stats( $prev_rows );

        // Renders a coloured diff vs previous year value
        $diff = function( $cur, $prev, $fmt = '%.1f', $lower_is_better = false ) {
            if ( $prev === null ) return '';
            $d     = $cur - $prev;
            if ( $d == 0 ) return '<span style="color:gray"> (=)</span>';
            $sign  = $d > 0 ? '+' : '';
            $up_good = ! $lower_is_better;
            $color = ( $d > 0 ) ? ( $up_good ? 'green' : 'red' ) : ( $up_good ? 'red' : 'green' );
            return sprintf(
                '<span style="color:%s"> (%s' . $fmt . ' vs %s)</span>',
                $color, $sign, $d, number_format( $prev, is_int( $prev ) ? 0 : 1 )
            );
        };

        if ( $s ) : ?>
        <h2>Statistics &mdash; <?php echo intval( $selected_year ); ?></h2>
        <table class="widefat striped" style="max-width:560px;">
            <tbody>
                <tr>
                    <th>Boardgame evenings (datapoints)</th>
                    <td><?php echo $s['count']; echo $diff( $s['count'], $sp['count'] ?? null, '%d' ); ?></td>
                </tr>
                <tr>
                    <th>Total visitors</th>
                    <td><?php echo $s['total']; echo $diff( $s['total'], $sp['total'] ?? null, '%d' ); ?></td>
                </tr>
                <tr>
                    <th>Mean visitors per evening</th>
                    <td><?php echo number_format( $s['mean'], 1 ); echo $diff( $s['mean'], $sp['mean'] ?? null, '%.1f' ); ?></td>
                </tr>
                <tr>
                    <th>Median visitor count</th>
                    <td><?php echo number_format( $s['median'], 1 ); echo $diff( $s['median'], $sp['median'] ?? null, '%.1f' ); ?></td>
                </tr>
                <tr>
                    <th>Missing evenings (of 52 weeks)</th>
                    <td><?php echo $s['missing']; echo $diff( $s['missing'], $sp['missing'] ?? null, '%d', true ); ?></td>
                </tr>
                <tr>
                    <th>% of weeks fulfilled</th>
                    <td><?php echo $s['pct']; ?>%<?php echo $diff( $s['pct'], $sp['pct'] ?? null, '%.1f%%' ); ?></td>
                </tr>
                <tr>
                    <th>Best evening</th>
                    <td>
                        <?php echo esc_html( $s['best']->date ) . ' &mdash; ' . intval( $s['best']->visitors ) . ' visitors'; ?>
                        <?php echo $diff( intval( $s['best']->visitors ), isset( $sp['best'] ) ? intval( $sp['best']->visitors ) : null, '%d' ); ?>
                    </td>
                </tr>
                <tr>
                    <th>Worst evening</th>
                    <td>
                        <?php echo esc_html( $s['worst']->date ) . ' &mdash; ' . intval( $s['worst']->visitors ) . ' visitors'; ?>
                        <?php echo $diff( intval( $s['worst']->visitors ), isset( $sp['worst'] ) ? intval( $sp['worst']->visitors ) : null, '%d' ); ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2 style="margin-top:30px;">All entries &mdash; <?php echo intval( $selected_year ); ?></h2>
        <table class="widefat striped" style="max-width:420px;">
            <thead>
                <tr><th>Date</th><th>Visitors</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $row ) :
                $delete_url = wp_nonce_url(
                    add_query_arg( [
                        'page'        => 'genesis-attendance',
                        'year'        => $selected_year,
                        'delete_date' => $row->date,
                    ], admin_url( 'admin.php' ) ),
                    'genesis_delete_' . $row->date
                ); ?>
                <tr>
                    <td><?php echo esc_html( $row->date ); ?></td>
                    <td><?php echo intval( $row->visitors ); ?></td>
                    <td><a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('Delete this entry?')">Delete</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php else : ?>
            <p>No entries for <?php echo intval( $selected_year ); ?>.</p>
        <?php endif; ?>
        <?php endif; ?>

        <hr style="margin:30px 0;">
        <h2>Export / Import</h2>

        <p>
            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=genesis_attendance_export' ), 'genesis_attendance_export' ) ); ?>">
                Download all data as CSV
            </a>
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field( 'genesis_attendance_import' ); ?>
            <input type="hidden" name="action" value="genesis_attendance_import">
            <label><strong>Import CSV</strong> (columns: <code>date</code>, <code>visitors</code>; existing dates are overwritten)<br><br>
                <input type="file" name="attendance_csv" accept=".csv,text/csv">
            </label>
            <br><br>
            <button type="submit" class="button button-primary">Import</button>
        </form>

    </div>
    <?php
}
