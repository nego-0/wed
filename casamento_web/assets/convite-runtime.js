(function(){
  'use strict';
  var raiz=document.getElementById('kulemba-runtime-config');
  var cfg={};
  if(raiz){try{cfg=JSON.parse(raiz.textContent||'{}');}catch(e){cfg={};}}
  var reduzido=!!(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  function emitir(nome,detalhe,cancelavel){
    var ev=new CustomEvent('kulemba:'+nome,{detail:detalhe||{},bubbles:true,cancelable:!!cancelavel});
    document.dispatchEvent(ev);return ev;
  }
  function um(sel,base){return (base||document).querySelector(sel)}
  function todos(sel,base){return Array.prototype.slice.call((base||document).querySelectorAll(sel))}
  function escut(el,evento,fn,opt){if(el)el.addEventListener(evento,fn,opt)}
  function focoPossivel(base){return todos('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),iframe,[tabindex]:not([tabindex="-1"])',base).filter(function(el){return !el.hidden&&el.getAttribute('aria-hidden')!=='true';})}

  /* A composição editorial usa a largura real da capa. */
  var hero=um('#hero .frame');
  if(document.body.dataset.estilo==='kulemba'&&hero){
    var medir=function(){hero.style.setProperty('--uw',hero.offsetWidth/100+'px');hero.style.setProperty('--uh',hero.offsetHeight/100+'px');};
    medir();if(window.ResizeObserver)new ResizeObserver(medir).observe(hero);else escut(window,'resize',medir);
  }

  /* Música, capa e rolagem partilham eventos públicos. */
  var audioBtn=um('[data-kulemba="audio"],#audioBtn'),audio=um('#bgAudio'),tocando=false;
  function estadoAudio(v){tocando=v;if(audioBtn)audioBtn.classList.toggle('playing',v);emitir(v?'audio-tocar':'audio-pausar',{audio:audio});}
  function tocar(){if(!audio)return;var p=audio.play();if(p&&p.then)p.then(function(){estadoAudio(true);}).catch(function(){estadoAudio(false);});else estadoAudio(true);}
  function pausar(){if(!audio)return;try{audio.pause();}catch(e){}estadoAudio(false);}
  if(audio&&audioBtn){audio.volume=.55;audioBtn.style.display='flex';escut(audio,'play',function(){estadoAudio(true);});escut(audio,'pause',function(){estadoAudio(false);});escut(audioBtn,'click',function(){tocando?pausar():tocar();});}

  var rolagemId=0,rolagemAnterior=0,rolagemPausaAte=0;
  function passoRolagem(agora){
    if(!rolagemAnterior)rolagemAnterior=agora;
    var dt=Math.min(50,agora-rolagemAnterior);rolagemAnterior=agora;
    if(agora>=rolagemPausaAte)window.scrollBy(0,(Number(cfg.rolagem&&cfg.rolagem.velocidade)||28)*dt/1000);
    if(window.scrollY+window.innerHeight<document.documentElement.scrollHeight-2)rolagemId=requestAnimationFrame(passoRolagem);else rolagemId=0;
  }
  function iniciarRolagem(){if(reduzido||rolagemId||!(cfg.rolagem&&cfg.rolagem.activa))return;rolagemAnterior=0;rolagemId=requestAnimationFrame(passoRolagem);emitir('rolagem-iniciar');}
  function pausarRolagem(){rolagemPausaAte=performance.now()+4500;emitir('rolagem-pausar');}
  ['wheel','touchstart','pointerdown','keydown'].forEach(function(ev){escut(window,ev,pausarRolagem,{passive:true});});

  var cover=um('[data-kulemba="capa"],#cover');
  function abrirCapa(){
    if(!cover||cover.classList.contains('open'))return;
    var antes=emitir('capa-abrir',{capa:cover},true);if(antes.defaultPrevented)return;
    cover.classList.add('open');document.body.classList.add('opened');
    if(cfg.autoplay)tocar();iniciarRolagem();
  }
  if(cover){escut(cover,'click',abrirCapa);escut(cover,'keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();abrirCapa();}});}

  /* Pétalas e entrada progressiva respeitam movimento reduzido. */
  if(!reduzido&&cfg.petalas&&cfg.petalas.activo){
    var pc=um('#petals'),cores=cfg.petalas.cores||[];
    if(pc)for(var i=0;i<14;i++){
      var p=document.createElement('div'),s=10+Math.random()*16,dur=12+Math.random()*12,col=cores[i%Math.max(1,cores.length)]||'#d9bc8c';
      p.className='petal';p.style.width=s+'px';p.style.height=(s*1.25)+'px';p.style.left=(Math.random()*100)+'vw';p.style.animationDuration=dur+'s';p.style.animationDelay=(-Math.random()*dur)+'s';
      p.innerHTML='<svg viewBox="0 0 20 26"><path d="M10 0C4 6 0 12 0 17c0 5 4 9 10 9s10-4 10-9C20 12 16 6 10 0z" fill="'+col+'" opacity="0.9"/><path d="M10 2C10 8 10 20 10 26" stroke="rgba(0,0,0,0.06)" stroke-width="0.6" fill="none"/></svg>';pc.appendChild(p);
    }
  }
  var revelados=todos('.rv,.story-photo,.timeline,.thread');
  if(reduzido||!window.IntersectionObserver)revelados.forEach(function(el){el.classList.add('in');});
  else{
    var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target);}});},{threshold:.16});revelados.forEach(function(el){io.observe(el);});
    var ioV=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){e.target.classList.add('in-view');ioV.unobserve(e.target);}});},{threshold:.2});todos('.page').forEach(function(el){ioV.observe(el);});
  }

  /* Contagem e calendário. */
  var alvo=cfg.evento&&cfg.evento.data?new Date(cfg.evento.data).getTime():NaN;
  var eD=um('#cd-d'),eH=um('#cd-h'),eM=um('#cd-m'),eS=um('#cd-s');
  function pad(n){return n<10?'0'+n:String(n)}
  function tick(){if(!eD||!isFinite(alvo))return;var d=alvo-Date.now();if(d<=0){eD.textContent='0';eH.textContent=eM.textContent=eS.textContent='00';return;}eD.textContent=Math.floor(d/86400000);eH.textContent=pad(Math.floor(d/3600000)%24);eM.textContent=pad(Math.floor(d/60000)%60);eS.textContent=pad(Math.floor(d/1000)%60);setTimeout(tick,1000);}tick();
  todos('[data-kulemba="calendario"],#calBtn').forEach(function(botao){escut(botao,'click',function(e){
    e.preventDefault();var cal=cfg.calendario||{},linhas=cal.linhas||[];if(!linhas.length)return;
    emitir('calendario-descarregar',{ficheiro:cal.ficheiro});var blob=new Blob([linhas.join('\r\n')],{type:'text/calendar'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=cal.ficheiro||'casamento.ics';document.body.appendChild(a);a.click();a.remove();setTimeout(function(){URL.revokeObjectURL(url);},1500);
  });});

  /* Mapas mantêm o destino do modelo e anunciam a acção ao runtime. */
  todos('[data-kulemba="mapa"]').forEach(function(link){escut(link,'click',function(){emitir('mapa-abrir',{url:link.href,elemento:link});});});

  /* QR de entrada e QR da página de presentes usam o mesmo gerador local. */
  function qr(canvas,valor,cor,fundo){if(!canvas||!valor||!window.QRious)return;try{new QRious({element:canvas,value:valor,size:Number(canvas.getAttribute('width'))||320,level:'M',foreground:cor||'#16261e',background:fundo||'#fff'});emitir('qr-gerado',{canvas:canvas,valor:valor});}catch(e){emitir('erro',{recurso:'qr',erro:e});}}
  todos('canvas[data-kulemba-qr]').forEach(function(c){qr(c,c.getAttribute('data-kulemba-qr'),c.getAttribute('data-qr-cor'),c.getAttribute('data-qr-fundo'));});
  todos('canvas[data-presente-qr]').forEach(function(c){qr(c,c.getAttribute('data-presente-qr'),c.getAttribute('data-qr-cor')||(cfg.qr&&cfg.qr.cor),'#fff');});

  /* Galeria opcional: qualquer modelo monta-a só com atributos semânticos. */
  var itens=todos('[data-kulemba-galeria-item]');
  if(itens.length){
    var lb=document.createElement('div');lb.className='kulemba-galeria-lightbox';lb.setAttribute('role','dialog');lb.setAttribute('aria-modal','true');lb.setAttribute('aria-label','Fotografia ampliada');lb.innerHTML='<button type="button" aria-label="Fechar fotografia">×</button><img alt="">';document.body.appendChild(lb);
    var fecharGaleria=function(){lb.classList.remove('aberta');emitir('galeria-fechar');};escut(um('button',lb),'click',fecharGaleria);escut(lb,'click',function(e){if(e.target===lb)fecharGaleria();});
    itens.forEach(function(item){escut(item,'click',function(e){e.preventDefault();var img=item.matches('img')?item:um('img',item),src=item.getAttribute('href')||(img&&img.currentSrc)||(img&&img.src);if(!src)return;var dest=um('img',lb);dest.src=src;dest.alt=(img&&img.alt)||'Fotografia do casamento';lb.classList.add('aberta');um('button',lb).focus();emitir('galeria-abrir',{src:src});});});
  }

  /* Confirmação comum em modal, com conteúdo remoto ou alvo local de demonstração. */
  var modal=null,ultimoFoco=null,conteudoLocal=null,origemLocal=null,inertes=[];
  function criarModal(){
    if(modal)return modal;modal=document.createElement('div');modal.className='kulemba-modal';modal.setAttribute('aria-hidden','true');
    modal.innerHTML='<section class="kulemba-modal__painel" role="dialog" aria-modal="true" aria-labelledby="kulemba-modal-titulo"><header class="kulemba-modal__cabecalho"><span class="kulemba-modal__titulo" id="kulemba-modal-titulo">Confirmar presença</span><button class="kulemba-modal__fechar" type="button" aria-label="Fechar confirmação"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button></header><div class="kulemba-modal__corpo"></div></section>';
    document.body.appendChild(modal);escut(um('.kulemba-modal__fechar',modal),'click',fecharConfirmacao);escut(modal,'mousedown',function(e){if(e.target===modal)fecharConfirmacao();});
    escut(modal,'keydown',function(e){if(e.key==='Escape'){e.preventDefault();fecharConfirmacao();return;}if(e.key!=='Tab')return;var fs=focoPossivel(modal);if(!fs.length)return;var a=fs[0],z=fs[fs.length-1];if(e.shiftKey&&document.activeElement===a){e.preventDefault();z.focus();}else if(!e.shiftKey&&document.activeElement===z){e.preventDefault();a.focus();}});return modal;
  }
  function urlModal(href){try{var u=new URL(href,location.href);u.searchParams.set('modal','1');return u.href;}catch(e){return href;}}
  function abrirConfirmacao(gatilho){
    var m=criarModal(),corpo=um('.kulemba-modal__corpo',m),href=gatilho&&gatilho.getAttribute('href')||'';
    var antes=emitir('confirmacao-abrir',{gatilho:gatilho,href:href},true);if(antes.defaultPrevented)return;
    ultimoFoco=gatilho||document.activeElement;corpo.innerHTML='';conteudoLocal=null;origemLocal=null;
    if(href.charAt(0)==='#'&&href!=='#confirmar'){
      var alvo=um(href);if(alvo){origemLocal={pai:alvo.parentNode,proximo:alvo.nextSibling,hidden:alvo.hidden};conteudoLocal=alvo;alvo.hidden=false;alvo.classList.add('kulemba-modal__local');corpo.appendChild(alvo);}
    }else{
      var frame=document.createElement('iframe');frame.title='Formulário de confirmação de presença';frame.src=urlModal(href);frame.setAttribute('loading','eager');corpo.appendChild(frame);
    }
    m.setAttribute('aria-hidden','false');document.body.classList.add('kulemba-modal-aberto');
    inertes=[];todos('body > *').forEach(function(el){if(el===m||el.tagName==='SCRIPT'||el.tagName==='STYLE')return;inertes.push({el:el,ja:el.hasAttribute('inert')});el.setAttribute('inert','');});
    if(location.hash!=='#confirmar'&&history.pushState)history.pushState({kulembaConfirmacao:true},'',location.pathname+location.search+'#confirmar');
    setTimeout(function(){um('.kulemba-modal__fechar',m).focus();},0);
  }
  function fecharConfirmacao(){
    if(!modal||modal.getAttribute('aria-hidden')==='true')return;var corpo=um('.kulemba-modal__corpo',modal);
    if(conteudoLocal&&origemLocal){conteudoLocal.classList.remove('kulemba-modal__local');conteudoLocal.hidden=origemLocal.hidden;if(origemLocal.proximo)origemLocal.pai.insertBefore(conteudoLocal,origemLocal.proximo);else origemLocal.pai.appendChild(conteudoLocal);}
    corpo.innerHTML='';modal.setAttribute('aria-hidden','true');document.body.classList.remove('kulemba-modal-aberto');inertes.forEach(function(x){if(!x.ja)x.el.removeAttribute('inert');});inertes=[];emitir('confirmacao-fechar');
    if(location.hash==='#confirmar'&&history.replaceState)history.replaceState({},'',location.pathname+location.search);
    if(ultimoFoco&&ultimoFoco.focus)ultimoFoco.focus();
  }
  var gatilhos=todos('[data-kulemba="confirmacao"]');gatilhos.forEach(function(g){escut(g,'click',function(e){e.preventDefault();abrirConfirmacao(g);});});
  if(location.hash==='#confirmar'&&gatilhos[0])setTimeout(function(){abrirConfirmacao(gatilhos[0]);},0);
  escut(window,'hashchange',function(){if(location.hash==='#confirmar'&&gatilhos[0])abrirConfirmacao(gatilhos[0]);else if(modal&&modal.getAttribute('aria-hidden')==='false')fecharConfirmacao();});
  escut(window,'message',function(e){if(e.data&&e.data.tipo==='kulemba:rsvp-concluido'){emitir('confirmacao-concluida',e.data);}});

  window.KulembaConvite={config:cfg,eventos:emitir,abrirCapa:abrirCapa,iniciarRolagem:iniciarRolagem,abrirConfirmacao:function(){if(gatilhos[0])abrirConfirmacao(gatilhos[0]);},fecharConfirmacao:fecharConfirmacao};
  emitir('pronto',{runtime:window.KulembaConvite});
})();
