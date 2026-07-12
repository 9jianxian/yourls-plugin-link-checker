<?php
/*
Plugin Name: 失效链接检测器
Plugin URI: https://github.com/9jianxian/yourls-plugin-link-checker
Description: 自动探测短链对应的长链接是否失效，后台可视化列表展示失效链接，支持一键删除
Version: 1.0
Author: ai
Author URI: https://github.com/9jianxian
*/

// No direct call
if ( !defined( 'YOURLS_ABSPATH' ) ) {
    die();
}

// 注册后台页面
yourls_add_action( 'plugins_loaded', 'link_checker_register_admin_page' );
function link_checker_register_admin_page() {
    yourls_register_plugin_page( 'link_checker', yourls__( '失效链接检测' ), 'link_checker_admin_display' );
}

// 默认配置
function link_checker_get_default_config() {
    return array(
        'timeout'      => 10,
        'ssl_verify'   => 'off',
        'batch_size'   => 50,
    );
}

// 读取配置
function link_checker_get_config() {
    $saved = yourls_get_option( 'link_checker_config' );
    $def   = link_checker_get_default_config();
    if ( !is_array( $saved ) ) {
        return $def;
    }
    return array_merge( $def, $saved );
}

// 保存配置
function link_checker_save_config( $data ) {
    yourls_update_option( 'link_checker_config', $data );
}

/**
 * 检测单个链接是否失效
 */
