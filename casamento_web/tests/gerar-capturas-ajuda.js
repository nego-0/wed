// Recria as capturas responsivas da Ajuda a partir das páginas reais.
// Requer uma instalação local com dados de demonstração e uma conta admin.
// BASE_URL, TEST_USER, TEST_PASSWORD e CHROMIUM podem ser definidos no ambiente.
const { chromium } = require('playwright-core');
const fs = require('node:fs'), path = require('node:path');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const USER = process.env.TEST_USER || 'admin';
const PASSWORD = process.env.TEST_PASSWORD;
const EXE = process.env.CHROMIUM || (process.platform === 'win32'
  ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
  : '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
const OUT = process.env.HELP_CAPTURE_OUT || path.join(__dirname,'..','assets','ajuda','capturas');
const OUT_DEMO = path.join(OUT,'demonstracao');
const SO_CENAS = process.env.HELP_CAPTURE_SCENES_ONLY === '1' ||
  process.env.HELP_CAPTURE_MODAL_ONLY === '1'; // nome antigo, mantido por compatibilidade
const SO_DISPOSITIVO = process.env.HELP_CAPTURE_DEVICE || '';
const SO_MODULO = process.env.HELP_CAPTURE_MODULE || '';
if(!PASSWORD) throw new Error('Defina TEST_PASSWORD com a senha da conta de testes.');
fs.mkdirSync(OUT,{recursive:true});
fs.mkdirSync(OUT_DEMO,{recursive:true});
const alvosGerados=[];
const alvosDemoGerados=[];

const modulos = [
  ['convidados',[
    ['/index.php',async p=>p.evaluate(()=>novoConvite())], ['/index.php',null]
  ]],
  ['mesas',[
    ['/mesas.php',async p=>p.evaluate(()=>{const d=document.getElementById('barra-add-dobra');if(d)d.open=true;})], ['/mesas.php',null]
  ]],
  ['impresso',[
    ['/editor-cartao.php',null], ['/manual.php?peca=cartao',null]
  ]],
  ['digital',[
    ['/convite-digital.php?demo=1',null], ['/convite.php?demo=1',null]
  ]],
  ['porta',[
    ['/porteiro.php',null], ['/porteiro.php',async p=>p.locator('#tab-ent').click()]
  ]],
  ['bar',[
    ['/bar.php',null], ['/copa.php',null]
  ]],
  ['orcamento',[
    ['/orcamento.php',async p=>p.evaluate(()=>abrirDespesa())], ['/orcamento.php',null]
  ]],
];

async function continuarEditor(p){
  const aviso=p.locator('.esp-ok:visible');
  if(await aviso.count()) await aviso.first().click();
  await p.locator('.esp-aviso.on').waitFor({state:'hidden'}).catch(()=>{});
}
async function centrarAlvo(p,selector){
  const alvo=p.locator(selector+':visible').first();
  await alvo.waitFor();
  await alvo.evaluate(e=>e.scrollIntoView({block:'center',inline:'nearest'}));
}

let DADOS={};
async function api(p,acao,corpo){
  return p.evaluate(async ({acao,corpo})=>{
    const op={headers:{'X-CSRF-Token':window.CSRF}};
    if(corpo!==undefined){op.method='POST';op.headers['Content-Type']='application/json';op.body=JSON.stringify(corpo);}
    return fetch('api.php?action='+acao,op).then(r=>r.json());
  },{acao,corpo});
}
async function apiImagemExemplo(p,acao,id,ficheiro){
  return p.evaluate(async ({acao,id,ficheiro})=>{
    const resposta=await fetch(ficheiro,{cache:'no-store'});
    if(!resposta.ok)throw new Error('Não foi possível abrir a imagem de exemplo: '+ficheiro);
    const blob=await resposta.blob(),fd=new FormData();
    fd.append('id',String(id));
    fd.append('ficheiro',new File([blob],ficheiro.split('/').pop(),{type:blob.type||'image/jpeg'}));
    return fetch('api.php?action='+acao,{method:'POST',headers:{'X-CSRF-Token':window.CSRF},body:fd}).then(r=>r.json());
  },{acao,id,ficheiro});
}
async function marcar(p,loc){
  await p.locator('[data-aj-alvo]').evaluateAll(ns=>ns.forEach(n=>n.removeAttribute('data-aj-alvo')));
  const alvo=loc.first();await alvo.waitFor();await alvo.evaluate(e=>{document.documentElement.style.scrollBehavior='auto';e.dataset.ajAlvo='1';e.scrollIntoView({block:'center',inline:'nearest'});});
  await p.waitForTimeout(180);return alvo;
}

// Os exemplos são criados pelas mesmas APIs da interface e identificados pelo
// nome. Assim o guião pode ser repetido sem acumular famílias, mesas, bebidas
// ou despesas, e cada captura mostra dados concretos em vez de caixas vazias.
async function prepararDados(p){
  // Reaplica a peça de origem que o admin tem actualmente publicada. A base
  // de capturas pode ter ficado presa a um modelo antigo, embora a galeria e a
  // demonstração já usem outro; nesse caso os stickers deixariam de retratar
  // aquilo que um novo casal recebe.
  for(const ambito of ['impresso','digital']){
    const catalogo=await api(p,'modelo_lista&ambito='+ambito);
    const origem=(catalogo.modelos||[]).find(m=>m.de_origem)||(catalogo.modelos||[]).find(m=>m.de_fabrica);
    if(origem){
      // Algumas bases de teste antigas baptizaram a peça padrão com os nomes
      // de um casal real. A galeria pública deve mostrar um nome de modelo e
      // reservar “Marta & Pedro” para a identidade fictícia do casamento.
      if(/isabel|abednego/i.test(origem.nome||'')){
        const renomeado=await api(p,'modelo_editar',{
          id:+origem.id,nome:'Peça padrão',descricao:origem.descricao||'',visivel:+origem.visivel?1:0
        });
        if(!renomeado.success)throw new Error('Não foi possível normalizar o nome do modelo de origem: '+(renomeado.error||''));
      }
      const aplicado=await api(p,'modelo_aplicar&id='+(+origem.id),{});
      if(!aplicado.success)throw new Error('Não foi possível aplicar o modelo de origem '+ambito+': '+(aplicado.error||''));
    }
  }
  // As capturas públicas usam sempre a identidade editorial configurada para
  // os dados de exemplo. Isto também limpa valores semânticos antigos que uma
  // base de testes possa ter deixado nos editores.
  const identidade=await api(p,'casamento_identidade',{
    nome:'Marta & Pedro',noiva:'Marta',noivo:'Pedro',data_evento:'2027-06-12'
  });
  if(!identidade.success)throw new Error('Não foi possível preparar a identidade de exemplo: '+(identidade.error||''));
  let lista=await api(p,'convite_list');
  let convite=(lista.convites||[]).find(c=>c.nome_exibicao==='Família Kiala');
  let mesas=await api(p,'mesa_list');
  let mesa=(mesas.mesas||[]).find(m=>m.nome==='Jacarandá');
  if(!mesa){const r=await api(p,'mesa_save',{id:0,nome:'Jacarandá',capacidade:8,forma:'oval',cor:'verde'});mesa=(r.mesas||[]).find(m=>m.nome==='Jacarandá');}
  const corpoConvite={id:0,nome_exibicao:'Família Kiala',tipo:'ambos',lado:'noiva',telefone:'+244 923 456 789',
    mesa:+mesa.id,mostrar_num_mesa:1,presenca:'confirmado',msg_pessoal:'Esperamos celebrar convosco.',
    membros:[{nome:'Ana Kiala',genero:'f',brinde:1,mesa_id:+mesa.id},{nome:'Mateus Kiala',genero:'m',brinde:1,mesa_id:+mesa.id}]};
  if(!convite){const salvo=await api(p,'convite_save',corpoConvite);convite=salvo.convite;}
  else convite=(await api(p,'convite_get&id='+(+convite.id))).convite;
  await api(p,'porta_checkin',{convite_id:+convite.id,modo:'anular'});

  // Um casamento com números plausíveis: o limite é 7,5 milhões e a soma
  // planeada fica abaixo dele, deixando uma margem legível no gráfico.
  await api(p,'orc_ajuste',{total:'7500000',moeda:'Kz'});
  const exemplosOrc=[
    ['Espaço e catering','2800000','#3F7254','Quinta e menu do casamento','Quinta das Acácias','2650000','1500000'],
    ['Fotografia','950000','#B4864A','Fotografia e vídeo','Luz do Sul','850000','300000'],
    ['Decoração','1200000','#8E6E97','Flores e decoração do salão','Atelier Jasmim','1100000','400000'],
    ['Música e som','650000','#477A8A','DJ, som e iluminação','Ritmo Eventos','580000','200000'],
    ['Vestuário','900000','#9A6959','Vestuário dos noivos','Casa Kiala','760000','300000'],
  ];
  let cat=null,desp=null,orc=await api(p,'orc_estado');
  for(const [nome,previsto,cor,descricao,fornecedor,valor,pago] of exemplosOrc){
    let c=(orc.categorias||[]).find(x=>x.nome===nome);
    const rc=await api(p,'orc_categoria_guardar',{id:c?+c.id:0,nome,previsto,cor});
    orc=await api(p,'orc_estado');c=(orc.categorias||[]).find(x=>+x.id===+rc.id);
    let d=(orc.despesas||[]).find(x=>x.descricao===descricao);
    const rd=await api(p,'orc_despesa_guardar',{id:d?+d.id:0,categoria_id:+c.id,descricao,fornecedor,valor,estado:'previsto',nota:'Pagamento acompanhado no calendário.'});
    orc=await api(p,'orc_estado');d=(orc.despesas||[]).find(x=>+x.id===+rd.id);
    if(!(orc.pagamentos||[]).some(x=>+x.despesa_id===+d.id))
      await api(p,'orc_pagamento_guardar',{despesa_id:+d.id,valor:pago,data_prevista:'2026-10-15',pago_em:'2026-09-20',nota:'Sinal'});
    if(nome==='Fotografia'){cat=c;desp=d;}
  }

  let bar=await api(p,'bar_estado');
  // As suites do Bar criam bebidas ZZ. Uma captura tem de mostrar apenas os
  // exemplos editoriais, mesmo quando é regenerada na mesma base de testes.
  for(const pedido of [...(bar.fila||[]),...(bar.resolvidos||[])]){
    if(!(pedido.itens||[]).some(i=>/^ZZ/i.test(i.nome)))continue;
    if(pedido.estado==='em_analise')await api(p,'bar_decidir',{id:+pedido.id,decisao:'recusar',motivo_texto:'Limpeza da captura'});
    else if(['aprovado','a_caminho','falhou'].includes(pedido.estado))await api(p,'bar_cancelar_copa',{id:+pedido.id});
  }
  for(const item of (bar.itens||[]).filter(i=>/^ZZ/i.test(i.nome)))await api(p,'bar_item_apagar',{id:+item.id});
  bar=await api(p,'bar_estado');
  // Retira da fila apenas o exemplo antigo usado pelo gerador anterior. Assim
  // uma regeneração sobre a mesma base continua a produzir uma cena limpa.
  for(const pedido of [...(bar.fila||[]),...(bar.resolvidos||[])]){
    if(!(pedido.itens||[]).some(i=>i.nome==='Sumo de múcua'))continue;
    if(pedido.estado==='em_analise') await api(p,'bar_decidir',{id:+pedido.id,decisao:'recusar',motivo_texto:'Exemplo substituído'});
    else if(['aprovado','a_caminho','falhou'].includes(pedido.estado)) await api(p,'bar_cancelar_copa',{id:+pedido.id});
  }
  bar=await api(p,'bar_estado');
  let bebida=(bar.itens||[]).find(i=>i.nome==='Coca-Cola');
  const semAlcool=(bar.categorias||[]).find(c=>c.nome==='Sem álcool')||(bar.categorias||[])[0];
  const coca={nome:'Coca-Cola',descricao:'Bem fresca, com gelo',categoria_id:+semAlcool.id,alcoolico:0,max_por_pedido:2,stock_minimo:8,servir:'garrafa',doses_garrafa:1,stock:48,estado:'ativo'};
  if(!bebida){const r=await api(p,'bar_item_guardar',coca);bar=await api(p,'bar_estado');bebida=(bar.itens||[]).find(i=>+i.id===+r.id);}
  else if(bebida.servir!=='garrafa'){await api(p,'bar_item_guardar',{...coca,id:+bebida.id});bar=await api(p,'bar_estado');bebida=(bar.itens||[]).find(i=>+i.id===+bebida.id);}
  {
    const foto=await apiImagemExemplo(p,'bar_item_foto',+bebida.id,'assets/ajuda/exemplos/coca-cola.jpg');
    if(!foto.success)throw new Error('Não foi possível associar a fotografia à Coca-Cola: '+(foto.error||''));
  }
  const cervejas=(bar.categorias||[]).find(c=>/cerveja/i.test(c.nome))||(bar.categorias||[])[0];
  const vinhos=(bar.categorias||[]).find(c=>/vinho|espumante/i.test(c.nome))||cervejas;
  for(const exemplo of [
    {nome:'Cuca',descricao:'Cerveja angolana bem fresca',ficheiro:'assets/ajuda/exemplos/cuca.jpg',actualizar_foto:true,categoria_id:+cervejas.id,servir:'garrafa',doses_garrafa:1,stock:36},
    {nome:'Vinho tinto',descricao:'Vinho tinto para acompanhar o jantar',ficheiro:'assets/ajuda/exemplos/vinho-tinto.jpg',actualizar_foto:true,categoria_id:+vinhos.id,servir:'ambos',doses_garrafa:6,stock:30},
  ]){
    bar=await api(p,'bar_estado');let item=(bar.itens||[]).find(i=>i.nome===exemplo.nome);
    if(!item){const r=await api(p,'bar_item_guardar',{...exemplo,alcoolico:1,max_por_pedido:2,stock_minimo:6,estado:'ativo'});bar=await api(p,'bar_estado');item=(bar.itens||[]).find(i=>+i.id===+r.id);}
    else if(+item.categoria_id!==+exemplo.categoria_id){await api(p,'bar_item_guardar',{...item,...exemplo,alcoolico:1,max_por_pedido:2,stock_minimo:6,estado:'ativo'});bar=await api(p,'bar_estado');item=(bar.itens||[]).find(i=>i.nome===exemplo.nome);}
    if(item&&(exemplo.actualizar_foto||!item.foto)){const foto=await apiImagemExemplo(p,'bar_item_foto',+item.id,exemplo.ficheiro);if(!foto.success)throw new Error('Não foi possível associar a fotografia a '+exemplo.nome+': '+(foto.error||''));}
  }
  await api(p,'bar_abrir',{});bar=await api(p,'bar_estado');
  const membro=(convite.membros||[]).find(m=>m.nome==='Ana Kiala')||convite.membros[0];
  const membroBar=(convite.membros||[]).find(m=>m.nome==='Mateus Kiala')||membro;
  for(const pedido of [...(bar.fila||[]),...(bar.resolvidos||[])]){
    if(!(pedido.itens||[]).some(i=>i.nome==='Coca-Cola'&&i.unidade!=='garrafa'))continue;
    if(pedido.estado==='em_analise')await api(p,'bar_decidir',{id:+pedido.id,decisao:'recusar',motivo_texto:'Exemplo anterior ao pedido por garrafa'});
    else if(['aprovado','a_caminho','falhou'].includes(pedido.estado))await api(p,'bar_cancelar_copa',{id:+pedido.id});
  }
  bar=await api(p,'bar_estado');
  const jaPedido=[...(bar.fila||[]),...(bar.resolvidos||[])].some(x=>['em_analise','aprovado','a_caminho'].includes(x.estado)&&(x.itens||[]).some(i=>i.nome==='Coca-Cola'&&i.unidade==='garrafa'));
  if(!jaPedido) await api(p,'bar_pedir_por',{posto:'copa',convidado_id:+membroBar.id,mesa_id:+mesa.id,itens:[{item_id:+bebida.id,quantidade:2,unidade:'garrafa'}]});

  DADOS={conviteId:+convite.id,conviteCodigo:convite.codigo,membroId:+membro.id,mesaId:+mesa.id,
    mesaToken:mesa.bar_token,bebidaId:+bebida.id,categoriaBarId:+semAlcool.id,despesaId:+desp.id,categoriaOrcId:+cat.id};
}

// Uma única fotografia não pode explicar passos que mudam o estado da página:
// antes/depois de abrir um modal, um formulário dobrável ou o próprio editor.
// Cada alvo abaixo tem de estar realmente visível quando a cena é capturada.
const ALVO='[data-aj-alvo="1"]';
const sequenciasComCenas = [
  {nome:'convidados',topico:1,url:'/index.php',passos:[
    {tipo:'abrir',fazer:async p=>marcar(p,p.locator('button[onclick="novoConvite()"]:visible'))},
    {tipo:'preencher',fazer:async p=>{await p.locator('button[onclick="novoConvite()"]:visible').first().click();await p.locator('#ov-convite.aberto').waitFor();await p.locator('#c-id').evaluate((e,id)=>e.value=id,DADOS.conviteId);const nomes=p.locator('#membros input[type=text]');await nomes.first().fill('Ana Kiala');await p.locator('button[onclick="addMembro()"]:visible').click();await nomes.nth(1).fill('Mateus Kiala');await p.fill('#c-nome','Família Kiala');await p.fill('#c-telefone','+244 923 456 789');await marcar(p,p.locator('#c-nome'));}},
    {tipo:'enviar',rolar:true,fazer:async p=>{await p.locator('#ov-convite .modal').evaluate(e=>e.scrollTop=e.scrollHeight);await marcar(p,p.locator('#ov-convite button[onclick="guardarConvite()"]'));}},
  ]},
  {nome:'convidados',topico:2,url:'/index.php',passos:[
    {tipo:'pesquisar',fazer:async p=>{await p.fill('#busca','Família Kiala');await p.dispatchEvent('#busca','input');await p.waitForTimeout(500);await marcar(p,p.locator('#busca'));}},
    {tipo:'editar',fazer:async p=>{const row=p.locator('.convite-row').filter({hasText:'Família Kiala'}).first();await row.locator('button[title="Editar"]').click();await p.locator('#ov-convite.aberto').waitFor();const modal=p.locator('#ov-convite .modal');await modal.evaluate(e=>e.scrollTop=e.scrollHeight*.62);await marcar(p,p.locator('.picker[data-target="c-presenca"]'));}},
    {tipo:'rever',rolar:true,fazer:async p=>{await p.locator('#ov-convite .modal').evaluate(e=>e.scrollTop=e.scrollHeight);await p.evaluate(()=>fechar('ov-convite'));await p.fill('#busca','Família Kiala');await p.dispatchEvent('#busca','input');await p.waitForTimeout(500);await marcar(p,p.locator('.convite-row').filter({hasText:'Família Kiala'}));}},
  ]},
  {nome:'mesas',topico:1,url:'/mesas.php',passos:[
    {tipo:'adicionar',fazer:async p=>{await p.locator('#barra-add-dobra').evaluate(e=>e.open=true);await marcar(p,p.viewportSize().width<=760?p.locator('#barra-add-dobra > summary'):p.locator('#nova-nome'));}},
    {tipo:'preencher',fazer:async p=>{await p.fill('#nova-nome','Jacarandá');await p.fill('#nova-cap','8');await p.getByRole('button',{name:'Oval',exact:true}).first().click();await marcar(p,p.locator('#nova-nome'));}},
    {tipo:'arrastar',rolar:true,fazer:async p=>{await p.locator('#barra-add-dobra').evaluate(e=>e.open=false);await p.evaluate(id=>irAMesa(id),DADOS.mesaId);await p.waitForTimeout(400);await marcar(p,p.locator(`.mesa-node[data-id="${DADOS.mesaId}"]`));}},
  ]},
  {nome:'mesas',topico:2,url:'/mesas.php',passos:[
    {tipo:'selecionar',fazer:async p=>{await p.evaluate(()=>irTab('pessoas'));await p.fill('#busca-tab','Ana Kiala');await p.dispatchEvent('#busca-tab','input');await p.waitForTimeout(350);await marcar(p,p.locator('#busca-tab'));}},
    {tipo:'selecionar',fazer:async p=>{await p.evaluate(id=>irAMesa(id),DADOS.mesaId);await p.waitForTimeout(350);const combo=p.locator('.combo[data-kind="mesa-pessoa"]').first();DADOS.membroId=+(await combo.getAttribute('data-arg'));await combo.evaluate(e=>abrirCombo(e));await p.waitForTimeout(220);await marcar(p,combo.locator('.combo-pop'));}},
    {tipo:'rever',rolar:true,fazer:async p=>{await api(p,'convidado_mesa',{id:DADOS.membroId,mesa_id:DADOS.mesaId});await p.reload({waitUntil:'networkidle'});await p.evaluate(id=>irAMesa(id),DADOS.mesaId);await p.waitForTimeout(450);await marcar(p,p.locator('#tab-body .mesa-form').first());}},
  ]},
  {nome:'impresso',topico:1,url:'/graficas.php',passos:[
    {tipo:'selecionar',fazer:async p=>marcar(p,p.locator('a[href="editor-cartao.php"]:visible').first())},
    {tipo:'editar',rolar:true,fazer:async p=>{
      await p.goto(BASE+'/editor-cartao.php',{waitUntil:'networkidle'});await continuarEditor(p);
      // No telemóvel as camadas vivem na gaveta inferior. Marcar a primeira
      // sem abrir a gaveta fotografava um elemento transformado para fora do
      // viewport e já não representava a interface que o utilizador vê.
      if(p.viewportSize().width<=760){
        await p.locator('.ed-movel-bt[data-abrir="camadas"]').click();
        await p.locator('body.ed-inspector-on').waitFor();
      }
      await marcar(p,p.locator('#camadas .camada').first());
    }},
    {tipo:'guardar',fazer:async p=>{
      if(p.viewportSize().width<=760&&await p.locator('body.ed-inspector-on').count()){
        await p.locator('.ed-inspector-fechar').click();
        await p.locator('body.ed-inspector-on').waitFor({state:'detached'}).catch(()=>{});
      }
      await p.locator('#bt-versao').click();await p.locator('.vs-jan.aberta').waitFor();
      await p.waitForTimeout(700);await marcar(p,p.locator('.vs-fim'));
    }},
  ]},
  {nome:'impresso',topico:2,url:'/graficas.php',passos:[
    {tipo:'abrir',fazer:async p=>marcar(p,p.locator('table.prod tbody tr').filter({hasText:'Família Kiala'}).locator('td.nm'))},
    {tipo:'rever',rolar:true,fazer:async p=>{const linha=p.locator('table.prod tbody tr').filter({hasText:'Família Kiala'});await linha.click();await p.locator('#ov-modelo.aberto').waitFor();await p.waitForTimeout(450);await marcar(p,p.locator('#mod-palco'));}},
    {tipo:'descarregar',rolar:true,fazer:async p=>{await p.goto(BASE+'/manual.php?peca=cartao',{waitUntil:'networkidle'});await marcar(p,p.getByRole('button',{name:'Imprimir manual'}));}},
  ]},
  {nome:'digital',topico:1,url:'/convite-digital.php?demo=1',passos:[
    {tipo:'abrir',fazer:async p=>marcar(p,p.locator('#cover .seal'))},
    {tipo:'rever',rolar:true,fazer:async p=>{await p.locator('#cover').click();await p.waitForTimeout(500);await marcar(p,p.locator('#historia .titles'));}},
    {tipo:'confirmar',rolar:true,fazer:async p=>{await p.goto(BASE+'/convite.php?demo=1',{waitUntil:'networkidle'});await marcar(p,p.locator('#form-rsvp .opcoes'));}},
  ]},
  {nome:'digital',topico:2,url:'/convite-digital.php?demo=1',passos:[
    {tipo:'abrir',fazer:async p=>marcar(p,p.locator('#cover .seal'))},
    {tipo:'rolar',rolar:true,fazer:async p=>{await p.locator('#cover').click();await p.waitForTimeout(500);await marcar(p,p.locator('#convite .guest-card'));}},
    {tipo:'confirmar',rolar:true,fazer:async p=>{await p.goto(BASE+'/convite.php?demo=1',{waitUntil:'networkidle'});await p.locator('#op-sim').click();await marcar(p,p.locator('#membros'));}},
  ]},
  {nome:'porta',topico:1,url:'/porteiro.php',passos:[
    {tipo:'pesquisar',fazer:async p=>{await p.fill('#q','Família Kiala');await p.evaluate(()=>buscar());await p.waitForTimeout(450);await marcar(p,p.locator('#q'));}},
    {tipo:'selecionar',fazer:async p=>marcar(p,p.locator('#resultado .lista-memb'))},
    {tipo:'confirmar',rolar:true,fazer:async p=>{await api(p,'porta_checkin',{convite_id:DADOS.conviteId,modo:'todos'});await p.reload({waitUntil:'networkidle'});await p.locator('#tab-ent').click();await p.waitForTimeout(450);await marcar(p,p.locator('#lista-entradas, #painel-ent').first());}},
  ]},
  {nome:'porta',topico:2,url:'/porteiro.php',passos:[
    {tipo:'abrir',fazer:async p=>{await p.fill('#q','Família Kiala');await p.evaluate(()=>buscar());await p.waitForTimeout(450);await marcar(p,p.locator('#resultado .cartao-conv'));}},
    {tipo:'desmarcar',fazer:async p=>{const memb=p.locator('#resultado .memb').filter({hasText:'Mateus Kiala'});await marcar(p,memb);}},
    {tipo:'rever',rolar:true,fazer:async p=>{await api(p,'porta_checkin',{convite_id:DADOS.conviteId,modo:'membro',membro_id:DADOS.membroId});await p.reload({waitUntil:'networkidle'});await p.fill('#q','Família Kiala');await p.evaluate(()=>buscar());await p.waitForTimeout(450);await marcar(p,p.locator('#resultado .lista-memb'));}},
  ]},
  {nome:'bar',topico:1,url:'/bar.php?aba=gav',passos:[
    {tipo:'adicionar',fazer:async p=>marcar(p,p.locator('.b-cat').filter({hasText:'Sem álcool'}))},
    {tipo:'preencher',fazer:async p=>{await p.evaluate(()=>barAba('menu'));await p.evaluate(id=>barEditar(id),DADOS.bebidaId);await p.fill('#lf-nome','Coca-Cola');await p.fill('#lf-descricao','Bem fresca, com gelo');await p.evaluate(id=>{const s=document.getElementById('lf-categoria_id');if(s){s.value=String(id);s.dispatchEvent(new Event('change',{bubbles:true}));}},DADOS.categoriaBarId);await marcar(p,p.locator('#lf-nome'));}},
    {tipo:'activar',rolar:true,fazer:async p=>{if(await p.locator('#lic-jx').count())await p.locator('#lic-jx').click();await p.evaluate(()=>barAba('menu'));await p.waitForTimeout(350);await marcar(p,p.locator('.b-cart').filter({hasText:'Coca-Cola'}));}},
  ]},
  {nome:'bar',topico:2,url:()=>`/bebidas.php?m=${DADOS.mesaToken}`,passos:[
    {tipo:'enviar',fazer:async p=>{if(await p.locator('#b-q').count()){await p.fill('#b-q','Mateus Kiala');await p.waitForTimeout(550);await p.locator('.b-nome').filter({hasText:'Mateus Kiala'}).click();await p.waitForTimeout(550);}await marcar(p,p.locator('.b-bebida').filter({hasText:'Coca-Cola'}));}},
    {tipo:'preparar',rolar:true,fazer:async p=>{await p.goto(BASE+'/copa.php',{waitUntil:'networkidle'});const aba=p.locator('.b-pilula').filter({hasText:'Por entregar'});await aba.waitFor();await aba.click();await p.waitForTimeout(450);await marcar(p,p.locator('.b-ped').filter({hasText:'Coca-Cola'}).first());}},
    {tipo:'entregar',rolar:true,fazer:async p=>{await p.goto(BASE+'/entregas.php',{waitUntil:'networkidle'});await p.waitForTimeout(450);await marcar(p,p.locator('.b-ped').filter({hasText:'Coca-Cola'}).first());}},
  ]},
  {nome:'orcamento',topico:1,url:'/orcamento.php',passos:[
    {tipo:'adicionar',fazer:async p=>marcar(p,p.locator('button[onclick="abrirDespesa()"]:visible').first())},
    {tipo:'preencher',fazer:async p=>{await p.locator('button[onclick="abrirDespesa()"]:visible').first().click();await p.locator('#m-desp.aberto').waitFor();await p.locator('#md-id').evaluate((e,id)=>e.value=id,DADOS.despesaId);await p.fill('#md-desc','Fotografia e vídeo');await p.fill('#md-valor','850 000');await p.fill('#md-fornecedor','Luz do Sul');await p.fill('#md-nota','Sinal pago; saldo antes da cerimónia.');await p.selectOption('#md-categoria',String(DADOS.categoriaOrcId));await marcar(p,p.locator('#md-desc'));}},
    {tipo:'guardar',rolar:true,fazer:async p=>{await p.locator('#m-desp .modal').evaluate(e=>e.scrollTop=e.scrollHeight);await marcar(p,p.locator('#m-desp button[onclick="guardarDespesa()"]'));}},
  ]},
  {nome:'orcamento',topico:2,url:'/orcamento.php',passos:[
    {tipo:'comparar',fazer:async p=>marcar(p,p.locator('.kpi').filter({hasText:'POR PAGAR'}))},
    {tipo:'filtrar',rolar:true,fazer:async p=>{const chip=p.locator('.chip-cat').filter({hasText:'Fotografia'});await chip.click();await marcar(p,chip);}},
    {tipo:'rever',rolar:true,fazer:async p=>marcar(p,p.locator('table.desp tr').filter({hasText:'Fotografia e vídeo'}).locator('.d-nome'))},
  ]},
].map(f=>({...f,passos:f.passos.map(p=>({alvo:ALVO,...p}))}));
// A montra pode contar uma vantagem diferente do guia operacional. Estas
// cenas específicas evitam forçar uma captura da Ajuda a explicar outro gesto.
const sequenciasDemonstracao = [
  {nome:'digital',topico:2,url:'/index.php',passos:[
    {numero:3,tipo:'whatsapp',fazer:async p=>{
      await p.fill('#busca','Família Kiala');await p.dispatchEvent('#busca','input');await p.waitForTimeout(450);
      const linha=p.locator('.convite-row').filter({hasText:'Família Kiala'}).first();
      await marcar(p,linha.locator('.bt-wa'));
    }},
  ]},
].map(f=>({...f,passos:f.passos.map(p=>({alvo:ALVO,...p}))}));
async function enquadrar(p){
  await p.waitForTimeout(500);
  await p.evaluate(()=>{const m=document.querySelector('main');if(m)scrollTo({top:Math.max(0,m.offsetTop-8),behavior:'instant'});});
  await p.waitForTimeout(250);
}
async function guardar(p,nome,topico,dispositivo){
  await enquadrar(p);
  await p.screenshot({path:path.join(OUT,`${nome}-${topico}-${dispositivo}.jpg`),type:'jpeg',quality:84,fullPage:false});
}
async function guardarCena(p,cena,passo,dispositivo,pasta=OUT){
  const selector=typeof cena.alvo==='string'?cena.alvo:cena.alvo[dispositivo];
  const alvo=p.locator(selector+':visible').first();await alvo.waitFor();await p.waitForTimeout(260);
  const b=await alvo.boundingBox(),v=p.viewportSize();
  if(!b||b.x<0||b.y<0||b.x+b.width>v.width||b.y+b.height>v.height)
    throw new Error(`${cena.nome||''}/${passo}/${dispositivo}: alvo fora do enquadramento`);
  await p.screenshot({path:path.join(pasta,`${cena.modulo}-${cena.topico}-${passo}-${dispositivo}.jpg`),type:'jpeg',quality:88,fullPage:false});
  return {x:+((b.x+b.width/2)/v.width*100).toFixed(1),y:+((b.y+b.height/2)/v.height*100).toFixed(1)};
}
(async()=>{
  const browser=await chromium.launch({executablePath:EXE,args:['--no-sandbox']});
  const ctx=await browser.newContext({viewport:{width:1280,height:800},deviceScaleFactor:1,reducedMotion:'reduce'});
  const p=await ctx.newPage();
  await p.goto(BASE+'/login.php',{waitUntil:'networkidle'});
  await p.fill('[name=utilizador]',USER); await p.fill('[name=senha]',PASSWORD);
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  const resposta=await p.evaluate(async()=>{const r=await fetch('api.php?action=casamento_abrir&id=1',{method:'POST',headers:{'X-CSRF-Token':window.CSRF}});return {estado:r.status,texto:await r.text()};});
  let abriu;try{abriu=JSON.parse(resposta.texto);}catch(_){throw new Error(`Abrir casamento devolveu HTTP ${resposta.estado}: ${resposta.texto}`);}
  if(!abriu.success)throw new Error('Não foi possível abrir o casamento de demonstração: '+(abriu.error||'erro desconhecido'));
  const dispositivos=[['desktop',{width:1280,height:800}],['mobile',{width:390,height:780}]]
    .filter(([nome])=>!SO_DISPOSITIVO||nome===SO_DISPOSITIVO);
  for(const [dispositivo,viewport] of dispositivos){
    await p.setViewportSize(viewport);
    await p.goto(BASE+'/index.php',{waitUntil:'networkidle'});
    await prepararDados(p);
    if(!SO_CENAS)for(const [nome,topicos] of modulos.filter(([nome])=>!SO_MODULO||nome===SO_MODULO)){
      for(let i=0;i<topicos.length;i++){
        const [url,preparar]=topicos[i]; await p.goto(BASE+url,{waitUntil:'networkidle'});
        try{if(preparar)await preparar(p);}catch(e){console.warn(`${nome}/${i+1}: ${e.message}`);}
        await guardar(p,nome,i+1,dispositivo);
      }
      console.log(`CAPTURA ${nome} · ${dispositivo}`);
    }
    for(const fluxo of sequenciasComCenas.filter(x=>!SO_MODULO||x.nome===SO_MODULO)){
      const url=typeof fluxo.url==='function'?fluxo.url():fluxo.url;
      await p.goto(BASE+url,{waitUntil:'networkidle'});
      for(let i=0;i<fluxo.passos.length;i++){
        const cena={...fluxo.passos[i],modulo:fluxo.nome,topico:fluxo.topico};await cena.fazer(p);
        const ponto=await guardarCena(p,cena,i+1,dispositivo);
        alvosGerados.push({modulo:fluxo.nome,topico:fluxo.topico,passo:i+1,dispositivo,
          tipo:cena.tipo||'tocar',rolar:!!cena.rolar,...ponto});
        console.log(`ALVO ${fluxo.nome}/${fluxo.topico}/${i+1} · ${dispositivo}: ${ponto.x},${ponto.y}`);
      }
    }
    for(const fluxo of sequenciasDemonstracao.filter(x=>!SO_MODULO||x.nome===SO_MODULO)){
      await p.goto(BASE+fluxo.url,{waitUntil:'networkidle'});
      for(const passo of fluxo.passos){
        const cena={...passo,modulo:fluxo.nome,topico:fluxo.topico};await cena.fazer(p);
        const ponto=await guardarCena(p,cena,passo.numero,dispositivo,OUT_DEMO);
        alvosDemoGerados.push({modulo:fluxo.nome,topico:fluxo.topico,passo:passo.numero,dispositivo,
          tipo:cena.tipo||'tocar',rolar:!!cena.rolar,...ponto});
        console.log(`ALVO DEMO ${fluxo.nome}/${fluxo.topico}/${passo.numero} · ${dispositivo}: ${ponto.x},${ponto.y}`);
      }
    }
  }
  let alvosFinais=alvosGerados;
  const manifesto=path.join(OUT,'alvos-cenas.json');
  if(SO_MODULO&&fs.existsSync(manifesto)){
    const anteriores=JSON.parse(fs.readFileSync(manifesto,'utf8'));
    alvosFinais=[...anteriores.filter(x=>x.modulo!==SO_MODULO),...alvosGerados];
  }
  fs.writeFileSync(manifesto,JSON.stringify(alvosFinais,null,2));
  let alvosDemoFinais=alvosDemoGerados;
  const manifestoDemo=path.join(OUT,'alvos-demonstracao.json');
  if(SO_MODULO&&fs.existsSync(manifestoDemo)){
    const anteriores=JSON.parse(fs.readFileSync(manifestoDemo,'utf8'));
    alvosDemoFinais=[...anteriores.filter(x=>x.modulo!==SO_MODULO),...alvosDemoGerados];
  }
  fs.writeFileSync(manifestoDemo,JSON.stringify(alvosDemoFinais,null,2));
  await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});

