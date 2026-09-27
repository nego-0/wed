(function(){
  'use strict';
  const busca=document.getElementById('aj-busca'),cards=[...document.querySelectorAll('.aj-card')];if(!busca||!cards.length)return;
  const botoes=[...document.querySelectorAll('[data-aj-modulo]')],estado=document.getElementById('aj-resultado'),vazio=document.getElementById('aj-sem-resultados');let modulo='todos';
  const limpar=s=>(s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
  function filtrar(){const termos=limpar(busca.value).split(/\s+/).filter(Boolean);let n=0;cards.forEach(c=>{const texto=limpar(c.dataset.pesquisa),ver=(modulo==='todos'||c.dataset.modulo===modulo)&&termos.every(t=>texto.includes(t));c.hidden=!ver;if(ver)n++;});vazio.hidden=n!==0;estado.textContent=n+(n===1?' guia encontrado':' guias encontrados');}
  botoes.forEach(b=>b.addEventListener('click',()=>{modulo=b.dataset.ajModulo;botoes.forEach(x=>x.classList.toggle('ativo',x===b));filtrar();}));busca.addEventListener('input',filtrar);document.addEventListener('keydown',e=>{if(e.key==='/'&&!/input|textarea|select/i.test(document.activeElement.tagName)){e.preventDefault();busca.focus();}});
  const reduzir=matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.querySelectorAll('[data-aj-sticker]').forEach((sticker,indice)=>{const passos=[...sticker.querySelectorAll('li')],titulo=sticker.querySelector('.aj-sticker-topo b');if(passos.length<2||reduzir)return;let actual=0;setInterval(()=>{passos[actual].classList.remove('ativo');actual=(actual+1)%passos.length;passos[actual].classList.add('ativo');if(titulo)titulo.textContent=passos[actual].dataset.operacao||'Como fazer';sticker.style.setProperty('--passo',actual);},2200+indice*90);});filtrar();
})();