function link_checker_probe_url( $url, $timeout = 10, $ssl_verify = false ) {
    if ( empty( $url ) || !filter_var( $url, FILTER_VALIDATE_URL ) ) {
        return array(
            'valid'   => false,
            'status'  => 0,
            'message' => yourls__( '无效URL' ),
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
            'message' => yourls__( '无法连接' ),
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
 * 批量检测链接
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
 * 获取所有短链
 */
function link_checker_get_all_links() {
    global $ydb;
    $table = YOURLS_DB_TABLE_URL;
    $sql   = "SELECT `keyword`, `url`, `title`, `clicks` FROM `$table` ORDER BY `timestamp` DESC";
    return $ydb->fetchAll( $sql );
}

/**
 * 删除单个链接
 */
function link_checker_delete_link( $keyword ) {
    return yourls_delete_link_by_keyword( $keyword );
}

// AJAX 处理：批量检测
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

// AJAX 处理：删除单个链接
yourls_add_action( 'yourls_ajax_link_checker_delete', 'link_checker_ajax_delete' );
function link_checker_ajax_delete() {
    yourls_verify_nonce( 'link_checker_nonce' );

    $keyword = isset( $_POST['keyword'] ) ? yourls_sanitize_string( $_POST['keyword'] ) : '';
    if ( empty( $keyword ) ) {
        echo json_encode( array( 'success' => false, 'message' => yourls__( '无效关键词' ) ) );
        die();
    }

    $success = link_checker_delete_link( $keyword );
    echo json_encode( array(
        'success' => $success,
        'message' => $success ? yourls__( '删除成功' ) : yourls__( '删除失败' ),
    ) );
    die();
}

// AJAX 处理：批量删除
yourls_add_action( 'yourls_ajax_link_checker_delete_batch', 'link_checker_ajax_delete_batch' );
function link_checker_ajax_delete_batch() {
    yourls_verify_nonce( 'link_checker_nonce' );

    // 修复：前端传递 JSON 字符串，后端需要解码
    $keywords_raw = isset( $_POST['keywords'] ) ? $_POST['keywords'] : '';
    $keywords = json_decode( $keywords_raw, true );

    if ( !is_array( $keywords ) || empty( $keywords ) ) {
        echo json_encode( array( 'success' => false, 'message' => yourls__( '未选择任何链接' ) ) );
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
        'message' => yourls__( '已删除 ' ) . $deleted . yourls__( ' 条，失败 ' ) . $failed . yourls__( ' 条' ),
    ) );
    die();
}

// 后台渲染页面
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
        $msg = '<div class="notice notice-success" style="color:green;padding:8px 12px;border-left:4px solid #46b450;background:#f0fff4;"><strong>' . yourls_esc_html( yourls__( '配置已保存' ) ) . '</strong></div>';
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
        <h2><?php echo yourls_esc_html( yourls__( '失效链接检测器' ) ); ?></h2>
        <?php echo $msg; ?>

        <!-- 配置表单 -->
        <form method="post" style="margin-bottom: 20px;">
            <?php yourls_nonce_field( 'link_checker_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><?php echo yourls_esc_html( yourls__( '检测超时（秒）' ) ); ?></th>
                    <td>
                        <input type="number" name="timeout" value="<?php echo intval( $cfg['timeout'] ); ?>" min="1" max="60" style="width:80px;">
                        <p class="description"><?php echo yourls_esc_html( yourls__( '单个链接的最大等待时间' ) ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php echo yourls_esc_html( yourls__( '每批数量' ) ); ?></th>
                    <td>
                        <input type="number" name="batch_size" value="<?php echo intval( $cfg['batch_size'] ); ?>" min="10" max="200" style="width:80px;">
                        <p class="description"><?php echo yourls_esc_html( yourls__( '每次 AJAX 请求检测的链接数量，过大可能导致超时' ) ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php echo yourls_esc_html( yourls__( 'SSL 证书校验' ) ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="ssl_verify" <?php echo $cfg['ssl_verify'] === 'on' ? 'checked' : ''; ?>>
                            <?php echo yourls_esc_html( yourls__( '开启 SSL 证书验证' ) ); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <p>
                <button type="submit" name="save_config" class="button button-primary">
                    <?php echo yourls_esc_html( yourls__( '保存配置' ) ); ?>
                </button>
            </p>
        </form>

        <hr>

        <!-- 检测控制区 -->
        <h3><?php echo yourls_esc_html( yourls__( '开始检测' ) ); ?></h3>
        <p>
            <button type="button" id="start-probe" class="probe-btn">
                <?php echo yourls_esc_html( yourls__( '点击开始检测全部链接' ) ); ?>
            </button>
        </p>

        <div class="progress-bar" id="progress-bar">
            <div class="progress-bar-inner" id="progress-bar-inner">0%</div>
        </div>

        <div class="stats-bar" id="stats-bar" style="display:none;">
            <span><?php echo yourls_esc_html( yourls__( '总链接数：' ) ); ?><span id="stat-total">0</span></span>
            <span style="color:#c62828;"><?php echo yourls_esc_html( yourls__( '失效链接：' ) ); ?><span id="stat-invalid">0</span></span>
        </div>

        <!-- 批量操作栏 -->
        <div class="batch-action-bar" id="batch-action-bar">
            <input type="checkbox" id="select-all-top" style="margin-right:5px;">
            <label for="select-all-top" style="margin-right:15px;cursor:pointer;">
                <?php echo yourls_esc_html( yourls__( '全选' ) ); ?>
            </label>
            <button type="button" class="batch-delete-btn" id="batch-delete-btn">
                <?php echo yourls_esc_html( yourls__( '批量删除选中' ) ); ?>
            </button>
            <span id="selected-count" style="margin-left:10px;color:#666;"></span>
        </div>

        <!-- 结果表格 -->
        <div id="results-area">
            <p class="empty-tip"><?php echo yourls_esc_html( yourls__( '点击上方按钮开始检测，失效链接将在此列出' ) ); ?></p>
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
            btn.textContent = '<?php echo yourls_esc_js( yourls__( '检测中...' ) ); ?>';
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
                    alert('<?php echo yourls_esc_js( yourls__( '请求失败，请刷新重试' ) ); ?>');
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
                    alert('<?php echo yourls_esc_js( yourls__( '检测完成！共发现 ' ) ); ?>' + invalidLinks.length + '<?php echo yourls_esc_js( yourls__( ' 条失效链接' ) ); ?>');
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
            btn.textContent = '<?php echo yourls_esc_js( yourls__( '点击开始检测全部链接' ) ); ?>';
        }

        function updateSelectedCount() {
            var checked = document.querySelectorAll('.link-checker-wrap .row-checkbox:checked');
            var countEl = document.getElementById('selected-count');
            if (checked.length > 0) {
                countEl.textContent = '<?php echo yourls_esc_js( yourls__( '已选择 ' ) ); ?>' + checked.length + '<?php echo yourls_esc_js( yourls__( ' 条' ) ); ?>';
            } else {
                countEl.textContent = '';
            }
        }

        function renderResults() {
            var area = document.getElementById('results-area');
            if (invalidLinks.length === 0) {
                area.innerHTML = '<p class="empty-tip"><?php echo yourls_esc_js( yourls__( '暂未发现失效链接' ) ); ?></p>';
                document.getElementById('batch-action-bar').classList.remove('visible');
                return;
            }
            var html = '<div class="results-table-wrap"><table class="results-table"><thead><tr>' +
                '<th><input type="checkbox" id="select-all-header"></th>' +
                '<th class="col-keyword"><?php echo yourls_esc_js( yourls__( '短链' ) ); ?></th>' +
                '<th><?php echo yourls_esc_js( yourls__( '长链接' ) ); ?></th>' +
                '<th class="col-title"><?php echo yourls_esc_js( yourls__( '标题' ) ); ?></th>' +
                '<th class="col-clicks"><?php echo yourls_esc_js( yourls__( '点击数' ) ); ?></th>' +
                '<th class="col-status"><?php echo yourls_esc_js( yourls__( '状态码' ) ); ?></th>' +
                '<th class="col-action"><?php echo yourls_esc_js( yourls__( '操作' ) ); ?></th>' +
                '</tr></thead><tbody>';
            invalidLinks.forEach(function(link) {
                html += '<tr data-keyword="' + escapeHtml(link.keyword) + '">' +
                    '<td><input type="checkbox" class="row-checkbox" value="' + escapeHtml(link.keyword) + '"></td>' +
                    '<td class="col-keyword"><a href="' + escapeHtml(link.url) + '" target="_blank">' + escapeHtml(link.keyword) + '</a></td>' +
                    '<td class="col-url" title="' + escapeHtml(link.url) + '">' + escapeHtml(link.url) + '</td>' +
                    '<td class="col-title" title="' + escapeHtml(link.title || '-') + '">' + escapeHtml(link.title || '-') + '</td>' +
                    '<td class="col-clicks">' + link.clicks + '</td>' +
                    '<td class="col-status"><span class="status-badge status-fail">' + link.status + '</span></td>' +
                    '<td class="col-action"><button class="delete-btn" data-keyword="' + escapeHtml(link.keyword) + '"><?php echo yourls_esc_js( yourls__( '删除' ) ); ?></button></td>' +
                    '</tr>';
            });
            html += '</tbody></table></div>';
            area.innerHTML = html;

            // 绑定删除事件
            area.querySelectorAll('.delete-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    deleteLink(this, this.getAttribute('data-keyword'));
                });
            });

            // 绑定行选择事件
            area.querySelectorAll('.row-checkbox').forEach(function(cb) {
                cb.addEventListener('change', updateSelectedCount);
            });

            // 绑定表头全选
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

        // 顶部全选按钮
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

        // 批量删除按钮
        document.getElementById('batch-delete-btn').addEventListener('click', function() {
            var checked = document.querySelectorAll('.link-checker-wrap .row-checkbox:checked');
            if (checked.length === 0) {
                alert('<?php echo yourls_esc_js( yourls__( '请先选择要删除的链接' ) ); ?>');
                return;
            }
            if (!confirm('<?php echo yourls_esc_js( yourls__( '确定删除选中的 ' ) ); ?>' + checked.length + '<?php echo yourls_esc_js( yourls__( ' 条链接吗？此操作不可恢复。' ) ); ?>')) {
                return;
            }

            var keywords = [];
            checked.forEach(function(cb) {
                keywords.push(cb.value);
            });

            var btn = this;
            btn.disabled = true;
            btn.textContent = '<?php echo yourls_esc_js( yourls__( '删除中...' ) ); ?>';

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
                btn.textContent = '<?php echo yourls_esc_js( yourls__( '批量删除选中' ) ); ?>';
            };
            xhr.send('action=link_checker_delete_batch&nonce=' + encodeURIComponent(nonce) + '&keywords=' + encodeURIComponent(JSON.stringify(keywords)));
        });

        function deleteLink(btn, keyword) {
            if (!confirm('<?php echo yourls_esc_js( yourls__( '确定删除短链 ' ) ); ?>' + keyword + '<?php echo yourls_esc_js( yourls__( ' 吗？此操作不可恢复。' ) ); ?>')) {
                return;
            }
            btn.disabled = true;
            btn.textContent = '<?php echo yourls_esc_js( yourls__( '删除中...' ) ); ?>';
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
                    alert(data.message || '<?php echo yourls_esc_js( yourls__( '删除失败' ) ); ?>');
                    btn.disabled = false;
                    btn.textContent = '<?php echo yourls_esc_js( yourls__( '删除' ) ); ?>';
                }
            };
            xhr.send('action=link_checker_delete&keyword=' + encodeURIComponent(keyword) + '&nonce=' + encodeURIComponent(nonce));
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    })();
    </script>
    <?php
}
