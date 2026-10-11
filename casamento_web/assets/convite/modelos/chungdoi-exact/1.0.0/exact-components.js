/* Secções partilhadas, com a identidade visual do documento que as recebe. */
(function(){
  'use strict';
  var cfg=(window.KulembaConvite||{}).config||{},d=cfg.exacto||{};
  function all(s,b){return Array.from((b||document).querySelectorAll(s));}
  function one(s,b){return (b||document).querySelector(s);}
  function esc(s){var e=document.createElement('span');e.textContent=String(s||'');return e.innerHTML.replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
  function icon(key){return '<svg viewBox="0 0 24 24" aria-hidden="true">'+((d.icones||{})[key]||(d.icones||{}).coracao||'')+'</svg>';}
  function text(p){return p.textoHtml||esc(p.texto);}
  function items(p){return (p.itens||[]).map(function(i){return '<article>'+ (i.icone?'<span class="exact-gift-icon">'+icon(i.icone)+'</span>':'')+(i.titulo?'<h3>'+esc(i.titulo)+'</h3>':'')+'<div class="exact-richtext">'+text(i)+'</div></article>';}).join('');}
  function photo(role){var p=(d.fotos||{})[role];if(!p||!p.src)return '';var e=p.enquadramento||{},x=Number.isFinite(Number(e.x))?Number(e.x):50,y=Number.isFinite(Number(e.y))?Number(e.y):50,z=Math.max(1,(Number(e.zoom)||100)/100);return '<figure class="exact-semantic-photo exact-semantic-photo--'+role+'"><img data-media="media.'+role+'" src="'+esc(p.src)+'" alt="'+esc(d.noiva+' e '+d.noivo)+'" loading="lazy" decoding="async" style="object-position:'+x+'% '+y+'%;transform:scale('+z+')"></figure>';}
  function stage(){var c=one('[data-sec="grande-dia"]');return c?c.parentElement:one('[data-showcase-root]');}
  function scene(id,p,body){var root=stage();if(!root||one('[data-sec="'+id+'"]',root))return null;var s=document.createElement('section');s.className='exact-semantic-host exact-semantic-scene exact-component';s.dataset.sec=id;s.innerHTML=(p.eyebrow?'<p class="exact-component-eyebrow">'+esc(p.eyebrow)+'</p>':'')+(p.titulo?'<h2>'+esc(p.titulo)+'</h2>':'')+body;root.appendChild(s);return s;}
  function prepare(){
    var root=one('[data-showcase-root]');if(!root)return;
    var cs=getComputedStyle(root),palette={
      'jasmine-white':['#404a1d','#fffaf7','Baskerville, "Times New Roman", serif','"The Nautigal", cursive'],
      'royal-v2-green':['#e5e4d0','#10261c','Baskerville, "Times New Roman", serif','"The Nautigal", cursive'],
      'spring-garden-blue':['#486c7d','#ffffff','"Noto Serif", "Times New Roman", serif','"The Nautigal", cursive'],
      'double-happiness-green':['#1f3a25','#fff7eb','"Big Caslon", Baskerville, serif','"Big Caslon", Baskerville, serif'],
      'porcelain-brown':['#8d6f32','#fcfbf8','"Times New Roman", serif','"Viaoda Libre", cursive'],
      'royal-blue':['#e1bc7c','#00112e','"Big Caslon", Baskerville, serif','"The Nautigal", cursive'],
      'porcelain-v2-green':['#464f1e','#fff7eb','"Times New Roman", serif','"Times New Roman", serif'],
      'hoa-kho-orange':['#4c611b','#fdf7ed','"Times New Roman", serif','"Times New Roman", serif'],
      'mahal-gold':['#ab7a45','#f7e3cd','Baskerville, "Times New Roman", serif','"The Nautigal", cursive'],
      'porcelain-v2-pink':['#776e38','#fff7eb','"Times New Roman", serif','Carattere, "The Nautigal", cursive'],
      'lien-hoa-pink':['#804b5b','#fefefe','Baskerville, "Times New Roman", serif','"The Nautigal", cursive']
    }[d.modelo]||[cs.color,cs.backgroundColor,cs.fontFamily,cs.fontFamily];
    var accent=d.modelo==='porcelain-v2-pink'?'#dc8599':palette[0];
    [['--exact-ink',palette[0]],['--exact-paper',palette[1]],['--exact-accent',accent],['--exact-title-font',palette[2]],['--exact-name-font',palette[3]],['--exact-dialog-accent',palette[0]]].forEach(function(v){document.body.style.setProperty(v[0],v[1]);});
    // As variáveis servem apenas as secções opcionais acrescentadas pelo
    // editor. O documento nativo conserva as suas próprias cores, famílias
    // tipográficas e textos: não se repinta o convite inteiro por herança.
    // A Porcelana V2 Rosa define o conteúdo canónico, mas nenhum bloco nativo
    // é dado como concluído antes de o runtime o preencher. Alguns modelos
    // usam esta classe apenas como estrutura visual e ainda contêm o texto de
    // origem; marcá-los aqui fazia a normalização saltar toda a cerimónia.
    // Cada estado musical aceita a escolha feita no editor.
    var b=one('#audioBtn');if(b){b.innerHTML='<span class="kvp-audio-disc" aria-hidden="true"></span><span class="exact-audio-play">'+icon(d.iconeMusicaTocar||'musica')+'</span><span class="exact-audio-pause">'+icon(d.iconeMusicaPausa||'pausa')+'</span>';}
  }
  function extras(){
    var visible=d.visibilidade||{},h=d.historia||{},i=d.interludio||{},m=d.manual||{};
    if(visible.historia)scene('historia',h,photo('historia')+'<blockquote class="exact-richtext">'+text(h)+'</blockquote>'+(h.autor?'<small>'+esc(h.autor)+'</small>':'')+'<div class="exact-chapters">'+items(h)+'</div>');
    if(visible.interludio)scene('interludio',{},photo('interludio')+'<blockquote class="exact-richtext">'+text(i)+'</blockquote>'+(i.autor?'<small>'+esc(i.autor)+'</small>':'')+'<div class="exact-richtext">'+(i.fechoHtml||'')+'</div>');
    if(visible.manual)scene('manual',m,'<div class="exact-richtext">'+text(m)+'</div><div class="exact-message-list">'+items(m)+'</div>');
    (d.blocos||[]).filter(function(b){return !['galeria','presentes','mensagens'].includes(b.tipo);}).forEach(function(b){scene(b.id,b,'<div class="exact-richtext">'+text(b)+'</div><div class="exact-message-list">'+items(b)+'</div>');});
    // A galeria incorporada adopta o conteúdo da secção que o editor adicionou.
    var g=(d.blocos||[]).find(function(b){return b.tipo==='galeria';}),gal=one('.exact-gallery');
    if(!gal){var gh=all('h2').find(function(e){return e.textContent.trim()==='Galeria de fotografias'&&!e.closest('dialog');});if(gh)gal=gh.closest('section')||gh.parentElement;}
    if(gal&&!gal.dataset.sec)gal.dataset.sec='galeria';
    if(g&&gal){gal.dataset.sec=g.id;var eyebrow=one(':scope > p',gal),title=one('h2',gal);if(eyebrow)eyebrow.textContent=g.eyebrow||'';if(title)title.textContent=g.titulo||'';}
    var ceremony=one('.exact-semantic-ceremony');if(ceremony)ceremony.dataset.cerimonias=String(all('.kvp-cer-card',ceremony).length);
    var reception=one('.exact-semantic-reception');if(reception){
      var calendar=document.createElement('button');calendar.type='button';calendar.dataset.kulemba='calendario';calendar.className='exact-calendar';calendar.innerHTML=icon('calendario')+'Adicionar ao calendário';reception.appendChild(calendar);
    }
    all('.exact-semantic-program').forEach(function(p){if(visible.cronograma===false){var host=p.closest('[data-sec]');if(host)host.remove();}});
    // Ordena dentro da composição original, conservando a capa, os ornamentos,
    // o rodapé e os modais fora deste grupo de conteúdo.
    var root=stage(),secs=root?all(':scope > [data-sec]',root):[],order=d.ordem||[];
    function rank(id){var i=order.indexOf(id);if(i>=0)return i;var base={recepcao:'grande-dia',cronograma:'grande-dia',galeria:'interludio',confirmacao:'final'}[id],bi=order.indexOf(base);return bi>=0?bi+(id==='recepcao'?.1:id==='cronograma'?.2:.5):order.length-1;}
    if(root&&secs.length){var anchor=document.createComment('secções editáveis');secs[0].before(anchor);secs.sort(function(a,b){return rank(a.dataset.sec)-rank(b.dataset.sec);});secs.forEach(function(s){anchor.before(s);});anchor.remove();}
  }
  window.KulembaExactComponents={prepare:prepare,extras:extras,icon:icon};
})();
