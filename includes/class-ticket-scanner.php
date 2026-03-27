<?php
/**
 * WCTQR_Scanner
 * Full-screen QR ticket scanner with expandable scan log.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WCTQR_Scanner {

    public function __construct() {
        add_shortcode( 'ticket_scanner', [ $this, 'render_scanner' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets() {
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'ticket_scanner' ) ) return;
        wp_enqueue_script( 'zxing', 'https://cdn.jsdelivr.net/npm/@zxing/library@0.21.3/umd/index.min.js', [], '0.21.3', true );
    }

    public static function maybe_create_page() {
        $existing = get_option( 'wctqr_scanner_page_id' );
        if ( $existing && get_post( $existing ) ) return;
        $page_id = wp_insert_post([
            'post_title'   => 'Ticket Scanner',
            'post_name'    => 'ticket-scanner',
            'post_content' => '[ticket_scanner]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ]);
        if ( $page_id && ! is_wp_error( $page_id ) ) update_option( 'wctqr_scanner_page_id', $page_id );
    }

    public function render_scanner() {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
            return '<div style="padding:40px;text-align:center;font-family:sans-serif;max-width:400px;margin:0 auto;">'
                 . '<div style="font-size:48px;">&#128274;</div>'
                 . '<h2 style="color:#1e1e3e;">Staff Access Only</h2>'
                 . '<p style="color:#666;">You must be logged in as a staff member to use the ticket scanner.</p>'
                 . '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" style="display:inline-block;margin-top:16px;padding:14px 28px;background:#7c3aed;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;">Log In</a>'
                 . '</div>';
        }

        $validate_base = rest_url( 'wctqr/v1/validate/' );
        $nonce         = wp_create_nonce( 'wp_rest' );
        $max_log       = (int) get_option( 'wctqr_max_log', 10 );

        ob_start(); ?>
<style>
*{box-sizing:border-box;}
body{background:#0f0f1a!important;}
#wctqr-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;max-width:500px;margin:0 auto;padding:16px 16px 40px;color:#f0f0f0;min-height:100vh;}
#wctqr-header{text-align:center;padding:16px 0 20px;}
#wctqr-header h2{margin:0;font-size:22px;color:#fff;}
#wctqr-header p{margin:6px 0 0;color:#888;font-size:14px;}
#wctqr-viewport{position:relative;border-radius:20px;overflow:hidden;background:#000;aspect-ratio:1/1;box-shadow:0 0 0 3px #7c3aed,0 8px 32px rgba(0,0,0,0.6);}
#wctqr-video{width:100%;height:100%;object-fit:cover;display:block;}
#wctqr-frame{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;}
#wctqr-frame-inner{width:62%;aspect-ratio:1/1;border:2px solid rgba(255,255,255,0.5);border-radius:16px;position:relative;box-shadow:0 0 0 9999px rgba(0,0,0,0.4);}
.wctqr-corner{position:absolute;width:24px;height:24px;border-color:#7c3aed;border-style:solid;border-width:0;}
.wctqr-corner.tl{top:-2px;left:-2px;border-top-width:4px;border-left-width:4px;border-top-left-radius:10px;}
.wctqr-corner.tr{top:-2px;right:-2px;border-top-width:4px;border-right-width:4px;border-top-right-radius:10px;}
.wctqr-corner.bl{bottom:-2px;left:-2px;border-bottom-width:4px;border-left-width:4px;border-bottom-left-radius:10px;}
.wctqr-corner.br{bottom:-2px;right:-2px;border-bottom-width:4px;border-right-width:4px;border-bottom-right-radius:10px;}
#wctqr-scanline{position:absolute;left:19%;width:62%;height:2px;background:linear-gradient(90deg,transparent,#7c3aed,#a855f7,#7c3aed,transparent);border-radius:2px;animation:wctqr-scan 2s ease-in-out infinite;}
@keyframes wctqr-scan{0%,100%{top:19%;opacity:0.4;}50%{top:81%;opacity:1;}}
#wctqr-result{margin-top:20px;min-height:100px;display:flex;align-items:center;justify-content:center;}
.wctqr-idle{color:#555;font-size:14px;text-align:center;}
.wctqr-card{width:100%;border-radius:16px;padding:20px 20px 16px;animation:wctqr-pop 0.2s ease;}
@keyframes wctqr-pop{from{transform:scale(0.94);opacity:0;}to{transform:scale(1);opacity:1;}}
.wctqr-card.valid{background:#052e16;border:2px solid #16a34a;}
.wctqr-card.invalid{background:#2d0505;border:2px solid #dc2626;}
.wctqr-card.warning{background:#1c1000;border:2px solid #d97706;}
.wctqr-card-header{display:flex;align-items:center;gap:14px;margin-bottom:14px;}
.wctqr-card-icon{font-size:40px;line-height:1;flex-shrink:0;}
.wctqr-card-status h3{margin:0;font-size:20px;font-weight:800;}
.valid .wctqr-card-status h3{color:#4ade80;}
.invalid .wctqr-card-status h3{color:#f87171;}
.warning .wctqr-card-status h3{color:#fbbf24;}
.wctqr-card-status p{margin:3px 0 0;font-size:13px;color:#aaa;}
.wctqr-details{border-top:1px solid rgba(255,255,255,0.08);padding-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.wctqr-detail-label{font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#666;margin-bottom:2px;}
.wctqr-detail-value{font-size:14px;color:#e0e0e0;font-weight:500;}

/* Scan Log */
#wctqr-log-section{margin-top:24px;}
#wctqr-log-section h3{font-size:13px;color:#555;margin:0 0 8px;text-transform:uppercase;letter-spacing:0.05em;}
#wctqr-log{display:flex;flex-direction:column;gap:6px;}
.wctqr-log-entry{border-radius:10px;overflow:hidden;cursor:pointer;}
.wctqr-log-entry.valid  {border-left:3px solid #16a34a;}
.wctqr-log-entry.warning{border-left:3px solid #d97706;}
.wctqr-log-entry.invalid{border-left:3px solid #dc2626;}
.wctqr-log-summary{display:flex;justify-content:space-between;align-items:center;padding:9px 12px;font-size:13px;user-select:none;}
.wctqr-log-entry.valid   .wctqr-log-summary{background:#052e16;}
.wctqr-log-entry.warning .wctqr-log-summary{background:#1c1000;}
.wctqr-log-entry.invalid .wctqr-log-summary{background:#2d0505;}
.wctqr-log-summary-left{display:flex;align-items:center;gap:8px;}
.wctqr-log-chevron{font-size:10px;color:#666;transition:transform 0.2s;margin-left:6px;}
.wctqr-log-entry.open .wctqr-log-chevron{transform:rotate(180deg);}
.wctqr-log-details{display:none;padding:10px 12px 12px;font-size:13px;gap:8px;grid-template-columns:1fr 1fr;}
.wctqr-log-entry.open .wctqr-log-details{display:grid;}
.wctqr-log-entry.valid   .wctqr-log-details{background:#021f0d;}
.wctqr-log-entry.warning .wctqr-log-details{background:#140c00;}
.wctqr-log-entry.invalid .wctqr-log-details{background:#1f0303;}
.wctqr-log-detail-label{font-size:10px;text-transform:uppercase;letter-spacing:0.05em;color:#555;margin-bottom:1px;}
.wctqr-log-detail-value{font-size:13px;color:#ccc;}
.wctqr-log-time{color:#555;font-size:11px;white-space:nowrap;}

/* Manual entry */
#wctqr-manual-section{margin-top:20px;}
#wctqr-manual-section summary{cursor:pointer;color:#7c3aed;font-size:13px;user-select:none;list-style:none;padding:4px 0;}
#wctqr-manual-row{margin-top:10px;display:flex;gap:8px;}
#wctqr-manual-input{flex:1;padding:10px 12px;background:#1a1a2e;border:1px solid #333;border-radius:8px;color:#fff;font-size:13px;}
#wctqr-manual-input::placeholder{color:#555;}
#wctqr-manual-btn{padding:10px 16px;background:#7c3aed;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:14px;font-weight:600;}
</style>

<div id="wctqr-wrap">
    <div id="wctqr-header">
        <h2>&#127903; Ticket Scanner</h2>
        <p>Point camera at a ticket QR code</p>
    </div>
    <div id="wctqr-viewport">
        <video id="wctqr-video" playsinline autoplay muted></video>
        <div id="wctqr-frame">
            <div id="wctqr-frame-inner">
                <div class="wctqr-corner tl"></div>
                <div class="wctqr-corner tr"></div>
                <div class="wctqr-corner bl"></div>
                <div class="wctqr-corner br"></div>
            </div>
        </div>
        <div id="wctqr-scanline"></div>
    </div>
    <div id="wctqr-result"><p class="wctqr-idle">Waiting for scan&hellip;</p></div>
    <details id="wctqr-manual-section">
        <summary>&#9998; Enter token manually</summary>
        <div id="wctqr-manual-row">
            <input id="wctqr-manual-input" type="text" placeholder="Paste full 64-character token" />
            <button id="wctqr-manual-btn" onclick="wctqrValidate(document.getElementById('wctqr-manual-input').value)">Check</button>
        </div>
    </details>
    <div id="wctqr-log-section">
        <h3>Session log <span id="wctqr-log-count" style="color:#444;">(0)</span></h3>
        <div id="wctqr-log"><p style="color:#444;font-size:13px;margin:0;">No scans yet.</p></div>
    </div>
</div>

<script>
(function(){
    var BASE  = <?php echo json_encode($validate_base); ?>;
    var NONCE = <?php echo json_encode($nonce); ?>;
    var MAX_LOG = <?php echo intval($max_log); ?>;
    var lastToken='', cooldown=false;
    var scanLog = []; // stores full data for each scan

    // ── ZXing ────────────────────────────────────────────────────────────────
    function initScanner() {
        if (typeof ZXing==='undefined'){setTimeout(initScanner,200);return;}
        try {
            var cr = new ZXing.BrowserQRCodeReader();
            cr.decodeFromVideoDevice(null,'wctqr-video',function(result,err){
                if(result&&!cooldown){
                    var token=extractToken(result.getText());
                    if(token&&token!==lastToken){lastToken=token;wctqrValidate(token);}
                }
            });
        } catch(e){
            setResult('<div class="wctqr-card invalid"><div class="wctqr-card-header"><div class="wctqr-card-icon">&#128247;</div><div class="wctqr-card-status"><h3>Scanner Error</h3><p>'+e.message+'</p></div></div></div>');
        }
    }
    window.addEventListener?window.addEventListener('load',initScanner):initScanner();

    function extractToken(raw){
        var m=raw.match(/([a-f0-9]{64})$/i);
        return m?m[1].toLowerCase():null;
    }

    // ── Validate ─────────────────────────────────────────────────────────────
    window.wctqrValidate=function(token){
        if(!token)return;
        token=token.trim().toLowerCase();
        if(!/^[a-f0-9]{64}$/.test(token)){
            setResult(makeCard('invalid','&#10060;','Invalid Format','Not a valid ticket token.',null));
            return;
        }
        cooldown=true;
        setResult('<p class="wctqr-idle" style="color:#7c3aed;">&#128269; Validating&hellip;</p>');

        fetch(BASE+token,{method:'POST',headers:{'X-WP-Nonce':NONCE,'Content-Type':'application/json'}})
        .then(function(r){return r.json().then(function(d){return{s:r.status,d:d};});})
        .then(function(r){
            var d=r.d,s=r.s;
            var ref=token.substring(0,8).toUpperCase()+'&hellip;';
            var time=new Date().toLocaleTimeString();
            var logType,icon,title,sub;

            if(s===200&&d.valid){
                icon='&#9989;';title='VALID &mdash; ADMIT';sub='Order #'+(d.order_id||'?');
                logType='valid';
                setResult(makeCard('valid',icon,title,sub,d));
                vibrate([100,50,100]);
            } else {
                if(s===409){icon='&#9888;&#65039;';title='ALREADY SCANNED';logType='warning';sub=d.scanned_at?'Scanned: '+d.scanned_at:'Previously admitted.';}
                else if(s===410){icon='&#10060;';title='TICKET CANCELLED';logType='invalid';sub='Refunded or cancelled.';}
                else if(s===404){icon='&#10060;';title='INVALID TICKET';logType='invalid';sub='Token not found.';}
                else{icon='&#10060;';title='REJECTED';logType='invalid';sub=d.message||'';}
                setResult(makeCard(logType,icon,title,sub,d));
                vibrate([300]);
            }

            // Add to session log
            addToLog(logType, icon, ref, time, d, title);

            setTimeout(function(){
                lastToken='';cooldown=false;
                setResult('<p class="wctqr-idle">Ready for next scan&hellip;</p>');
            },3500);
        })
        .catch(function(e){
            setResult(makeCard('invalid','&#9889;','Network Error',e.message,null));
            setTimeout(function(){cooldown=false;lastToken='';},3000);
        });
    };

    // ── Card builder ──────────────────────────────────────────────────────────
    function makeCard(type,icon,title,sub,d){
        var html='<div class="wctqr-card '+type+'">'
            +'<div class="wctqr-card-header">'
            +'<div class="wctqr-card-icon">'+icon+'</div>'
            +'<div class="wctqr-card-status"><h3>'+title+'</h3>'+(sub?'<p>'+sub+'</p>':'')+'</div></div>';
        if(d&&(d.attendee||d.product_name)){
            html+='<div class="wctqr-details">';
            if(d.attendee)     html+=di('Attendee',esc(d.attendee));
            if(d.variation)    html+=di('Type',esc(d.variation));
            if(d.event_date)   html+=di('Date',esc(d.event_date));
            if(d.event_venue)  html+=di('Venue',esc(d.event_venue));
            if(d.ticket_number)html+=di('Ticket',esc(d.ticket_number));
            if(d.order_id)     html+=di('Order','#'+d.order_id);
            html+='</div>';
        }
        html+='</div>';
        return html;
    }

    function di(label,value){
        return '<div><div class="wctqr-detail-label">'+label+'</div><div class="wctqr-detail-value">'+value+'</div></div>';
    }

    // ── Expandable log ────────────────────────────────────────────────────────
    function addToLog(type,icon,ref,time,d,statusTitle){
        var log=document.getElementById('wctqr-log');
        var empty=log.querySelector('p');
        if(empty)empty.remove();

        // Build details fields
        var detailFields=[];
        if(d){
            if(d.attendee)      detailFields.push({l:'Attendee',v:d.attendee});
            if(d.email)         detailFields.push({l:'Email',v:d.email});
            if(d.product_name)  detailFields.push({l:'Event',v:d.product_name});
            if(d.variation)     detailFields.push({l:'Ticket Type',v:d.variation});
            if(d.event_date)    detailFields.push({l:'Date',v:d.event_date});
            if(d.event_venue)   detailFields.push({l:'Venue',v:d.event_venue});
            if(d.ticket_number) detailFields.push({l:'Ticket #',v:d.ticket_number});
            if(d.order_id)      detailFields.push({l:'Order',v:'#'+d.order_id});
            detailFields.push({l:'Scanned At',v:time});
        }

        var detailsHtml='';
        if(detailFields.length){
            detailFields.forEach(function(f){
                detailsHtml+='<div><div class="wctqr-log-detail-label">'+esc(f.l)+'</div>'
                    +'<div class="wctqr-log-detail-value">'+esc(String(f.v))+'</div></div>';
            });
        } else {
            detailsHtml='<div style="grid-column:1/-1;color:#555;font-size:12px;">No details available</div>';
        }

        var summaryText = d&&d.attendee
            ? esc(d.attendee) + (d.variation?' &mdash; '+esc(d.variation):'')
            : ref;

        var entry=document.createElement('div');
        entry.className='wctqr-log-entry '+type;
        entry.innerHTML=
            '<div class="wctqr-log-summary">'
            +'<span class="wctqr-log-summary-left">'+icon+' <span>'+summaryText+'</span><span class="wctqr-log-chevron">&#9660;</span></span>'
            +'<span class="wctqr-log-time">'+time+'</span>'
            +'</div>'
            +'<div class="wctqr-log-details">'+detailsHtml+'</div>';

        // Toggle expand on click
        entry.querySelector('.wctqr-log-summary').addEventListener('click',function(){
            entry.classList.toggle('open');
        });

        log.insertBefore(entry,log.firstChild);

        // Keep max entries
        while(log.children.length>MAX_LOG) log.removeChild(log.lastChild);

        // Update count
        document.getElementById('wctqr-log-count').textContent='('+log.children.length+')';
    }

    function setResult(html){document.getElementById('wctqr-result').innerHTML=html;}
    function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
    function vibrate(p){if(navigator.vibrate)navigator.vibrate(p);}
})();
</script>
<?php
        return ob_get_clean();
    }
}

new WCTQR_Scanner();
