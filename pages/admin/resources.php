<?php
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../../includes/paths.php';
require_once $project_root . 'includes/session.php';
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403); exit('Administrator access required.');
}
header('Cache-Control: no-store');
$raw = @file_get_contents('/var/lib/sahtoutcms/metrics/resources.json');
$data = $raw === false ? null : json_decode($raw, true);
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    if (!is_array($data)) { http_response_code(503); echo '{"error":"Telemetry unavailable"}'; }
    else { echo json_encode($data); }
    exit;
}
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
$page_class = 'resources';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Server Resources · Playerbots</title>
<link rel="stylesheet" href="<?= $base_path ?>assets/css/tailwind.css">
<style>
body{margin:0;background:#080d15;color:#e4eaf3;font-family:Inter,system-ui,sans-serif}.resources{max-width:1400px;padding:36px 30px;margin:auto}.resources h1{font-size:32px;color:#f2cf5b;margin:0 0 8px}.resources h2{font-size:19px;margin:0 0 18px}.muted{color:#a7b6c9}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin:26px 0}.card,.box{background:#111a27;border:1px solid #29374a;border-radius:12px;padding:22px}.card strong{display:block;font-size:28px;margin:12px 0}.card small{color:#a7b6c9}.box{margin-bottom:20px}.resources table{width:100%;border-collapse:collapse;text-align:left}.resources td,.resources th{padding:13px 10px;border-bottom:1px solid #29374a}.resources th{color:#a7b6c9;font-weight:500}.scroll{overflow:auto}#fresh{padding:12px 16px;border:1px solid #365946;border-radius:8px;margin-top:18px;color:#b1e8c6}#fresh.warning{border-color:#b88b3d;color:#ffd58a}.resources button{border:1px solid #65748b;border-radius:6px;padding:6px 12px;color:#e4eaf3;background:#182537;cursor:pointer}.resources .intro{display:flex;justify-content:space-between;gap:20px;align-items:center}.resources progress{width:100%;height:7px;accent-color:#e4c05a}@media(max-width:1100px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:550px){.cards{grid-template-columns:1fr}.resources{padding:28px 16px}.resources .intro{display:block}}
</style></head><body>
<?php include $project_root . 'includes/header.php'; include $project_root . 'includes/admin_sidebar.php'; ?>
<main id="mainContent" class="lg:ml-[280px]" style="padding-top:110px"><div class="resources">
<div class="intro"><div><h1>Server Resources</h1><p class="muted">Live host usage · server-01 · <span id="cores">—</span></p></div><button id="pause" type="button">Pause refresh</button></div>
<div id="fresh" role="status" aria-live="polite">Loading telemetry…</div>
<div class="cards" id="cards"></div>
<section class="box"><h2>Realm &amp; web services</h2><p class="muted">CPU uses 100% per logical CPU; a service can exceed 100%. Memory includes its whole service group and cache.</p><div class="scroll"><table><thead><tr><th>Service</th><th>State</th><th>CPU</th><th>Memory</th><th>Restarts</th></tr></thead><tbody id="services"></tbody></table></div></section>
<section class="box"><h2>Storage</h2><div class="scroll"><table><thead><tr><th>Path</th><th>Used</th><th>Available</th><th>Total</th></tr></thead><tbody id="disks"></tbody></table></div><p class="muted">Paths may share the same filesystem; their capacities are not additive.</p></section>
<section class="box"><h2>Resource pressure</h2><p class="muted">Percentage of time at least one task waited for a resource. Sustained pressure can explain lag even when memory or CPU totals look reasonable.</p><div class="scroll"><table><thead><tr><th>Resource</th><th>Last 10 seconds</th><th>Last minute</th><th>Last 5 minutes</th></tr></thead><tbody id="pressure"></tbody></table></div></section>
<p class="muted">Snapshots refresh every 15 seconds. Host CPU is a one-second sample; service CPU is averaged between snapshots. This page reports current usage, not a proven safe bot capacity.</p>
</div></main>
<script>
const byId=id=>document.getElementById(id), gib=n=>n==null?'Unavailable':n===0?'0 B':n<1048576?(n/1024).toFixed(1)+' KiB':n<1073741824?(n/1048576).toFixed(1)+' MiB':(n/1073741824).toFixed(2)+' GiB', pct=n=>n==null?'Unavailable':n.toFixed(1)+'%';
let latest=null, paused=false, failed=false;
function cellRow(parent, values){const tr=document.createElement('tr'); for(const value of values){const td=document.createElement('td');td.textContent=value;tr.append(td)}parent.append(tr)}
function freshness(){const age=latest?Math.max(0,Date.now()/1000-latest.sampled_at):Infinity;byId('fresh').className=failed||age>45?'warning':'';byId('fresh').textContent=!latest?'Telemetry unavailable — retrying.':(failed?'Refresh failed · ':age>45?'Stale telemetry · ':paused?'Refresh paused · ':'Live · ')+ 'sampled '+new Date(latest.sampled_at*1000).toLocaleTimeString()+' · '+Math.floor(age)+' seconds ago';}
function render(d){latest=d;byId('cores').textContent=d.cpus+' logical CPUs · uptime '+Math.floor(d.uptime_seconds/86400)+'d '+Math.floor(d.uptime_seconds/3600%24)+'h';byId('cards').replaceChildren();
const cards=[['Host CPU',pct(d.cpu_percent),'Total capacity across all CPUs',d.cpu_percent],['Memory',gib(d.memory.used),gib(d.memory.available)+' available of '+gib(d.memory.total),100*d.memory.used/d.memory.total],['Swap',gib(d.swap.used),'of '+gib(d.swap.total),d.swap.total?100*d.swap.used/d.swap.total:0],['System load',d.load.map(n=>n.toFixed(2)).join(' / '),'1 / 5 / 15 minutes · '+d.cpus+' logical CPUs',null]];
for(const [label,value,note,percent] of cards){const c=document.createElement('div');c.className='card';for(const [tag,text] of [['span',label],['strong',value],['small',note]]){const e=document.createElement(tag);e.textContent=text;c.append(e)}if(percent!=null){const p=document.createElement('progress');p.max=100;p.value=percent;p.setAttribute('aria-label',label);c.append(p)}byId('cards').append(c)}
byId('services').replaceChildren();for(const s of d.services)cellRow(byId('services'),[s.unit.replace('.service',''),s.state,pct(s.cpu_percent),gib(s.memory),s.restarts??'Unavailable']);byId('disks').replaceChildren();for(const s of d.disks)cellRow(byId('disks'),[s.path,gib(s.used)+' ('+pct(100*s.used/s.total)+')',gib(s.free),gib(s.total)]);byId('pressure').replaceChildren();for(const [k,v] of Object.entries(d.pressure))cellRow(byId('pressure'),[k.toUpperCase(),pct(v?.some?.avg10),pct(v?.some?.avg60),pct(v?.some?.avg300)]);freshness();}
async function refresh(){if(!paused&&!document.hidden){try{const r=await fetch(window.location.pathname+'?format=json',{cache:'no-store',signal:AbortSignal.timeout(8000)});if(!r.ok)throw Error();const d=await r.json();render(d);failed=false;}catch(e){failed=true;}freshness();}setTimeout(refresh,15000)}
byId('pause').onclick=()=>{paused=!paused;byId('pause').textContent=paused?'Resume refresh':'Pause refresh';freshness()};refresh();setInterval(freshness,1000);
</script></body></html>
