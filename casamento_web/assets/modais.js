/* Os modais antigos e novos partilham o viewport visual, sem alterar as APIs. */
(function () {
  'use strict';
  const seletor='.overlay,.modal-fundo,.pl-modal,.ov-modelo,.ft-lente,.vs-jan,.esp-aviso,#lightbox';
  let pendente=false;
  function tamanho(){
    const v=window.visualViewport, r=document.documentElement.style;
    r.setProperty('--modal-altura',(v?v.height:window.innerHeight)+'px');
    r.setProperty('--modal-largura',(v?v.width:window.innerWidth)+'px');
    r.setProperty('--modal-topo',(v?v.offsetTop:0)+'px');
    r.setProperty('--modal-esquerda',(v?v.offsetLeft:0)+'px');
  }
  function actualizar(){
    pendente=false; let aberto=false;
    document.querySelectorAll(seletor).forEach(el=>{
      if(el.hidden || getComputedStyle(el).display==='none')return;
      // Escapa a contextos de empilhamento criados por contentores transformados.
      if(el.parentElement!==document.body)document.body.appendChild(el);
      aberto=true;
    });
    document.body.classList.toggle('modal-em-uso',aberto); tamanho();
  }
  function agendar(){if(!pendente){pendente=true;requestAnimationFrame(actualizar);}}
  const obs=new MutationObserver(ms=>{if(ms.some(m=>m.type==='childList'||m.target.matches(seletor)))agendar();});
  obs.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['class','style','hidden']});
  window.addEventListener('resize',tamanho);
  if(window.visualViewport){visualViewport.addEventListener('resize',tamanho);visualViewport.addEventListener('scroll',tamanho);}
  actualizar();
})();
