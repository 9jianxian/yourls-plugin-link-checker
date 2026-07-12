<?php
/*
Plugin Name: Dead Link Checker
Plugin URI: https://github.com/9jianxian/yourls-plugin-link-checker
Description: Automatically detect invalid long URLs mapped to short links, display broken links in a visual backend table with one-click deletion support
Version: 1.0
Author: ai
Author URI: https://github.com/9jianxian
*/

// Block direct file access
if ( !defined( 'YOURLS_ABSPATH' ) ) {
    die();
}

// Register admin page on plugin load
yourls_add_action( 'plugins_loaded', 'link_checker_register_admin_page' );
function link_checker_register_admin_page() {
    yourls_register_plugin_page( 'link_checker', yourls__( 'Dead Link Detection' ), 'link_checker_admin_display' );
}

// Return default plugin configuration
function link_checker_get_default_config() {
    return array(
        'timeout'      => 10,
        'ssl_verify'   => 'off',
        'batch_size'   => 50,
    );
}

// Load saved configuration and merge with defaults
function link_checker_get_config() {
    $saved = yourls_get_option( 'link_checker_config' );
    $def   = link_checker_get_default_config();
    if ( !is_array( $saved ) ) {
        return $def;
    }
    return array_merge( $def, $saved );
}

// Persist configuration to YOURLS database
function link_checker_save_config( $data ) {
    yourls_update_option( 'link_checker_config', $data );
}

/**
 * Probe single URL to check if it is broken
 * @param string $url Target URL
 * @param int $timeout Request timeout in seconds
 * @param bool $ssl_verify Enable SSL certificate validation
 * @return array Scan result data
 */
function link_checker_probe_url( $url, $timeout = 10, $ssl_verify = false ) {
    if ( empty( $url ) || !filter_var( $url, FILTER_VALIDATE_URL ) ) {
        return array(
            'valid'   => false,
            'status'  => 0,
            'message' => yourls__( 'Invalid URL' ),
        );
    }

    $ssl_opt = array(
        'verify_peer'      => $ssl_verify,
        'verify_peer_name' => $ssl_verify,
    );
    if ( !$ssl_verify ) {
        $ssl_opt['allow_self_signed'] = true;
    }

    $ctx = stream_context_create( array(
        'http' => array(
            'timeout'       => $timeout,
            'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'method'        => 'HEAD',
            'max_redirects' => 5,
        ),
        'ssl' => $ssl_opt,
    ) );

    $headers = @get_headers( $url, true, $ctx );

    if ( !$headers ) {
        return array(
            'valid'   => false,
            'status'  => 0,
            'message' => yourls__( 'Connection failed' ),
        );
    }

    $status_line = is_array( $headers[0] ) ? $headers[0] : $headers[0];
    preg_match( '/HTTP\/\d\.\d\s+(\d{3})/', $status_line, $matches );
    $status_code = isset( $matches[1] ) ? intval( $matches[1] ) : 0;
    $is_valid = ( $status_code >= 200 && $status_code < 400 );

    return array(
        'valid'   => $is_valid,
        'status'  => $status_code,
        'message' => $status_line,
    );
}

/**
 * Batch scan multiple URLs
 * @param array $links List of short link database rows
 * @param int $timeout Scan timeout
 * @param bool $ssl_verify SSL validation toggle
 * @return array Scan results for all links
 */
function link_checker_batch_probe( $links, $timeout = 10, $ssl_verify = false ) {
    $results = array();
    foreach ( $links as $link ) {
        $res = link_checker_probe_url( $link['url'], $timeout, $ssl_verify );
        $results[] = array(
            'keyword'   => $link['keyword'],
            'url'       => $link['url'],
            'title'     => $link['title'],
            'clicks'    => $link['clicks'],
            'valid'     => $res['valid'],
            'status'    => $res['status'],
            'message'   => $res['message'],
        );
    }
    return $results;
}

/**
 * Fetch all short link records from database
 * @return array All URL entries sorted by creation time descending
 */
function link_checker_get_all_links() {
    global $ydb;
    $table = YOURLS_DB_TABLE_URL;
    $sql   = "SELECT `keyword`, `url`, `title`, `clicks` FROM `$table` ORDER BY `timestamp` DESC";
    return $ydb->fetchAll( $sql );
}

/**
 * Delete single short link by keyword
 * @param string $keyword Short link slug
 * @return bool Delete success status
 */
