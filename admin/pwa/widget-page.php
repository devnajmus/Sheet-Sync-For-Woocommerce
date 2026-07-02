<?php
/**
 * Mobile PWA widget page (standalone snapshot).
 *
 * @var array<string, mixed> $branding
 * @var string               $title
 * @var string               $manifest
 * @var string               $sw
 * @var string               $ajax
 * @var string               $nonce
 */
defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="<?php echo esc_attr( $branding['primary_color'] ); ?>">
    <link rel="manifest" href="<?php echo esc_url( $manifest ); ?>">
    <title><?php echo esc_html( $title ); ?></title>
    <style>
        :root {
            --primary: <?php echo esc_attr( $branding['primary_color'] ); ?>;
            --accent: <?php echo esc_attr( $branding['accent_color'] ); ?>;
            --bg: #0f1117;
            --card: #1a1d27;
            --text: #eef0f6;
            --muted: #8891aa;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding: 20px 16px 32px;
        }
        .head { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
        .head img { width: 40px; height: 40px; border-radius: 10px; object-fit: contain; }
        .head h1 { margin: 0; font-size: 1.15rem; font-weight: 700; }
        .sub { color: var(--muted); font-size: 0.8rem; margin-top: 2px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .card {
            background: var(--card);
            border-radius: 14px;
            padding: 16px;
            border: 1px solid rgba(255,255,255,.06);
        }
        .card.wide { grid-column: 1 / -1; }
        .label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
        .val { font-size: 1.35rem; font-weight: 700; margin-top: 6px; color: var(--primary); }
        .val.accent { color: var(--accent); }
        .refresh {
            display: block; width: 100%; margin-top: 16px; padding: 14px;
            border: none; border-radius: 12px; background: var(--primary);
            color: #fff; font-weight: 600; font-size: 1rem; cursor: pointer;
        }
        .loading { text-align: center; color: var(--muted); padding: 40px 0; }
    </style>
</head>
<body>
    <div class="head">
        <?php if ( ! empty( $branding['logo_url'] ) ) : ?>
            <img src="<?php echo esc_url( $branding['logo_url'] ); ?>" alt="">
        <?php endif; ?>
        <div>
            <h1><?php echo esc_html( $title ); ?></h1>
            <div class="sub" id="ss_pwa_updated"><?php esc_html_e( 'Loading…', 'sheetsync-for-woocommerce' ); ?></div>
        </div>
    </div>
    <div id="ss_pwa_root" class="loading"><?php esc_html_e( 'Fetching live snapshot…', 'sheetsync-for-woocommerce' ); ?></div>
    <button type="button" class="refresh" id="ss_pwa_refresh"><?php esc_html_e( 'Refresh', 'sheetsync-for-woocommerce' ); ?></button>
    <script>
    (function(){
        var ajax = <?php echo wp_json_encode( $ajax ); ?>;
        var nonce = <?php echo wp_json_encode( $nonce ); ?>;
        var swUrl = <?php echo wp_json_encode( $sw ); ?>;

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register(swUrl).catch(function(){});
        }

        function money(sym, v) {
            return sym + (parseFloat(v) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function render(d) {
            var sym = d.currency || '$';
            var html = '<div class="grid">';
            html += '<div class="card"><div class="label"><?php echo esc_js( __( 'Net Sales (7d)', 'sheetsync-for-woocommerce' ) ); ?></div><div class="val">' + money(sym, d.net_sales) + '</div></div>';
            html += '<div class="card"><div class="label"><?php echo esc_js( __( 'Orders', 'sheetsync-for-woocommerce' ) ); ?></div><div class="val accent">' + (parseInt(d.total_orders, 10) || 0) + '</div></div>';
            html += '<div class="card"><div class="label"><?php echo esc_js( __( 'Profit', 'sheetsync-for-woocommerce' ) ); ?></div><div class="val accent">' + money(sym, d.gross_profit != null ? d.gross_profit : d.net_profit) + '</div></div>';
            html += '<div class="card"><div class="label"><?php echo esc_js( __( 'Pending', 'sheetsync-for-woocommerce' ) ); ?></div><div class="val">' + (parseInt(d.pending, 10) || 0) + '</div></div>';
            if (d.margin_pct != null) {
                html += '<div class="card wide"><div class="label"><?php echo esc_js( __( 'Gross Margin', 'sheetsync-for-woocommerce' ) ); ?></div><div class="val">' + d.margin_pct + '%</div></div>';
            }
            html += '</div>';
            document.getElementById('ss_pwa_root').innerHTML = html;
            document.getElementById('ss_pwa_updated').textContent = d.generated_at || '';
            try { localStorage.setItem('ss_pwa_snapshot', JSON.stringify(d)); } catch (e) {}
        }

        function load(useCache) {
            if (useCache) {
                try {
                    var cached = JSON.parse(localStorage.getItem('ss_pwa_snapshot') || '');
                    if (cached) render(cached);
                } catch (e) {}
            }
            var fd = new FormData();
            fd.append('action', 'sheetsync_pwa_snapshot');
            fd.append('nonce', nonce);
            fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(r){ if (r.success) render(r.data); })
                .catch(function(){});
        }

        document.getElementById('ss_pwa_refresh').addEventListener('click', function(){ load(false); });
        load(true);
    })();
    </script>
</body>
</html>
