(function(){
  'use strict';
  const busca=document.getElementById('aj-busca'),cards=[...document.querySelectorAll('.aj-card')];if(!busca||!cards.length)return;
  const botoes=[...document.querySelectorAll('[data-aj-modulo]')],estado=document.getElementById('aj-resultado'),vazio=document.getElementById('aj-sem-resultados');let modulo='todos';
  const DURACAO_CATEGORIA=1100;let animacaoRolagem=0,temporizadorEntrada=0;
  const limpar=s=>(s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
  function filtrar(){const termos=limpar(busca.value).split(/\s+/).filter(Boolean);let n=0;cards.forEach(c=>{const texto=limpar(c.dataset.pesquisa),ver=(modulo==='todos'||c.dataset.modulo===modulo)&&termos.every(t=>texto.includes(t));c.hidden=!ver;if(ver)n++;});vazio.hidden=n!==0;estado.textContent=n+(n===1?' tópico encontrado':' tópicos encontrados');}
  function rolarAte(elemento){
    cancelAnimationFrame(animacaoRolagem);
    const reduzido=matchMedia('(prefers-reduced-motion: reduce)').matches;
    const inicio=window.scrollY;
    const margem=parseFloat(getComputedStyle(elemento).scrollMarginTop)||0;
    const maximo=Math.max(0,document.documentElement.scrollHeight-innerHeight);
    const destino=Math.min(maximo,Math.max(0,inicio+elemento.getBoundingClientRect().top-margem));
    const distancia=destino-inicio;
    if(reduzido||Math.abs(distancia)<2){window.scrollTo(0,destino);return;}
    const comeco=performance.now();
    const mover=agora=>{
      const t=Math.min(1,(agora-comeco)/DURACAO_CATEGORIA);
      const suave=t<.5?4*t*t*t:1-Math.pow(-2*t+2,3)/2;
      window.scrollTo(0,inicio+distancia*suave);
      if(t<1)animacaoRolagem=requestAnimationFrame(mover);
    };
    animacaoRolagem=requestAnimationFrame(mover);
  }
  function alinharPrimeiroRecurso(){
    const primeiro=cards.find(c=>!c.hidden);if(!primeiro)return;
    const alvo=document.body.classList.contains('central-publica')
      ? document.querySelector('.aj-forum-topo')||primeiro : primeiro;
    clearTimeout(temporizadorEntrada);cards.forEach(c=>c.classList.remove('aj-entrada-categoria'));
    requestAnimationFrame(()=>{
      void primeiro.offsetWidth;
      cards.filter(c=>!c.hidden).forEach(c=>c.classList.add('aj-entrada-categoria'));
      rolarAte(alvo);
      temporizadorEntrada=setTimeout(()=>cards.forEach(c=>c.classList.remove('aj-entrada-categoria')),DURACAO_CATEGORIA+100);
    });
  }
  botoes.forEach(b=>b.addEventListener('click',()=>{modulo=b.dataset.ajModulo;botoes.forEach(x=>x.classList.toggle('ativo',x===b));filtrar();alinharPrimeiroRecurso();}));
  busca.addEventListener('input',filtrar);document.addEventListener('keydown',e=>{if(e.key==='/'&&!/input|textarea|select/i.test(document.activeElement.tagName)){e.preventDefault();busca.focus();}});
  document.querySelectorAll('.aj-card>details').forEach(d=>d.addEventListener('toggle',()=>{if(!d.open)return;document.querySelectorAll('.aj-card>details[open]').forEach(outro=>{if(outro!==d)outro.open=false;});}));
  document.querySelectorAll('.aj-passo').forEach(d=>d.addEventListener('toggle',()=>{
    if(!d.open)return;
    d.closest('.aj-passos')?.querySelectorAll('.aj-passo[open]').forEach(outro=>{if(outro!==d)outro.open=false;});
    const guia=d.closest('.aj-guia'),passo=d.dataset.passo;
    const imagem=guia?.querySelector('.aj-imagem'),cena=guia?.querySelector(`[data-cena-passo="${passo}"]`),anterior=imagem?.querySelector('.aj-cena.ativo');
    const gesto=guia?.querySelector(`[data-scroll-passo="${passo}"]`);
    if(imagem&&cena&&cena!==anterior){
      clearTimeout(imagem._ajCenaTimer);imagem.classList.remove('rolando-d','rolando-m');
      imagem.querySelectorAll('.aj-cena').forEach(x=>x.classList.remove('anterior'));
      anterior?.classList.add('anterior');anterior?.classList.remove('ativo');cena.classList.add('ativo');
      void imagem.offsetWidth;
      if(gesto?.classList.contains('rolagem-d'))imagem.classList.add('rolando-d');
      if(gesto?.classList.contains('rolagem-m'))imagem.classList.add('rolando-m');
      imagem._ajCenaTimer=setTimeout(()=>{anterior?.classList.remove('anterior');imagem.classList.remove('rolando-d','rolando-m');},1180);
    }
    guia?.querySelectorAll('[data-sticker-passo]').forEach(s=>s.classList.toggle('ativo',s.dataset.stickerPasso===passo));
    guia?.querySelectorAll('[data-scroll-passo]').forEach(s=>s.classList.toggle('ativo',s.dataset.scrollPasso===passo));
    const legenda=guia?.querySelector('[data-aj-legenda]'),titulo=d.querySelector('summary b');
    if(legenda&&titulo)legenda.textContent=titulo.textContent;
    const marca=guia?.querySelector(`.aj-sticker-marca[data-sticker-passo="${passo}"] img`),icone=guia?.querySelector('[data-aj-sticker-legenda]');
    if(marca&&icone)icone.src=marca.src;
  }));
  document.querySelectorAll('.aj-guia').forEach(guia=>{const marca=guia.querySelector('.aj-sticker-marca.ativo img'),icone=guia.querySelector('[data-aj-sticker-legenda]');if(marca&&icone)icone.src=marca.src;});
  filtrar();
})();