function link_checker_delete_link( $keyword ) {
    return yourls_delete_link_by_keyword( $keyword );
}

// AJAX Handler: Batch URL scanning
yourls_add_action( 'yourls_ajax_link_checker_probe', 'link_checker_ajax_probe' );
function link_checker_ajax_probe() {
    yourls_verify_nonce( 'link_checker_nonce' );

    $cfg        = link_checker_get_config();
    $timeout    = intval( $cfg['timeout'] );
    $ssl_verify = $cfg['ssl_verify'] === 'on';

    $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
    $limit  = intval( $cfg['batch_size'] );

    $all_links = link_checker_get_all_links();
    $total     = count( $all_links );
    $batch     = array_slice( $all_links, $offset, $limit );

    if ( empty( $batch ) ) {
        echo json_encode( array( 'done' => true, 'total' => $total ) );
        die();
    }

    $results = link_checker_batch_probe( $batch, $timeout, $ssl_verify );
    $invalid = array_filter( $results, function( $r ) { return !$r['valid']; } );

    echo json_encode( array(
        'done'      => ( $offset + $limit ) >= $total,
        'total'     => $total,
        'processed' => $offset + count( $batch ),
        'results'   => array_values( $invalid ),
    ) );
    die();
}

// AJAX Handler: Delete single broken link
yourls_add_action( 'yourls_ajax_link_checker_delete', 'link_checker_ajax_delete' );
function link_checker_ajax_delete() {
    yourls_verify_nonce( 'link_checker_nonce' );

    $keyword = isset( $_POST['keyword'] ) ? yourls_sanitize_string( $_POST['keyword'] ) : '';
    if ( empty( $keyword ) ) {
        echo json_encode( array( 'success' => false, 'message' => yourls__( 'Invalid keyword' ) ) );
        die();
    }

    $success = link_checker_delete_link( $keyword );
    echo json_encode( array(
        'success' => $success,
        'message' => $success ? yourls__( 'Deleted successfully' ) : yourls__( 'Deletion failed' ),
    ) );
    die();
}

// AJAX Handler: Bulk delete multiple selected links
yourls_add_action( 'yourls_ajax_link_checker_delete_batch', 'link_checker_ajax_delete_batch' );
function link_checker_ajax_delete_batch() {
    yourls_verify_nonce( 'link_checker_nonce' );

    // Decode JSON keyword list sent from frontend
    $keywords_raw = isset( $_POST['keywords'] ) ? $_POST['keywords'] : '';
    $keywords = json_decode( $keywords_raw, true );

    if ( !is_array( $keywords ) || empty( $keywords ) ) {
        echo json_encode( array( 'success' => false, 'message' => yourls__( 'No links selected' ) ) );
        die();
    }

    $deleted = 0;
    $failed  = 0;
    foreach ( $keywords as $keyword ) {
        $keyword = yourls_sanitize_string( $keyword );
        if ( link_checker_delete_link( $keyword ) ) {
            $deleted++;
        } else {
            $failed++;
        }
    }

    echo json_encode( array(
        'success' => true,
        'deleted' => $deleted,
        'failed'  => $failed,
        'message' => yourls__( 'Deleted ' ) . $deleted . yourls__( ' entries, ' ) . $failed . yourls__( ' failed' ),
    ) );
    die();
}

