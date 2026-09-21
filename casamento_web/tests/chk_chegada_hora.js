// A tira de chegada é da HORA DA FESTA, e de mais nenhuma.
//
// Ela aparecia no instante em que o último convite respondesse. Para um
// casamento em Dezembro cuja lista fecha em Março, isso são nove meses com uma
// tira no cimo do painel a dar a notícia do dia em que foi dada — e o cimo do
// painel é de onde se trabalha todos os dias. Uma tira que está sempre lá
// deixa de se ver: é enfeite a ocupar o lugar de uma ferramenta.
//
// Passa a existir só entre a hora marcada do casamento e as 6 da manhã
// seguinte. Às duas da manhã ainda se está na festa, e é a essa hora que
// alguém abre o painel para ver quem falta chegar; à meia-noite em ponto,
// não.
//
// E o que ela diz muda com o que é: antes da festa não diz nada, e na festa
// fala de quem CHEGOU — «Já chegaram 8 de 12» — em vez de contar convites que
// responderam, que é conversa de Setembro.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const marca = 'zzc' + Math.floor(Math.random() * 1e6);


/** Arruma um casamento de prova: arquiva-se primeiro, que é o que a casa exige
 *  antes de apagar — sem isso o apagar falhava em silêncio e os casamentos de
 *  prova iam-se juntando no arquivo de trabalho, a estragar as provas seguintes. */
