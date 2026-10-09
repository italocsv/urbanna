<?php
/**
 * URBANNA — PORTAL DE MÍDIAS | PHP 8.1+ / PDO MySQL / cURL
 * Instalação: configure o banco abaixo e publique este arquivo via HTTPS.
 * Abra /portal-midias.php. Não importe novamente o dump da tabela existente.
 * Login: urbanna | Senha: urbanna+midias (validado somente no servidor).
 * Copiar link: link do produto na loja. No modal há também Copiar vídeo.
 * Downloads: anexo servido pelo PHP, sem depender do atributo download externo.
 * iPhone: abrir no Safari; arquivo em Arquivos > Downloads. A gravação em Fotos
 * depende da opção Salvar Vídeo no compartilhamento e do codec original.
 * MP4 e MOV são preservados; este portal não converte codecs.
 * O download usa arquivo temporário em disco (limite de 512 MiB por vídeo),
 * apagado automaticamente. Ajuste timeouts do PHP/proxy para vídeos grandes.
 * Consulta paginada, somente video_1, inclusive estoque zero, data DESC e id DESC, 100 por página.
 */
// Configure estes quatro valores (ou use as variáveis de ambiente indicadas).
$cfg = [
    'host' => getenv('MIDIAS_DB_HOST') ?: '148.230.72.178',
    'database' => getenv('MIDIAS_DB_NAME') ?: 'lojaur05_tagplus',
    //'user' => getenv('URBANNA_DB_USER') ?: 'lojaur05_admin',
    //'password' => getenv('URBANNA_DB_PASS') ?: '',
    'user' => 'lojaur05_admin',
    'password' => 'M2emsvjmt*20',
    'port' => getenv('MIDIAS_DB_PORT') ?: '3306',
    // Acrescente aqui outros hosts EXATOS caso os vídeos mudem de servidor.
    'video_hosts' => ['core.urbanna.com.br','urbanna.b-cdn.net'],
];
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
session_name('urbanna_midias');
session_set_cookie_params(['httponly'=>true, 'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite'=>'Lax', 'path'=>'/']);
session_start();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function jsonReply(array $data, int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function database(array $cfg): PDO { return new PDO('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['database'].';charset=utf8mb4', $cfg['user'], $cfg['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]); }
function validCsrf(): bool { return isset($_POST['csrf']) && is_string($_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']); }
$action = (string)($_GET['action'] ?? '');
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrf()) { http_response_code(403); $loginError='A sessão expirou. Atualize a página.'; }
    elseif (($_POST['action'] ?? '') === 'logout') { $_SESSION=[]; session_regenerate_id(true); header('Location: '.strtok($_SERVER['REQUEST_URI'], '?')); exit; }
    elseif (($_POST['action'] ?? '') === 'login') {
        if (time() < ($_SESSION['locked_until'] ?? 0)) $loginError='Aguarde um minuto para tentar novamente.';
        elseif (hash_equals('urbanna', (string)($_POST['user'] ?? '')) && hash_equals('urbanna+midias', (string)($_POST['password'] ?? ''))) {
            session_regenerate_id(true); $_SESSION['logged']=true; $_SESSION['attempts']=0; $_SESSION['csrf']=bin2hex(random_bytes(32)); header('Location: '.strtok($_SERVER['REQUEST_URI'], '?')); exit;
        } else { $_SESSION['attempts']=($_SESSION['attempts'] ?? 0)+1; if ($_SESSION['attempts']>=5) { $_SESSION['locked_until']=time()+60; $_SESSION['attempts']=0; } $loginError='Usuário ou senha incorretos.'; }
    }
}
$logged = !empty($_SESSION['logged']);
if (in_array($action, ['products', 'download'], true)) {
    if (!$logged) { if ($action==='products') jsonReply(['error'=>'Entre novamente para continuar.'],401); http_response_code(401); exit('Sua sessão expirou. Volte ao portal e entre novamente.'); }
    session_write_close(); // Um download não bloqueia a navegação da sessão.
    try {
        $db=database($cfg);
        if ($action==='products') {
            $page=max(1,min(100000,(int)($_GET['page'] ?? 1))); $size=100;
            $search=trim(substr((string)($_GET['q'] ?? ''),0,150));
            $where=" WHERE video_1 IS NOT NULL AND TRIM(video_1) <> ''";
            $params=[];
            if ($search!=='') { $where.=" AND (nome LIKE ? OR codigo_pai LIKE ?)"; $needle='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%'; $where=str_replace('LIKE ?', "LIKE ? ESCAPE '!'", $where); $params=[$needle,$needle]; }
            if (($_GET['stock'] ?? '')==='1') $where.=' AND estoque_total > 0';
            $category=trim(substr((string)($_GET['category'] ?? ''),0,120));
            $subcategory=trim(substr((string)($_GET['subcategory'] ?? ''),0,120));
            if ($category!=='') { $where.=' AND categoria = ?'; $params[]=$category; }
            if ($subcategory!=='') { $where.=' AND subcategoria = ?'; $params[]=$subcategory; }
            $filters=null;
            if (($_GET['filters'] ?? '')==='1') {
                $filters=$db->query("SELECT DISTINCT categoria, subcategoria FROM midias_produtos WHERE video_1 IS NOT NULL AND TRIM(video_1) <> '' ORDER BY categoria ASC, subcategoria ASC")->fetchAll();
            }
            $count=$db->prepare('SELECT COUNT(*) FROM midias_produtos'.$where); $count->execute($params); $total=(int)$count->fetchColumn();
            $page=min($page,max(1,(int)ceil($total/$size))); $offset=($page-1)*$size;
            $sql='SELECT id, nome, codigo_pai, imagem_principal, link, video_1, estoque_total, preco, data_criacao FROM midias_produtos'.$where.' ORDER BY data_criacao DESC, id DESC LIMIT '.$size.' OFFSET '.$offset;
            $stmt=$db->prepare($sql); $stmt->execute($params);
            jsonReply(['products'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$size)),'filters'=>$filters,'size'=>$size]);
        }
        $id=filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$id) { http_response_code(400); exit('Produto inválido.'); }
        $stmt=$db->prepare('SELECT codigo_pai, video_1 FROM midias_produtos WHERE id = ?'); $stmt->execute([$id]); $p=$stmt->fetch();
        if (!$p || !trim($p['video_1'])) { http_response_code(404); exit('Vídeo não encontrado.'); }
        $url=trim($p['video_1']); $parts=parse_url($url); $host=strtolower($parts['host'] ?? '');
        $ext=strtolower(pathinfo($parts['path'] ?? '', PATHINFO_EXTENSION));
        if (($parts['scheme'] ?? '')!=='https' || !in_array($host,$cfg['video_hosts'],true) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port']!==443) || !in_array($ext,['mp4','mov','m4v','webm'],true)) throw new RuntimeException('Origem de vídeo não permitida.');
        // Pinagem de IP público: não permite que o endpoint acesse a rede interna.
        $ips=gethostbynamel($host) ?: []; $ip=null;
        foreach ($ips as $candidate) if (filter_var($candidate,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) { $ip=$candidate; break; }
        if (!$ip) throw new RuntimeException('Servidor do vídeo indisponível.');
        if (!function_exists('curl_init')) throw new RuntimeException('Extensão cURL ausente.');
        set_time_limit(300); $temp=tmpfile(); if (!$temp) throw new RuntimeException('Sem espaço temporário.');
        $bytes=0; $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>240,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_WRITEFUNCTION=>function($ch,$chunk) use($temp,&$bytes) { $bytes+=strlen($chunk); if ($bytes>512*1024*1024) return 0; return fwrite($temp,$chunk); }]);
        $ok=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE); $type=strtolower((string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE)); curl_close($ch);
        if (!$ok || $status!==200 || $bytes===0 || str_contains($type,'text/') || str_contains($type,'json')) { fclose($temp); throw new RuntimeException('Falha ao recuperar o vídeo.'); }
        $code=preg_replace('/[^a-zA-Z0-9_-]/','_', $p['codigo_pai']); $name='urbanna_'.$code.'_video_1.'.$ext;
        while (ob_get_level()) ob_end_clean();
        ini_set('zlib.output_compression','0');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$name.'"; filename*=UTF-8\'\''.rawurlencode($name));
        header('Content-Length: '.$bytes); header('X-Accel-Buffering: no');
        rewind($temp); fpassthru($temp); fclose($temp); exit;
    } catch (Throwable $e) {
        error_log('Urbanna mídias: '.$e->getMessage());
        if ($action==='products') jsonReply(['error'=>'Não foi possível consultar os produtos. Confira a configuração do banco.'],503);
        http_response_code(502); header('Content-Type: text/html; charset=utf-8'); exit('<p>Não foi possível baixar o vídeo. Tente novamente ou peça ao responsável para verificar o servidor e o endereço do arquivo.</p><p><a href="?">Voltar ao portal</a></p>');
    }
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#8b3b51"><title>Urbanna · Mídias</title>
<style>
:root{--wine:#8b3b51;--ink:#33282c;--muted:#766b6e;--line:#e9dfe2;--bg:#fbf8f9}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}button,input,a{font:inherit}button,a{touch-action:manipulation}button,.btn{cursor:pointer;border:1px solid var(--line);background:white;border-radius:12px;padding:11px 15px;color:var(--ink);text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:7px}button:hover,.btn:hover{border-color:var(--wine)}button:disabled{opacity:.4;cursor:default}.primary{background:var(--wine);color:white;border-color:var(--wine)}input{border:1px solid var(--line);border-radius:12px;padding:13px;background:white;min-width:0}input:focus-visible,button:focus-visible,a:focus-visible{outline:3px solid #c896a6;outline-offset:3px}.brand{font-family:Georgia,serif;font-size:32px;letter-spacing:3px;font-weight:normal;margin:0}.small{font-size:13px;color:var(--muted)}.eyebrow{text-transform:uppercase;letter-spacing:2px;font-size:11px;color:var(--wine);font-weight:700}.login{min-height:100dvh;display:grid;place-items:center;padding:24px}.loginbox{width:min(100%,410px);padding:38px;background:white;border:1px solid var(--line);border-radius:24px;box-shadow:0 15px 70px #5824370b}.loginbox form{display:grid;gap:12px;margin-top:28px}.loginbox label{display:grid;gap:5px}.error{color:#aa263e}.top{background:white;border-bottom:1px solid var(--line)}.topinner{max-width:1220px;margin:auto;padding:20px 24px;display:flex;align-items:center;justify-content:space-between;gap:20px}main{max-width:1220px;margin:auto;padding:35px 24px}h1{font:36px/1.2 Georgia,serif;margin:8px 0 12px}p{margin:8px 0 16px}.toolbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:26px 0 12px}.search{display:flex;flex:1;gap:8px;min-width:230px}.search input{flex:1;width:100%}.check{display:flex;align-items:center;gap:8px}.check input{accent-color:var(--wine);width:18px;height:18px}.meta{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:20px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px}.card{background:white;border:1px solid var(--line);border-radius:18px;overflow:hidden}.cover{border:0;padding:0;width:100%;border-radius:0;position:relative;background:#f4f0f1;aspect-ratio:1/1;display:block}.cover img{width:100%;height:100%;object-fit:contain}.play{position:absolute;bottom:12px;right:12px;background:var(--wine);color:white;width:38px;height:38px;border-radius:50%;display:grid;place-items:center}.cardbody{padding:16px}.title{padding:0;border:0;display:block;text-align:left;font-weight:600;line-height:1.4;min-height:42px;background:none}.details{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:14px 0}.price{font-weight:700;font-size:20px}.stock{font-size:11px;background:#edf5ef;color:#386047;padding:5px 8px;border-radius:30px;white-space:nowrap}.stock.zero{background:#f3eeee;color:#856c71}.actions{display:flex;gap:8px}.actions>*{flex:1;padding:10px 6px;font-size:12px}.pagination{display:flex;justify-content:center;gap:15px;align-items:center;margin:30px 0}.empty{padding:45px;text-align:center;border:1px dashed var(--line);border-radius:16px;grid-column:1/-1}.help{border:1px solid var(--line);padding:14px 18px;border-radius:14px;color:var(--muted);font-size:13px;margin:22px 0}dialog{border:0;padding:0;border-radius:22px;width:min(94vw,760px);max-height:94dvh;background:white;color:var(--ink)}dialog::backdrop{background:#25151cc9;backdrop-filter:blur(4px)}.modalhead{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:16px 20px}.modalhead h2{font-size:18px;margin:0}.videoarea{background:#171215;display:grid;place-items:center}.videoarea video{display:block;width:100%;max-height:60dvh}.modalfoot{padding:18px 20px}.modalactions{display:flex;gap:8px;flex-wrap:wrap}.modalfoot .small{margin:12px 0 0}.toast{position:fixed;bottom:25px;left:50%;transform:translateX(-50%);padding:12px 20px;border-radius:12px;background:var(--ink);color:white;z-index:20;max-width:90vw;text-align:center}.toast:empty{display:none}#copyDialog{padding:24px}#copyDialog input{width:100%;margin-bottom:15px}@media(max-width:1000px){.grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:720px){.grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}main{padding:25px 14px}.topinner{padding:16px}.brand{font-size:26px}.cardbody{padding:12px}.price{font-size:17px}.details{align-items:flex-start;flex-direction:column;gap:6px}h1{font-size:30px}.actions{flex-direction:column}.toolbar{gap:14px}.help{padding:12px}.title{font-size:14px}.modalactions>*{flex:1}dialog{max-height:96dvh}.small{font-size:12px}}
select{font:inherit;color:var(--ink);border:1px solid var(--line);border-radius:12px;background:white;padding:12px;max-width:100%}select:focus-visible{outline:3px solid #c896a6;outline-offset:3px}.filters{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}.filterfield{display:grid;gap:5px;flex:1;min-width:150px}.pagination{flex-wrap:wrap;gap:8px;padding:18px;border:1px solid var(--line);border-radius:18px;background:white}.pagechoice{display:flex;align-items:center;gap:8px;margin:0 8px}.pagination button{min-height:44px}.pagination select{min-width:76px}.pagination .small{white-space:nowrap}@media(max-width:480px){.pagination{padding:12px;gap:6px}.pagination button{font-size:12px;padding:10px}.pagechoice{order:2;width:100%;justify-content:center;margin:6px 0 0}.filterfield{min-width:135px}}
</style></head><body>
<?php if (!$logged): ?>
<div class="login"><section class="loginbox"><p class="eyebrow">Biblioteca de conteúdo</p><h1 class="brand">urbanna</h1><p class="small">Fotos de produtos, vídeos prontos e inspiração.<br>Entre para acessar os vídeos da equipe.</p><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><input type="hidden" name="action" value="login"><label>Usuário<input name="user" autocomplete="username" required autocapitalize="none" spellcheck="false"></label><label>Senha<input name="password" type="password" autocomplete="current-password" required></label><?php if ($loginError): ?><p class="error" role="alert"><?=esc($loginError)?></p><?php endif; ?><button class="primary" type="submit">Entrar no portal →</button></form></section></div>
<?php else: ?>
<header class="top"><div class="topinner"><div><p class="brand">urbanna</p><span class="eyebrow">Portal de mídias</span></div><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><input type="hidden" name="action" value="logout"><button>Sair</button></form></div></header>
<main><span class="eyebrow">Conteúdo para compartilhar</span><h1>Um produto. Muitas possibilidades.</h1><p class="small">Encontre o produto, assista ao vídeo e leve o conteúdo com você.</p><div class="toolbar"><form class="search" id="search"><input id="query" type="search" placeholder="Buscar pelo nome ou código…" aria-label="Buscar produto"><button class="primary">Buscar</button></form><label class="check"><input type="checkbox" id="stock">Somente com estoque</label></div><div class="filters"><label class="filterfield small">Categoria<select id="category"><option value="">Todas as categorias</option></select></label><label class="filterfield small">Subcategoria<select id="subcategory"><option value="">Todas as subcategorias</option></select></label></div><div class="meta small"><span id="count" role="status">Carregando produtos…</span><span>Mais recentes primeiro · Data de criação ↓</span></div><div class="grid" id="grid" aria-busy="true"></div><nav class="pagination" aria-label="Paginação de produtos"><button id="first" disabled>⇤ Primeira</button><button id="prev" disabled>← Anterior</button><label class="pagechoice small">Página <select id="pageSelect" aria-label="Selecionar página" disabled><option value="1">1</option></select><span id="pages" aria-live="polite">de 1</span></label><button id="next" disabled>Próxima →</button><button id="last" disabled>Última ⇥</button></nav><div class="help">No iPhone, use o Safari. Toque em <strong>Baixar vídeo</strong> e confirme o download. Procure o arquivo em <strong>Arquivos → Downloads</strong>. Para levar a Fotos, abra o arquivo e procure <strong>Compartilhar → Salvar Vídeo</strong>, quando disponível.</div></main>
<dialog id="videoDialog" aria-labelledby="modalTitle"><div class="modalhead"><h2 id="modalTitle"></h2><button id="close" aria-label="Fechar vídeo">✕</button></div><div class="videoarea"><video id="player" controls playsinline preload="none"></video></div><div class="modalfoot"><div class="modalactions"><button id="modalCopy">Copiar link do produto</button><button id="videoCopy">Copiar link do vídeo</button><a id="modalDownload" class="btn primary">↓ Baixar vídeo</a></div><p class="small" id="videoInfo"></p><p class="small" id="videoError" role="alert" hidden>O navegador não conseguiu reproduzir este arquivo. Tente baixar o vídeo para abri-lo no aparelho.</p></div></dialog>
<dialog id="copyDialog" aria-labelledby="copyTitle"><h2 id="copyTitle">Copie o link</h2><p class="small">Toque e segure o endereço, selecione e copie.</p><input id="copyInput" readonly aria-label="Link para copiar"><button id="closeCopy">Fechar</button></dialog><div class="toast" id="toast" role="status" aria-live="polite"></div>
<script>
'use strict';
const $=s=>document.querySelector(s), money=new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'});
let page=1,pages=1,items=[],current=null,controller,toastTimer,opener,filterPairs=[],filtersLoaded=false;
function notice(text){$('#toast').textContent=text;clearTimeout(toastTimer);toastTimer=setTimeout(()=>$('#toast').textContent='',4000)}
function safeUrl(value){try{const u=new URL(value);return ['https:','http:'].includes(u.protocol)?u.href:''}catch{return ''}}
function downloadUrl(p){const u=new URL(location.href);u.search='';u.searchParams.set('action','download');u.searchParams.set('id',p.id);return u.href}
async function copy(value){const link=safeUrl(value);if(!link){notice('Este produto não tem um link válido.');return}try{await navigator.clipboard.writeText(link);notice('Link copiado!')}catch{$('#copyInput').value=link;$('#copyDialog').showModal();$('#copyInput').focus();$('#copyInput').select()}}
$('#closeCopy').onclick=()=>$('#copyDialog').close();
function openVideo(p,button){current=p;opener=button;$('#modalTitle').textContent=p.nome;$('#videoInfo').textContent=`Código ${p.codigo_pai} · ${money.format(Number(p.preco))} · Estoque total: ${p.estoque_total}`;$('#videoError').hidden=true;$('#player').src=safeUrl(p.video_1);$('#modalDownload').href=downloadUrl(p);$('#videoDialog').showModal();$('#player').play().catch(()=>{});}
$('#player').addEventListener('error',()=>$('#videoError').hidden=false);
$('#close').onclick=()=>$('#videoDialog').close();
$('#videoDialog').addEventListener('click',e=>{if(e.target===$('#videoDialog')){const r=e.target.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)e.target.close()}});
$('#videoDialog').addEventListener('close',()=>{$('#player').pause();$('#player').removeAttribute('src');$('#player').load();opener?.focus()});
$('#modalCopy').onclick=()=>current&&copy(current.link);$('#videoCopy').onclick=()=>current&&copy(current.video_1);
function element(tag,className,text){const e=document.createElement(tag);if(className)e.className=className;if(text!==undefined)e.textContent=text;return e}
function render(){const grid=$('#grid');grid.replaceChildren();if(!items.length){grid.append(element('div','empty','Nenhum produto com vídeo encontrado para esta busca.'));return}for(const p of items){const card=element('article','card'),cover=element('button','cover');cover.type='button';cover.setAttribute('aria-label',`Assistir vídeo: ${p.nome}`);const img=element('img');img.loading='lazy';img.alt=p.nome;const image=safeUrl(p.imagem_principal);if(image)img.src=image;else img.hidden=true;img.onerror=()=>{img.hidden=true};cover.append(img,element('span','play','▶'));cover.onclick=()=>openVideo(p,cover);const body=element('div','cardbody'),title=element('button','title',p.nome);title.type='button';title.onclick=()=>openVideo(p,title);const details=element('div','details');details.append(element('span','price',money.format(Number(p.preco))),element('span','stock'+(Number(p.estoque_total)<=0?' zero':''),`Estoque: ${p.estoque_total}`));const actions=element('div','actions'),cp=element('button','','Copiar link'),dl=element('a','btn primary','↓ Baixar vídeo');cp.type='button';cp.title='Copiar link do produto na loja';cp.disabled=!safeUrl(p.link);cp.onclick=()=>copy(p.link);dl.href=downloadUrl(p);dl.addEventListener('click',()=>notice('Preparando download. Aguarde a confirmação do navegador.'));actions.append(cp,dl);body.append(title,element('div','small',`Código ${p.codigo_pai}`),details,actions);card.append(cover,body);grid.append(card)}}
function fillSelect(select,values,label){const old=select.value;select.replaceChildren(new Option(label,''));for(const value of values)select.add(new Option(value,value));select.value=values.includes(old)?old:''}
function updateSubcategories(){const cat=$('#category').value;const values=[...new Set(filterPairs.filter(p=>!cat||p.categoria===cat).map(p=>p.subcategoria).filter(v=>v&&v.trim()))].sort((a,b)=>a.localeCompare(b,'pt-BR'));fillSelect($('#subcategory'),values,'Todas as subcategorias')}
function updatePagination(){const choice=$('#pageSelect');choice.replaceChildren();for(let n=1;n<=pages;n++)choice.add(new Option(String(n),String(n),false,n===page));choice.disabled=false;$('#pages').textContent=`de ${pages}`;$('#first').disabled=$('#prev').disabled=page<=1;$('#last').disabled=$('#next').disabled=page>=pages}
async function load(){controller?.abort();controller=new AbortController();$('#grid').setAttribute('aria-busy','true');for(const id of ['first','prev','next','last','pageSelect'])$('#'+id).disabled=true;$('#count').textContent='Carregando produtos…';const u=new URL(location.href);u.search='';u.searchParams.set('action','products');u.searchParams.set('page',page);u.searchParams.set('q',$('#query').value);u.searchParams.set('stock',$('#stock').checked?'1':'0');u.searchParams.set('category',$('#category').value);u.searchParams.set('subcategory',$('#subcategory').value);if(!filtersLoaded)u.searchParams.set('filters','1');try{const r=await fetch(u,{signal:controller.signal});const data=await r.json();if(!r.ok)throw Error(data.error||'Não foi possível carregar.');items=data.products;page=data.page;pages=data.pages;if(Array.isArray(data.filters)){filterPairs=data.filters;filtersLoaded=true;const categories=[...new Set(filterPairs.map(p=>p.categoria).filter(v=>v&&v.trim()))].sort((a,b)=>a.localeCompare(b,'pt-BR'));fillSelect($('#category'),categories,'Todas as categorias');updateSubcategories()}render();const first=data.total?((page-1)*data.size+1):0;const last=Math.min(page*data.size,data.total);$('#count').textContent=`${data.total} produtos com vídeo · Mostrando ${first}–${last} · 100 por página`;updatePagination();$('#grid').setAttribute('aria-busy','false')}catch(e){if(e.name==='AbortError')return;$('#count').textContent='Falha ao carregar';$('#grid').replaceChildren();const box=element('div','empty',e.message+' '),retry=element('button','','Tentar novamente');retry.onclick=load;box.append(retry);$('#grid').append(box);$('#grid').setAttribute('aria-busy','false')}}
function goToPage(target){page=Math.max(1,Math.min(pages,target));load();window.scrollTo({top:0,behavior:'smooth'})}
$('#search').onsubmit=e=>{e.preventDefault();page=1;load()};$('#stock').onchange=()=>{page=1;load()};$('#category').onchange=()=>{$('#subcategory').value='';updateSubcategories();page=1;load()};$('#subcategory').onchange=()=>{page=1;load()};$('#first').onclick=()=>goToPage(1);$('#prev').onclick=()=>goToPage(page-1);$('#next').onclick=()=>goToPage(page+1);$('#last').onclick=()=>goToPage(pages);$('#pageSelect').onchange=()=>goToPage(Number($('#pageSelect').value));load();

</script>
<?php endif; ?></body></html>