// Render plugin admin page HTML & JS
function link_checker_admin_display() {
    $cfg = link_checker_get_config();
    $msg = '';

    if ( isset( $_POST['save_config'] ) && yourls_verify_nonce( 'link_checker_nonce' ) ) {
        $new_cfg = array(
            'timeout'    => isset( $_POST['timeout'] ) ? intval( $_POST['timeout'] ) : 10,
            'ssl_verify' => isset( $_POST['ssl_verify'] ) ? 'on' : 'off',
            'batch_size' => isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 50,
        );
        link_checker_save_config( $new_cfg );
        $cfg = $new_cfg;
        $msg = '<div class="notice notice-success" style="color:green;padding:8px 12px;border-left:4px solid #46b450;background:#f0fff4;"><strong>' . yourls_esc_html( yourls__( 'Configuration saved' ) ) . '</strong></div>';
    }

    $nonce = yourls_create_nonce( 'link_checker_nonce' );
    ?>
    <style>
        .link-checker-wrap { max-width: 1200px; }
        .link-checker-wrap .form-table th { width: 180px; }
        .link-checker-wrap .probe-btn {
            background: #2271b1; color: #fff; border: none;
            padding: 8px 20px; font-size: 14px; cursor: pointer;
            border-radius: 3px;
        }
        .link-checker-wrap .probe-btn:hover { background: #135e96; }
        .link-checker-wrap .probe-btn:disabled { background: #ccc; cursor: not-allowed; }
        .link-checker-wrap .progress-bar {
            width: 100%; height: 24px; background: #f0f0f0;
            border-radius: 3px; overflow: hidden; margin: 15px 0;
            display: none;
        }
        .link-checker-wrap .progress-bar-inner {
            height: 100%; background: #2271b1; width: 0%;
            transition: width 0.3s; text-align: center;
            color: #fff; line-height: 24px; font-size: 12px;
        }
        .link-checker-wrap .results-table-wrap {
            width: 100%; overflow-x: auto; margin-top: 15px;
            border: 1px solid #ddd; border-radius: 3px;
        }
        .link-checker-wrap .results-table {
            width: 100%; border-collapse: collapse; min-width: 900px;
        }
        .link-checker-wrap .results-table th,
        .link-checker-wrap .results-table td {
            padding: 10px 12px; text-align: left; border-bottom: 1px solid #ddd;
            vertical-align: middle;
        }
        .link-checker-wrap .results-table th {
            background: #f5f5f5; font-weight: 600; white-space: nowrap;
        }
        .link-checker-wrap .results-table tr:hover { background: #f9f9f9; }
        .link-checker-wrap .results-table td:first-child,
        .link-checker-wrap .results-table th:first-child {
            width: 40px; text-align: center;
        }
        .link-checker-wrap .results-table .col-keyword { width: 120px; }
        .link-checker-wrap .results-table .col-url {
            max-width: 300px; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap;
        }
        .link-checker-wrap .results-table .col-title {
            max-width: 200px; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap;
        }
        .link-checker-wrap .results-table .col-clicks { width: 60px; text-align: center; }
        .link-checker-wrap .results-table .col-status { width: 80px; text-align: center; }
        .link-checker-wrap .results-table .col-action { width: 80px; text-align: center; }
        .link-checker-wrap .status-badge {
            display: inline-block; padding: 2px 8px; border-radius: 3px;
            font-size: 12px; font-weight: 600;
        }
        .link-checker-wrap .status-fail {
            background: #ffebee; color: #c62828;
        }
        .link-checker-wrap .delete-btn {
            background: #c62828; color: #fff; border: none;
            padding: 4px 12px; font-size: 12px; cursor: pointer;
            border-radius: 3px;
        }
        .link-checker-wrap .delete-btn:hover { background: #b71c1c; }
        .link-checker-wrap .delete-btn:disabled { background: #ccc; cursor: not-allowed; }
        .link-checker-wrap .batch-action-bar {
            display: none; align-items: center; gap: 10px;
            padding: 10px 12px; background: #fff3e0;
            border: 1px solid #f57c00; border-radius: 3px;
            margin-bottom: 10px;
        }
        .link-checker-wrap .batch-action-bar.visible { display: flex; }
        .link-checker-wrap .batch-delete-btn {
            background: #c62828; color: #fff; border: none;
            padding: 6px 16px; font-size: 13px; cursor: pointer;
            border-radius: 3px;
        }
        .link-checker-wrap .batch-delete-btn:hover { background: #b71c1c; }
        .link-checker-wrap .batch-delete-btn:disabled { background: #ccc; cursor: not-allowed; }
        .link-checker-wrap .select-all-btn {
            background: #666; color: #fff; border: none;
            padding: 6px 16px; font-size: 13px; cursor: pointer;
            border-radius: 3px;
        }
        .link-checker-wrap .select-all-btn:hover { background: #555; }
        .link-checker-wrap .empty-tip {
            text-align: center; padding: 40px; color: #666;
        }
        .link-checker-wrap .stats-bar {
            display: flex; gap: 20px; margin: 15px 0;
            padding: 12px 16px; background: #f5f5f5; border-radius: 3px;
        }
        .link-checker-wrap .stats-bar span { font-weight: 600; }
    </style>

    <div class="link-checker-wrap">
        <h2><?php echo yourls_esc_html( yourls__( 'Dead Link Checker' ) ); ?></h2>
        <?php echo $msg; ?>

        <!-- Configuration Form -->
        <form method="post" style="margin-bottom: 20px;">
            <?php yourls_nonce_field( 'link_checker_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><?php echo yourls_esc_html( yourls__( 'Scan Timeout (Seconds)' ) ); ?></th>
                    <td>
                        <input type="number" name="timeout" value="<?php echo intval( $cfg['timeout'] ); ?>" min="1" max="60" style="width:80px;">
                        <p class="description"><?php echo yourls_esc_html( yourls__( 'Max waiting time for single URL probe' ) ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php echo yourls_esc_html( yourls__( 'Batch Size' ) ); ?></th>
                    <td>
                        <input type="number" name="batch_size" value="<?php echo intval( $cfg['batch_size'] ); ?>" min="10" max="200" style="width:80px;">
                        <p class="description"><?php echo yourls_esc_html( yourls__( 'Number of URLs scanned per AJAX request; large values may trigger PHP timeout' ) ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php echo yourls_esc_html( yourls__( 'SSL Certificate Validation' ) ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="ssl_verify" <?php echo $cfg['ssl_verify'] === 'on' ? 'checked' : ''; ?>>
                            <?php echo yourls_esc_html( yourls__( 'Enable SSL certificate verification' ) ); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <p>
                <button type="submit" name="save_config" class="button button-primary">
                    <?php echo yourls_esc_html( yourls__( 'Save Configuration' ) ); ?>
                </button>
            </p>
        </form>

        <hr>

        <!-- Scan Control Area -->
        <h3><?php echo yourls_esc_html( yourls__( 'Start Scan' ) ); ?></h3>
        <p>
            <button type="button" id="start-probe" class="probe-btn">
                <?php echo yourls_esc_html( yourls__( 'Click to scan all links' ) ); ?>
            </button>
        </p>

        <div class="progress-bar" id="progress-bar">
            <div class="progress-bar-inner" id="progress-bar-inner">0%</div>
        </div>

        <div class="stats-bar" id="stats-bar" style="display:none;">
            <span><?php echo yourls_esc_html( yourls__( 'Total Links: ' ) ); ?><span id="stat-total">0</span></span>
            <span style="color:#c62828;"><?php echo yourls_esc_html( yourls__( 'Broken Links: ' ) ); ?><span id="stat-invalid">0</span></span>
        </div>

        <!-- Bulk Action Toolbar -->
        <div class="batch-action-bar" id="batch-action-bar">
            <input type="checkbox" id="select-all-top" style="margin-right:5px;">
            <label for="select-all-top" style="margin-right:15px;cursor:pointer;">
                <?php echo yourls_esc_html( yourls__( 'Select All' ) ); ?>
            </label>
            <button type="button" class="batch-delete-btn" id="batch-delete-btn">
                <?php echo yourls_esc_html( yourls__( 'Bulk Delete Selected' ) ); ?>
            </button>
            <span id="selected-count" style="margin-left:10px;color:#666;"></span>
        </div>

        <!-- Scan Result Table Container -->
        <div id="results-area">
            <p class="empty-tip"><?php echo yourls_esc_html( yourls__( 'Click the button above to start scanning; broken links will display here' ) ); ?></p>
        </div>
    </div>

    <script>
    (function() {
        var nonce = '<?php echo yourls_esc_js( $nonce ); ?>';
        var ajaxUrl = '<?php echo yourls_admin_url( 'admin-ajax.php' ); ?>';
        var batchSize = <?php echo intval( $cfg['batch_size'] ); ?>;
        var invalidLinks = [];

        document.getElementById('start-probe').addEventListener('click', function() {
            var btn = this;
            btn.disabled = true;
            btn.textContent = '<?php echo yourls_esc_js( yourls__( 'Scanning...' ) ); ?>';
            document.getElementById('progress-bar').style.display = 'block';
            document.getElementById('stats-bar').style.display = 'flex';
            document.getElementById('batch-action-bar').classList.remove('visible');
            invalidLinks = [];
            renderResults();
            doProbe(0);
        });

        function doProbe(offset) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4) return;
                if (xhr.status !== 200) {
                    alert('<?php echo yourls_esc_js( yourls__( 'Request failed, please refresh and retry' ) ); ?>');
                    resetBtn();
                    return;
                }
                var data = JSON.parse(xhr.responseText);
                if (data.results && data.results.length > 0) {
                    invalidLinks = invalidLinks.concat(data.results);
                }
                updateProgress(data.processed, data.total);
                document.getElementById('stat-total').textContent = data.total;
                document.getElementById('stat-invalid').textContent = invalidLinks.length;
                renderResults();

                if (!data.done) {
                    doProbe(offset + batchSize);
                } else {
                    resetBtn();
                    if (invalidLinks.length > 0) {
                        document.getElementById('batch-action-bar').classList.add('visible');
                    }
                    alert('<?php echo yourls_esc_js( yourls__( 'Scan completed! Found ' ) ); ?>' + invalidLinks.length + '<?php echo yourls_esc_js( yourls__( ' broken links' ) ); ?>');
                }
            };
            xhr.send('action=link_checker_probe&offset=' + offset + '&nonce=' + encodeURIComponent(nonce));
        }

        function updateProgress(processed, total) {
            var pct = total > 0 ? Math.round((processed / total) * 100) : 0;
            var bar = document.getElementById('progress-bar-inner');
            bar.style.width = pct + '%';
            bar.textContent = pct + '% (' + processed + '/' + total + ')';
        }

        function resetBtn() {
            var btn = document.getElementById('start-probe');
            btn.disabled = false;
            btn.textContent = '<?php echo yourls_esc_js( yourls__( 'Click to scan all links' ) ); ?>';
        }

        function updateSelectedCount() {
            var checked = document.querySelectorAll('.link-checker-wrap .row-checkbox:checked');
            var countEl = document.getElementById('selected-count');
            if (checked.length > 0) {
                countEl.textContent = '<?php echo yourls_esc_js( yourls__( 'Selected ' ) ); ?>' + checked.length + '<?php echo yourls_esc_js( yourls__( ' entries' ) ); ?>';
            } else {
                countEl.textContent = '';
            }
        }

        function renderResults() {
            var area = document.getElementById('results-area');
            if (invalidLinks.length === 0) {
                area.innerHTML = '<p class="empty-tip"><?php echo yourls_esc_js( yourls__( 'No broken links detected' ) ); ?></p>';
                document.getElementById('batch-action-bar').classList.remove('visible');
                return;
            }
            var html = '<div class="results-table-wrap"><table class="results-table"><thead><tr>' +
                '<th><input type="checkbox" id="select-all-header"></th>' +
                '<th class="col-keyword"><?php echo yourls_esc_js( yourls__( 'Short Link' ) ); ?></th>' +
                '<th><?php echo yourls_esc_js( yourls__( 'Target URL' ) ); ?></th>' +
                '<th class="col-title"><?php echo yourls_esc_js( yourls__( 'Page Title' ) ); ?></th>' +
                '<th class="col-clicks"><?php echo yourls_esc_js( yourls__( 'Clicks' ) ); ?></th>' +
                '<th class="col-status"><?php echo yourls_esc_js( yourls__( 'Status Code' ) ); ?></th>' +
                '<th class="col-action"><?php echo yourls_esc_js( yourls__( 'Action' ) ); ?></th>' +
                '</tr></thead><tbody>';
            invalidLinks.forEach(function(link) {
                html += '<tr data-keyword="' + escapeHtml(link.keyword) + '">' +
                    '<td><input type="checkbox" class="row-checkbox" value="' + escapeHtml(link.keyword) + '"></td>' +
                    '<td class="col-keyword"><a href="' + escapeHtml(link.url) + '" target="_blank">' + escapeHtml(link.keyword) + '</a></td>' +
                    '<td class="col-url" title="' + escapeHtml(link.url) + '">' + escapeHtml(link.url) + '</td>' +
                    '<td class="col-title" title="' + escapeHtml(link.title || '-') + '">' + escapeHtml(link.title || '-') + '</td>' +
                    '<td class="col-clicks">' + link.clicks + '</td>' +
                    '<td class="col-status"><span class="status-badge status-fail">' + link.status + '</span></td>' +
                    '<td class="col-action"><button class="delete-btn" data-keyword="' + escapeHtml(link.keyword) + '"><?php echo yourls_esc_js( yourls__( 'Delete' ) ); ?></button></td>' +
                    '</tr>';
            });
            html += '</tbody></table></div>';
            area.innerHTML = html;

            // Bind single row delete buttons
            area.querySelectorAll('.delete-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    deleteLink(this, this.getAttribute('data-keyword'));
                });
            });

            // Bind row checkbox selection counter
            area.querySelectorAll('.row-checkbox').forEach(function(cb) {
                cb.addEventListener('change', updateSelectedCount);
            });

            // Header select-all checkbox
            var headerCb = document.getElementById('select-all-header');
            if (headerCb) {
                headerCb.addEventListener('change', function() {
                    var checked = this.checked;
                    area.querySelectorAll('.row-checkbox').forEach(function(cb) {
                        cb.checked = checked;
                    });
                    document.getElementById('select-all-top').checked = checked;
                    updateSelectedCount();
                });
            }
        }

        // Top global select-all checkbox
        document.getElementById('select-all-top').addEventListener('change', function() {
            var checked = this.checked;
            var area = document.getElementById('results-area');
            area.querySelectorAll('.row-checkbox').forEach(function(cb) {
                cb.checked = checked;
            });
            var headerCb = document.getElementById('select-all-header');
            if (headerCb) headerCb.checked = checked;
            updateSelectedCount();
        });

        // Bulk delete button handler
        document.getElementById('batch-delete-btn').addEventListener('click', function() {
            var checked = document.querySelectorAll('.link-checker-wrap .row-checkbox:checked');
            if (checked.length === 0) {
                alert('<?php echo yourls_esc_js( yourls__( 'Please select links to delete first' ) ); ?>');
                return;
            }
            if (!confirm('<?php echo yourls_esc_js( yourls__( 'Confirm deletion of selected ' ) ); ?>' + checked.length + '<?php echo yourls_esc_js( yourls__( ' links? This action cannot be undone.' ) ); ?>')) {
                return;
            }

            var keywords = [];
            checked.forEach(function(cb) {
                keywords.push(cb.value);
            });

            var btn = this;
            btn.disabled = true;
            btn.textContent = '<?php echo yourls_esc_js( yourls__( 'Deleting...' ) ); ?>';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4) return;
                var data = JSON.parse(xhr.responseText);
                if (data.success) {
                    keywords.forEach(function(kw) {
                        var row = document.querySelector('tr[data-keyword="' + kw + '"]');
                        if (row) row.remove();
                        invalidLinks = invalidLinks.filter(function(l) { return l.keyword !== kw; });
                    });
                    document.getElementById('stat-invalid').textContent = invalidLinks.length;
                    if (invalidLinks.length === 0) {
                        renderResults();
                    }
                    updateSelectedCount();
                }
                alert(data.message);
                btn.disabled = false;
                btn.textContent = '<?php echo yourls_esc_js( yourls__( 'Bulk Delete Selected' ) ); ?>';
            };
            xhr.send('action=link_checker_delete_batch&nonce=' + encodeURIComponent(nonce) + '&keywords=' + encodeURIComponent(JSON.stringify(keywords)));
        });

        // Single link deletion function
        function deleteLink(btn, keyword) {
            if (!confirm('<?php echo yourls_esc_js( yourls__( 'Confirm deletion of short link ' ) ); ?>' + keyword + '<?php echo yourls_esc_js( yourls__( '? This action cannot be undone.' ) ); ?>')) {
                return;
            }
            btn.disabled = true;
            btn.textContent = '<?php echo yourls_esc_js( yourls__( 'Deleting...' ) ); ?>';
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4) return;
                var data = JSON.parse(xhr.responseText);
                if (data.success) {
                    var row = btn.closest('tr');
                    if (row) row.remove();
                    invalidLinks = invalidLinks.filter(function(l) { return l.keyword !== keyword; });
                    document.getElementById('stat-invalid').textContent = invalidLinks.length;
                    if (invalidLinks.length === 0) {
                        renderResults();
                    }
                    updateSelectedCount();
                } else {
                    alert(data.message || '<?php echo yourls_esc_js( yourls__( 'Deletion failed' ) ); ?>');
                    btn.disabled = false;
                    btn.textContent = '<?php echo yourls_esc_js( yourls__( 'Delete' ) ); ?>';
                }
            };
            xhr.send('action=link_checker_delete&keyword=' + encodeURIComponent(keyword) + '&nonce=' + encodeURIComponent(nonce));
        }

        // HTML escaping utility
        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    })();
    </script>
    <?php
}