async function arrumarCasamento(pg, id){
  return pg.evaluate(async i => {
    const g = a => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF } }).then(r => r.json()).catch(() => null);
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c) }).then(r => r.json()).catch(() => null);
    // São TRÊS passos, e por boa razão: um casamento com licença em vigor não
    // se arquiva, e um que não esteja arquivado não se apaga. A casa obriga a
    // desfazer pela ordem em que se fez, e uma prova que salte um passo deixa
    // o casamento no arquivo de trabalho a estragar as provas seguintes.
    await post('lic_revogar', { casamento: i, motivo: 'Fim da prova automática' });
    await g('casamento_estado&id=' + i + '&estado=arquivado');
    return g('casamento_apagar&id=' + i);
  }, id);
}

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');

  // Um casamento só desta prova, com dia e hora marcados.
  const dia = '2027-06-12', hora = '17:00';
  const novo = await p.evaluate(async ({ n }) => {
    const r = await fetch('api.php?action=casamento_criar', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify({ nome: n, licenca: 12 }) });
    return r.json();
  }, { n: 'ZZ Festa ' + marca });
  ok(novo.success, 'criou o casamento de prova');
  const cid = novo.id;

  await p.evaluate(async i => { await fetch('api.php?action=casamento_abrir&id=' + i,
    { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } }); }, cid);
  const marcou = await p.evaluate(async ({ d, h }) => {
    const r = await fetch('api.php?action=defs_save', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify({ defs: { 'evento.data': d, 'evento.hora': h } }) });
    return r.json();
  }, { d: dia, h: hora });
  ok(marcou && marcou.success !== false, 'marcou o dia e a hora do casamento');

  // Três convites confirmados, de duas pessoas cada: doze esperados.
  const ids = await p.evaluate(async () => {
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c || {}) }).then(r => r.json());
    const g = a => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF } }).then(r => r.json());
    const out = [];
    for (let i = 0; i < 3; i++) {
      const d = await post('convite_save', { nome_exibicao: 'Casal ' + i, tipo: 'ambos',
        membros: [{ nome: 'Ela ' + i }, { nome: 'Ele ' + i }] });
      const id = d.id || (d.convite && d.convite.id);
      if (id) { out.push(id); await g('convite_rsvp_manual&id=' + id + '&estado=confirmado'); }
    }
    return out;
  });
  ok(ids.length === 3, 'três convites de duas pessoas, todos confirmados');

  const ver = async (quando) => {
    await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
    await p.waitForTimeout(2200);
    return p.evaluate(q => {
      // O relógio da página adianta-se para a hora que se quer provar: esperar
      // pelo dia do casamento não é uma prova, é uma espera.
      AGORA_FESTA = q ? new Date(q) : null;
      momentoDeChegada(ULTIMAS_STATS || {});
      const el = document.getElementById('chegada');
      // Pergunta-se ao BROWSER, não ao atributo. `el.hidden` dizia que sim e a
      // faixa continuava desenhada: o [hidden] do browser é uma regra fraca e
      // o display da classe ganhava-lhe. Escondido é não ter caixa nenhuma.
      return { escondida: getComputedStyle(el).display === 'none'
                       && el.getClientRects().length === 0,
               atributo: el.hidden,
               alto: Math.round(el.getBoundingClientRect().height),
               texto: el.textContent.replace(/\s+/g, ' ').trim() };
    }, quando);
  };

  // ---- 1. fora da hora, não aparece ----
  const vespera = await ver('2027-06-11T20:00:00');
  ok(vespera.escondida,
     'na véspera não aparece, ainda que a lista esteja toda fechada — era aqui '
     + 'que ela vivia nove meses seguidos');

  const manha = await ver('2027-06-12T09:00:00');
  ok(manha.escondida,
     'e na manhã do próprio dia também não: a hora marcada são as ' + hora);

  const depois = await ver('2027-06-13T09:00:00');
  ok(depois.escondida, 'no dia seguinte, de manhã, já não aparece');

  const muitoDepois = await ver('2027-12-01T12:00:00');
  ok(muitoDepois.escondida, 'nem meio ano depois');

  // ---- 2. à hora da festa, aparece ----
  const festa = await ver('2027-06-12T17:30:00');
  ok(!festa.escondida, 'à hora da festa, aparece: «' + festa.texto + '»');
  ok(/é hoje/i.test(festa.texto),
     'e diz que é hoje, em vez de dar a notícia de Março: «' + festa.texto + '»');
  ok(/\b6\b/.test(festa.texto),
     'com as pessoas que se esperam — seis, de três convites de dois: «' + festa.texto + '»');
  ok(!/sem resposta/.test(festa.texto),
     'e sem se contradizer: marcar o convite confirmado confirma também as pessoas '
     + 'lá dentro, senão o painel dizia «6 confirmados» e «3 sem resposta» dos mesmos '
     + 'convites: «' + festa.texto + '»');

  const madrugada = await ver('2027-06-13T02:00:00');
  ok(!madrugada.escondida,
     'às duas da manhã ainda se está na festa — acabar à meia-noite era acabar '
     + 'a tira a meio da festa');

  const seis = await ver('2027-06-13T06:30:00');
  ok(seis.escondida, 'às seis e meia da manhã, acabou');

  // ---- 3. na festa, fala de quem CHEGOU ----
  const entrou = await p.evaluate(async is => {
    const r = await fetch('api.php?action=porta_checkin', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify({ convite_id: is[0], modo: 'todos' }) });
    return r.json();
  }, ids);
  ok(entrou && entrou.success !== false, 'um convite deu entrada à porta');
  const comChegadas = await ver('2027-06-12T18:00:00');
  ok(!comChegadas.escondida && /cheg/i.test(comChegadas.texto),
     'com gente à porta, a tira passa a falar de chegadas: «' + comChegadas.texto + '»');

  // ---- 4. sem data marcada, não há hora nenhuma ----
  // Num casamento acabado de abrir, que ainda não marcou o dia — e não a
  // apagar a data deste, porque guardar uma definição vazia não a apaga (e faz
  // bem: um campo em branco num formulário não deve limpar o que lá estava).
  const semDia = await p.evaluate(async n => {
    const r = await fetch('api.php?action=casamento_criar', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify({ nome: n, licenca: 12 }) });
    return r.json();
  }, 'ZZ SemDia ' + marca);
  ok(semDia.success, 'criou um casamento que ainda não marcou o dia');
  await p.evaluate(async i => { await fetch('api.php?action=casamento_abrir&id=' + i,
    { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } }); }, semDia.id);
  const semData = await ver('2027-06-12T18:00:00');
  ok(semData.escondida,
     'sem dia marcado não há hora da festa, e a tira não tem por onde aparecer');

  // ---- arrumar ----
  for (const id of [cid, semDia.id]) await arrumarCasamento(p, id);

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
