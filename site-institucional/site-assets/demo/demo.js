/* Demonstração interativa do MasterSchool (seção "Experimente" do site).
   Tudo roda no navegador, com dados fictícios (Colégio Aurora): não acessa o
   sistema de verdade. Carregado sob demanda pelo index.html. */
(() => {
  'use strict';
  const sec = document.getElementById('experimente');
  if (!sec || sec.dataset.pronto) return;
  sec.dataset.pronto = '1';

  const MAX = 'site-assets/max.png';
  const $ = (s, el = document) => el.querySelector(s);
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const md = (s) => esc(s).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\n/g, '<br>');

  // ── Ícones (traço, 24×24) ─────────────────────────────────────────────
  const I = {
    home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
    wallet: '<path d="M20 12V8H6a2 2 0 0 1 0-4h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    left: '<path d="m15 18-6-6 6-6"/>',
    right: '<path d="m9 18 6-6-6-6"/>',
    down: '<path d="m6 9 6 6 6-6"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    chart: '<path d="M3 3v18h18"/><path d="M7 16v-5M12 16V8M17 16v-9"/>',
    clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    qr: '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM17 17h4v4h-4"/>',
    copy: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
    moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9z"/>',
    spark: '<path d="M12 3l1.9 5.8L20 11l-6.1 2.2L12 19l-1.9-5.8L4 11l6.1-2.2z"/>',
    cal: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    alert: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
    msg: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
    out: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
    tick: '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    dollar: '<path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
    cog: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
  };
  const ic = (n) => `<svg viewBox="0 0 24 24" aria-hidden="true">${I[n]}</svg>`;

  // ── Dados fictícios ───────────────────────────────────────────────────
  const ALUNOS = [
    ['Ana Beatriz Souza', 98], ['Bruno Lima', 96], ['Carla Mendes', 95], ['Davi Rocha', 92], ['Eduarda Pires', 97],
    ['Felipe Antunes', 89], ['Gabriela Nunes', 100], ['Heitor Campos', 72], ['Isadora Lopes', 94], ['João Pedro Alves', 91],
  ];
  const ini = (n) => n.split(' ').filter((p) => p.length > 2).slice(0, 2).map((p) => p[0]).join('');
  const FILHOS = [
    { nome: 'Lucas', turma: '5º Ano', aula: 'Matemática com a Prof.ª Renata', notas: [
      ['Matemática', 8.5, 'Prova 4,2 · Teste 2,5 · Trabalho 1,8'], ['Língua Portuguesa', 9.1, 'Prova 4,6 · Teste 2,7 · Trabalho 1,8'],
      ['Ciências', 7.8, 'Prova 3,8 · Teste 2,4 · Trabalho 1,6'], ['História', 5.4, 'Prova 2,4 · Teste 1,8 · Trabalho 1,2'],
      ['Geografia', 8.0, 'Prova 4,0 · Teste 2,4 · Trabalho 1,6'], ['Língua Inglesa', 9.5, 'Avaliação única 9,5'] ] },
    { nome: 'Sofia', turma: '2º Ano', aula: 'Artes com o Prof. Diego', notas: [
      ['Matemática', 9.2, 'Prova 4,6 · Teste 2,8 · Trabalho 1,8'], ['Língua Portuguesa', 8.7, 'Prova 4,3 · Teste 2,6 · Trabalho 1,8'],
      ['Ciências', 9.0, 'Prova 4,5 · Teste 2,7 · Trabalho 1,8'], ['Artes', 10, 'Avaliação única 10'] ] },
  ];
  const PRECISA = [
    { t: '3 turmas sem chamada hoje', s: '6º Ano, 8º Ano B e 9º Ano', tipo: 'alerta', acao: 'Avisar os professores', ok: 'Professores avisados pelo app' },
    { t: '2 mensalidades atrasadas há mais de 30 dias', s: 'R$ 1.360,00 em aberto', tipo: 'erro', acao: 'Enviar lembrete às famílias', ok: 'Lembrete enviado com o Pix' },
    { t: '4 avaliações sem notas lançadas', s: 'Aplicadas há mais de 7 dias', tipo: 'alerta', acao: 'Cobrar o lançamento', ok: 'Pedido enviado aos professores' },
  ];
  const MAXQ = {
    prof: [
      ['Como está a frequência do 5º Ano?', 'No mês, o **5º Ano** está com **94%** de presença, acima da meta de 75%.\n\nAtenção ao **Heitor Campos**: 4 faltas nas últimas duas semanas. Vale falar com a família.'],
      ['Quem está abaixo da média em Matemática?', 'No 3º bimestre, **2 alunos** estão abaixo de 6,0 em Matemática: **Felipe Antunes (5,2)** e **Heitor Campos (4,8)**.\n\nOs dois foram mal na prova de frações. Quer que eu monte uma atividade de reforço?'],
      ['Escreve um recado aos pais sobre a prova', 'Pronto, um rascunho:\n\n"Olá, famílias! Na **próxima sexta-feira** teremos a prova de Matemática (frações e decimais). Revisem com os filhos os exercícios das páginas 48 a 55. Obrigada!"\n\nPosso enviar pelo app para as famílias do 5º Ano.'],
    ],
    fam: [
      ['Como o Lucas está indo?', 'O **Lucas** vai bem: média **8,1** no 3º bimestre e **97%** de presença.\n\nO ponto de atenção é **História (5,4)**: a prova foi abaixo do esperado. A professora sugeriu revisar o capítulo 6.'],
      ['Quando é a próxima prova?', 'A próxima é de **Matemática**, na **sexta-feira**, sobre frações e decimais. Depois vem **Ciências**, na quarta seguinte.'],
      ['Tem algo pendente pra eu resolver?', 'Só uma coisa: a **mensalidade de novembro** vence em **3 dias** (R$ 680,00). Dá para pagar com Pix aqui mesmo, no botão da tela inicial.'],
    ],
    dir: [
      ['Resuma a semana da escola', 'Semana boa: frequência média de **93,4%** (+1,2) e **R$ 184 mil** recebidos.\n\nPedem atenção: **3 turmas** ainda sem chamada hoje e **2 famílias** com mais de 30 dias de atraso.'],
      ['Quais turmas preocupam?', '**9º Ano**: a frequência caiu para **84%** nas últimas duas semanas.\n**8º Ano B**: média de Matemática em **5,8**, abaixo da escola (7,4).\n\nSugiro uma conversa com a coordenação pedagógica.'],
      ['Como está a inadimplência?', 'Está em **4,2%** este mês (no mês passado, 5,1%). São **6 famílias**, e **2** com mais de 30 dias.\n\nEnviar o lembrete com o Pix costuma resolver metade em uma semana.'],
    ],
  };
  const PERFIS = {
    prof: { nome: 'Professor', em: '📚', pessoa: 'Marcos', abas: [['inicio', 'Início', 'home'], ['turmas', 'Turmas', 'users'], ['diario', 'Diário', 'book']] },
    fam: { nome: 'Família', em: '👨‍👩‍👧', pessoa: 'Paula', abas: [['inicio', 'Início', 'home'], ['boletim', 'Boletim', 'chart'], ['pagar', 'Pagar', 'wallet']] },
    dir: { nome: 'Direção', em: '🎯', pessoa: 'Helena', abas: [['inicio', 'Início', 'home'], ['pessoas', 'Pessoas', 'users'], ['financas', 'Finanças', 'wallet']] },
  };
  const TAREFAS = {
    prof: [['chamada', 'Faça a chamada da aula de agora', 'No card "Agora", toque em Chamada.'], ['max', 'Pergunte algo ao Max', 'É o botão do centro da barra.'], ['escuro', 'Ligue o modo escuro', 'Menu > Modo escuro.']],
    fam: [['pagar', 'Pague a mensalidade com Pix', 'Toque em "Pagar com Pix ou boleto".'], ['boletim', 'Veja o boletim do Lucas', 'Aba Boletim, na barra de baixo.'], ['max', 'Pergunte algo ao Max', 'É o botão do centro da barra.']],
    dir: [['precisa', 'Resolva um item do "Precisa de você"', 'Toque num aviso e escolha a ação.'], ['financas', 'Veja as finanças do mês', 'Aba Finanças, na barra de baixo.'], ['max', 'Pergunte algo ao Max', 'É o botão do centro da barra.']],
    pc: [['kpi', 'Clique num indicador do painel', 'O gráfico muda para ele.'], ['ctrlk', 'Abra a busca rápida e encontre o 5º Ano', 'Ctrl+K, ou "Ir para…" no topo.'], ['chamadapc', 'Faça a chamada no computador', 'Marque P ou F e lance.']],
  };

  // ── Estado ────────────────────────────────────────────────────────────
  const st = {
    modo: 'cel', perfil: 'prof', tela: 'inicio', folha: null, escuro: false, toast: null,
    feitos: { prof: new Set(), fam: new Set(), dir: new Set(), pc: new Set() },
    chamada: {}, chamadaFeita: false, filho: 0, bim: 3, pix: 'novo', pago: false,
    aberto: null, resolvidos: new Set(), msgs: [], ocupado: false,
  };
  const pc = { tela: 'home', kpi: 0, pal: false, termo: '', ativo: 0, turma: '5º Ano', chamada: {}, feita: false, aluno: null, toast: null };

  const feito = (id, perfil = st.modo === 'pc' ? 'pc' : st.perfil) => {
    if (st.feitos[perfil].has(id)) return;
    st.feitos[perfil].add(id);
    guia();
  };
  const alvo = () => {
    const p = st.modo === 'pc' ? 'pc' : st.perfil;
    const t = TAREFAS[p].find(([id]) => !st.feitos[p].has(id));
    return t ? t[0] : null;
  };
  const marca = (id) => (alvo() === id ? ' msd-alvo' : '');

  let tToast;
  function toast(txt, alvoPc = false) {
    clearTimeout(tToast);
    if (alvoPc) pc.toast = txt; else st.toast = txt;
    tToast = setTimeout(() => { st.toast = null; pc.toast = null; render(); }, 2400);
  }

  // ── App no celular ────────────────────────────────────────────────────
  const app = document.createElement('div');
  app.className = 'msd';

  function telaInicio() {
    const P = PERFIS[st.perfil];
    if (st.perfil === 'prof') {
      return `
        <div class="msd-mut">Bom dia, ${P.pessoa} 👋</div>
        <div class="msd-h">Quinta-feira, aula em andamento</div>
        <div class="msd-heroi">
          <div class="msd-heroi-linha"><span class="msd-vivo"><i></i>Agora</span><small>08:00 – 08:50</small></div>
          <div class="msd-turma">5º Ano</div>
          <small>Matemática · Sala 12</small>
          <div class="msd-barra"><span style="width:46%"></span></div>
          <div class="msd-acoes">
            ${st.chamadaFeita
              ? `<button class="msd-hbtn msd-hbtn--cheio" data-a="ir:chamada">${ic('check')} Chamada feita</button>`
              : `<button class="msd-hbtn msd-hbtn--cheio${marca('chamada')}" data-a="ir:chamada">${ic('tick')} Chamada</button>`}
            <button class="msd-hbtn" data-a="toast:Registro de aula: conteúdo do dia em poucos toques">${ic('book')} Registro</button>
          </div>
          <div class="msd-depois">${ic('right')} Depois: 6º Ano · Matemática às 09:40</div>
        </div>
        <div class="msd-card">
          <div class="msd-rot">${ic('clock')} Hoje</div>
          <div class="msd-nota"><span>Aulas</span><b>4</b></div>
          <div class="msd-nota"><span>Chamadas pendentes</span><b>${st.chamadaFeita ? 0 : 1}</b></div>
          <div class="msd-nota"><span>Avaliações para corrigir</span><b>2</b></div>
        </div>
        <div class="msd-card">
          <div class="msd-rot">${ic('msg')} Comunicação</div>
          <b>Reunião pedagógica na sexta</b>
          <div class="msd-mut">Coordenação · há 2 h</div>
        </div>`;
    }
    if (st.perfil === 'fam') {
      const f = FILHOS[st.filho];
      return `
        <div class="msd-mut">Olá, ${P.pessoa} 👋</div>
        <div class="msd-filhos">
          ${FILHOS.map((x, i) => `<button class="msd-filho${i === st.filho ? ' on' : ''}" data-a="filho:${i}"><span class="msd-av">${x.nome[0]}</span><span><b>${x.nome}</b><small>${x.turma}</small></span></button>`).join('')}
        </div>
        ${st.pago ? `
          <div class="msd-card"><div class="msd-rot">${ic('wallet')} Mensalidades</div>
            <div class="msd-nota"><b>Tudo em dia</b><span class="msd-tag msd-tag--ok">${ic('check')} Pago</span></div>
            <div class="msd-mut">Recibo de novembro disponível em Pagar.</div></div>` : `
          <div class="msd-card msd-urgente">
            <div class="msd-rot">${ic('cal')} Mensalidade de novembro</div>
            <div class="msd-valor">R$ 680,00</div>
            <div class="msd-mut" style="margin-bottom:10px">Vence em 3 dias · ${f.nome} e ${FILHOS[1 - st.filho].nome}</div>
            <button class="msd-btn${marca('pagar')}" data-a="pix">${ic('qr')} Pagar com Pix ou boleto</button>
          </div>`}
        <div class="msd-card">
          <div class="msd-rot"><span class="msd-vivo" style="color:var(--ok);background:color-mix(in srgb,var(--ok) 12%,transparent)"><i></i>Agora</span></div>
          <b>${f.nome} está em aula</b>
          <div class="msd-mut">${f.aula}</div>
        </div>
        <div class="msd-card">
          <div class="msd-rot">${ic('chart')} 3º bimestre</div>
          <div class="msd-nota"><span>Média geral</span><b>${(f.notas.reduce((s, n) => s + n[1], 0) / f.notas.length).toFixed(1).replace('.', ',')}</b></div>
          <div class="msd-nota"><span>Presença</span><b>97%</b></div>
        </div>`;
    }
    return `
      <div class="msd-heroi">
        <div class="msd-heroi-linha"><small>Quinta-feira, 29 de outubro</small><span class="msd-chip-ano">2026 ${ic('down')}</span></div>
        <div style="font-size:20px;font-weight:800;margin-top:4px">Bom dia, ${P.pessoa}</div>
        <small>${3 - [...st.resolvidos].length > 0 ? `${3 - [...st.resolvidos].length} coisas pedem sua atenção hoje.` : 'Tudo em ordem por aqui.'}</small>
        <div class="msd-maxlinha"><img src="${MAX}" alt=""><span><b>Max:</b> frequência subiu 1,2 ponto esta semana.</span></div>
      </div>
      <div class="msd-rot" style="margin-top:4px">${ic('alert')} Precisa de você</div>
      <div class="msd-lista">
        ${PRECISA.map((p, i) => {
          const ok = st.resolvidos.has(i);
          return `<button class="msd-item msd-precisa${p.tipo === 'erro' ? ' msd-precisa--erro' : ''}${!ok && i === 0 ? marca('precisa') : ''}" data-a="precisa:${i}" ${ok ? 'style="border-left-color:var(--ok)"' : ''}>
            <span class="msd-item-txt"><b>${ok ? '✓ ' + p.ok : p.t}</b><small>${ok ? 'Resolvido agora' : p.s}</small></span>
            ${ok ? '' : `<span class="msd-seta">${ic(st.aberto === i ? 'down' : 'right')}</span>`}
          </button>
          ${st.aberto === i && !ok ? `<div style="padding:0 13px 12px"><button class="msd-btn" data-a="resolver:${i}">${p.acao}</button></div>` : ''}`;
        }).join('')}
      </div>
      <div class="msd-rot">${ic('chart')} Pulso da escola</div>
      <div class="msd-kpis">
        <div class="msd-kpi"><small>Frequência do mês</small><b>93,4%</b><small><span class="msd-up">▲1,2</span> vs. setembro</small></div>
        <div class="msd-kpi"><small>Aproveitamento</small><b>7,9</b><small><span class="msd-up">▲0,3</span> no bimestre</small></div>
        <div class="msd-kpi"><small>Recebido</small><b>R$ 184 mil</b><small>91% do previsto</small></div>
        <div class="msd-kpi"><small>Inadimplência</small><b>4,2%</b><small><span class="msd-up">▼0,9</span> vs. setembro</small></div>
      </div>
      <div class="msd-card"><div class="msd-rot">${ic('clock')} Hoje</div>
        <div class="msd-nota"><span>Chamadas lançadas</span><b>8/11</b></div>
        <div class="msd-nota"><span>Aniversariantes</span><b>2 🎂</b></div></div>`;
  }

  function telaChamada() {
    const ch = st.chamada;
    const n = (v) => ALUNOS.filter((a) => ch[a[0]] === v).length;
    const sem = ALUNOS.length - n(true) - n(false);
    return `
      <button class="msd-voltar" data-a="ir:inicio">${ic('left')} Minhas turmas</button>
      <div class="msd-h" style="margin-bottom:8px">Chamada · 5º Ano</div>
      <div style="display:flex;gap:6px;margin-bottom:10px"><span class="msd-tag">${ic('cal')} Hoje</span><span class="msd-tag">Matemática · 08:00</span>
        ${st.chamadaFeita ? `<span class="msd-tag msd-tag--ok">${ic('check')} Lançada</span>` : '<span class="msd-tag msd-tag--alerta">Pendente</span>'}</div>
      ${st.chamadaFeita ? '' : `<div class="msd-massa"><button class="p" data-a="todos:1">${ic('check')} Presentes</button><button class="f" data-a="todos:0">${ic('x')} Faltantes</button></div>`}
      <div class="msd-lista">
        ${ALUNOS.map(([nome, pct]) => {
          const v = ch[nome];
          return `<div class="msd-aluno${v === true ? ' msd-aluno--p' : v === false ? ' msd-aluno--f' : ''}">
            <span class="msd-item-txt"><b>${nome}</b><small style="color:${pct < 75 ? 'var(--erro)' : 'var(--ok)'};font-weight:700">${pct}%</small></span>
            <span class="msd-pf"><button class="p${v === true ? ' on' : ''}" data-a="pf:${nome}:1" ${st.chamadaFeita ? 'disabled' : ''}>P</button><button class="f${v === false ? ' on' : ''}" data-a="pf:${nome}:0" ${st.chamadaFeita ? 'disabled' : ''}>F</button></span>
          </div>`;
        }).join('')}
      </div>
      ${st.chamadaFeita ? '' : `<div class="msd-rodape">
        <span class="msd-resumo"><span class="p">${n(true)} P</span> · <span class="f">${n(false)} F</span><small>${sem ? sem + ' sem marcar' : 'todos marcados'}</small></span>
        <button class="msd-btn${sem ? '' : marca('chamada')}" data-a="lancar" ${sem ? 'disabled' : ''}>${ic('check')} Lançar frequência</button>
      </div>`}`;
  }

  function telaBoletim() {
    const f = FILHOS[st.filho];
    return `
      <div class="msd-h">Boletim</div>
      <div class="msd-filhos">
        ${FILHOS.map((x, i) => `<button class="msd-filho${i === st.filho ? ' on' : ''}" data-a="filho:${i}"><span class="msd-av">${x.nome[0]}</span><span><b>${x.nome}</b><small>${x.turma}</small></span></button>`).join('')}
      </div>
      <div class="msd-bims">${[1, 2, 3, 4].map((b) => `<button class="${b === st.bim ? 'on' : ''}" data-a="bim:${b}">${b}º bim.</button>`).join('')}</div>
      ${st.bim === 4 ? `<div class="msd-card"><b>4º bimestre ainda não começou</b><div class="msd-mut">As notas aparecem aqui assim que forem lançadas.</div></div>` :
        f.notas.map(([d, m, det]) => {
          const mm = Math.min(10, Math.max(0, m + (st.bim - 3) * 0.3)).toFixed(1);
          return `<div class="msd-card"><div class="msd-nota"><b>${d}</b><span class="msd-media ${mm >= 6 ? 'msd-media--ok' : 'msd-media--baixa'}">${mm.replace('.', ',')}</span></div><div class="msd-notas">${det}</div></div>`;
        }).join('')}`;
  }

  function telaPagar() {
    return `
      <div class="msd-h">Meus pagamentos</div>
      ${st.pago ? `<div class="msd-card"><div class="msd-nota"><b>Novembro · R$ 680,00</b><span class="msd-tag msd-tag--ok">${ic('check')} Pago</span></div>
        <div class="msd-mut">Pago hoje com Pix</div><button class="msd-btn msd-btn--sec" style="margin-top:10px" data-a="toast:Recibo aberto em PDF">${ic('file')} Ver recibo</button></div>` :
        `<div class="msd-card msd-urgente"><div class="msd-rot">${ic('cal')} Próxima mensalidade</div><div class="msd-valor">R$ 680,00</div>
        <div class="msd-mut" style="margin-bottom:10px">Vence em 3 dias</div><button class="msd-btn${marca('pagar')}" data-a="pix">${ic('qr')} Pagar com Pix ou boleto</button></div>`}
      <div class="msd-rot">Outubro e anteriores</div>
      <div class="msd-lista">${['Outubro', 'Setembro', 'Agosto'].map((m) => `<div class="msd-item"><span class="msd-item-txt"><b>${m} · R$ 680,00</b><small>Pago com Pix</small></span><span class="msd-tag msd-tag--ok">Pago</span></div>`).join('')}</div>`;
  }

  function telaTurmas() {
    return `<div class="msd-h">Minhas turmas</div>
      ${[['5º Ano', 'Manhã · 10 alunos', true], ['6º Ano', 'Manhã · 12 alunos', false], ['7º Ano', 'Tarde · 14 alunos', false]].map(([t, s, a]) => `
        <div class="msd-card"><div class="msd-nota"><span><b style="font-size:16px">${t}</b><div class="msd-mut">${s}</div></span>${a ? '<span class="msd-tag msd-tag--ok">Agora</span>' : ''}</div>
        <div class="msd-acoes" style="margin-top:10px"><button class="msd-btn" data-a="${a ? 'ir:chamada' : 'toast:Chamada do ' + t + ' aberta'}">${ic('tick')} Chamada</button><button class="msd-btn msd-btn--sec" data-a="toast:Notas do ${t}">${ic('chart')} Notas</button></div></div>`).join('')}`;
  }

  function telaDiario() {
    return `<div class="msd-h">Diário de classe</div><div class="msd-lista">
      ${[['Hoje', 'Frações equivalentes · exercícios p. 48'], ['Ontem', 'Comparação de frações'], ['Segunda', 'Introdução a frações'], ['Sexta', 'Revisão para a prova']].map(([d, c]) => `<div class="msd-item"><span class="msd-av">${ic('book')}</span><span class="msd-item-txt"><b>${c}</b><small>${d} · 5º Ano · Matemática</small></span></div>`).join('')}</div>`;
  }

  function telaPessoas() {
    return `<div class="msd-h">Pessoas</div><div class="msd-busca">${ic('search')} Buscar aluno</div>
      <div class="msd-lista">${ALUNOS.slice(0, 7).map(([n], i) => `<div class="msd-item"><span class="msd-av">${ini(n)}</span><span class="msd-item-txt"><b>${n}</b><small>Mat. 2026${String(i + 1).padStart(3, '0')} · ${i % 3 ? '2 responsáveis' : '1 responsável'}</small></span><span class="msd-tag msd-tag--ok">5º Ano</span></div>`).join('')}</div>
      <button class="msd-fab" data-a="toast:Novo aluno: cadastro com responsáveis e contrato">${ic('plus')}</button>`;
  }

  function telaFinancas() {
    return `<div class="msd-h">Financeiro</div>
      <div class="msd-kpis" style="margin-top:0">
        <div class="msd-kpi"><small>Previsto no mês</small><b>R$ 202 mil</b><small>186 parcelas</small></div>
        <div class="msd-kpi"><small>Recebido</small><b>R$ 184 mil</b><small>91% do previsto</small></div>
        <div class="msd-kpi"><small>Em atraso</small><b>R$ 8,5 mil</b><small>6 famílias</small></div>
      </div>
      <div class="msd-card"><div class="msd-rot">${ic('chart')} Recebido por dia</div>
        <div style="display:flex;align-items:flex-end;gap:6px;height:90px">${[30, 55, 80, 45, 90, 62, 40, 70, 95, 50].map((h) => `<span style="flex:1;height:${h}%;border-radius:5px 5px 2px 2px;background:var(--p);opacity:${0.45 + h / 200}"></span>`).join('')}</div></div>
      <div class="msd-rot">Últimos pagamentos</div>
      <div class="msd-lista">${[['Família Souza', 'Pix · agora'], ['Família Rocha', 'Boleto · 10:12'], ['Família Nunes', 'Pix · 09:40']].map(([n, s]) => `<div class="msd-item"><span class="msd-av">${ic('dollar')}</span><span class="msd-item-txt"><b>${n}</b><small>${s}</small></span><b>R$ 680</b></div>`).join('')}</div>`;
  }

  const TELAS = { inicio: telaInicio, chamada: telaChamada, boletim: telaBoletim, pagar: telaPagar, turmas: telaTurmas, diario: telaDiario, pessoas: telaPessoas, financas: telaFinancas };

  function qr() {
    // Padrão fixo (parece um QR; é só ilustração).
    let s = 7, cel = '';
    const r = () => ((s = (s * 9301 + 49297) % 233280) / 233280);
    for (let y = 0; y < 25; y++) for (let x = 0; x < 25; x++) {
      const canto = (x < 7 && y < 7) || (x > 17 && y < 7) || (x < 7 && y > 17);
      if (canto) continue;
      if (r() > 0.52) cel += `<rect x="${x}" y="${y}" width="1" height="1"/>`;
    }
    const olho = (x, y) => `<rect x="${x}" y="${y}" width="7" height="7" fill="#0f172a"/><rect x="${x + 1}" y="${y + 1}" width="5" height="5" fill="#fff"/><rect x="${x + 2}" y="${y + 2}" width="3" height="3" fill="#0f172a"/>`;
    return `<svg viewBox="0 0 25 25" shape-rendering="crispEdges"><g fill="#0f172a">${cel}</g>${olho(0, 0)}${olho(18, 0)}${olho(0, 18)}</svg>`;
  }

  function folhaHtml() {
    const f = st.folha;
    if (f === 'pix') {
      return `<div class="msd-folha"><div class="msd-folha-topo"><b>Pagar com Pix</b><button class="msd-x" data-a="fechar">${ic('x')}</button></div>
        <div style="padding:0 16px">
        ${st.pix === 'pago' ? `<div class="msd-card msd-pago"><div class="msd-pago-ic">${ic('check')}</div><b style="font-size:17px">Pagamento confirmado</b>
            <div class="msd-mut" style="margin:4px 0 12px">A baixa foi automática e o recibo já está disponível.</div>
            <button class="msd-btn" data-a="fechar">Concluir</button></div>` : `
          <div class="msd-card" style="text-align:center"><div class="msd-mut">Mensalidade de novembro</div><div class="msd-valor">R$ 680,00</div>
            <div class="msd-qr">${qr()}</div>
            <div class="msd-pix">00020126580014br.gov.bcb.pix0136a1f4-demo-aurora-0000-mensalidade5204000053039865406680.00</div>
            <button class="msd-btn${st.pix === 'novo' ? marca('pagar') : ''}" data-a="copiar">${ic('copy')} ${st.pix === 'novo' ? 'Copiar código Pix' : 'Código copiado'}</button>
            ${st.pix === 'aguardando' ? `<div class="msd-aguarda"><span class="msd-gira"></span> Aguardando o pagamento no app do banco…</div>` : '<div class="msd-mut" style="margin-top:9px">Ou pague pelo boleto, que vence no mesmo dia.</div>'}
          </div>`}
        </div></div>`;
    }
    if (f === 'menu') {
      const tiles = {
        prof: [['Minhas turmas', 'users'], ['Planejamento', 'cal'], ['Quadro de horários', 'clock'], ['Diário', 'book'], ['Comunicação', 'msg'], ['Avaliações', 'tick']],
        fam: [['Boletim', 'chart'], ['Pagamentos', 'wallet'], ['Quadro de horários', 'clock'], ['Documentos', 'file'], ['Comunicação', 'msg'], ['Rematrícula', 'cal']],
        dir: [['Pessoas', 'users'], ['Turmas', 'grid'], ['Financeiro', 'wallet'], ['Documentos', 'file'], ['Comunicação', 'msg'], ['Configurações', 'cog']],
      }[st.perfil];
      return `<div class="msd-folha"><div class="msd-folha-topo"><b>Menu</b><button class="msd-x" data-a="fechar">${ic('x')}</button></div>
        <div style="padding:0 16px 10px"><div class="msd-busca">${ic('search')} Ir para...</div></div>
        <div class="msd-tiles">${tiles.map(([t, i]) => `<button class="msd-tile" data-a="toast:${t}: disponível na versão completa"><span>${ic(i)}</span>${t}</button>`).join('')}</div>
        <div class="msd-sec">Ajustes</div>
        <button class="msd-ajuste${marca('escuro')}" data-a="escuro">${ic('moon')} Modo escuro <span class="msd-chave${st.escuro ? ' on' : ''}"></span></button>
        <button class="msd-ajuste" data-a="toast:Avisos no celular ativados">${ic('bell')} Avisos no celular <span class="msd-chave on"></span></button>
        <button class="msd-ajuste" data-a="toast:Na demonstração não dá para sair 🙂">${ic('out')} Sair</button></div>`;
    }
    if (f === 'sino') {
      const av = {
        prof: [['Nota lançada no 5º Ano', 'Prova de Matemática · há 1 h'], ['Recado da família do Heitor', 'Sobre as faltas · há 3 h']],
        fam: [['Nota de Matemática lançada', 'Lucas · 8,5 · há 1 h'], ['Boleto de novembro disponível', 'Vence em 3 dias · ontem']],
        dir: [['Chamada do 7º Ano lançada', 'há 20 min'], ['Pagamento recebido', 'Família Souza · Pix · agora']],
      }[st.perfil];
      return `<div class="msd-folha"><div class="msd-folha-topo"><b>Avisos</b><button class="msd-x" data-a="fechar">${ic('x')}</button></div>
        <div style="padding:0 16px"><div class="msd-lista">${av.map(([t, s]) => `<div class="msd-item"><span class="msd-av">${ic('bell')}</span><span class="msd-item-txt"><b>${t}</b><small>${s}</small></span></div>`).join('')}</div></div></div>`;
    }
    if (f === 'max') {
      const Q = MAXQ[st.perfil];
      return `<div class="msd-max"><div class="msd-folha-topo"><span></span><button class="msd-x" data-a="fechar">${ic('x')}</button></div>
        <div class="msd-max-heroi"><img src="${MAX}" alt=""><b>Oi, ${PERFIS[st.perfil].pessoa}! Eu sou o Max.</b><small>Conheço os dados da escola. Pergunte o que quiser.</small></div>
        <div class="msd-chat">${st.msgs.map((m) => `<div class="msd-msg msd-msg--${m.de}">${m.txt === null ? '<span class="msd-digitando"><i></i><i></i><i></i></span>' : md(m.txt)}</div>`).join('')}</div>
        <div class="msd-sugs">${Q.map(([q], i) => `<button class="msd-sug" data-a="perg:${i}" ${st.ocupado ? 'disabled' : ''}>${esc(q)}</button>`).join('')}</div></div>`;
    }
    return '';
  }

  function appHtml() {
    const P = PERFIS[st.perfil];
    const corpo = TELAS[st.tela] ? TELAS[st.tela]() : '';
    const naChamada = st.tela === 'chamada' && !st.chamadaFeita;
    const abaAtiva = (id) => st.tela === id || (id === 'turmas' && st.tela === 'chamada');
    const alvoAba = (id) => (id === 'boletim' ? marca('boletim') : id === 'financas' ? marca('financas') : '');
    return `
      <div class="msd-status"><span>9:41</span><span>●●● 5G ▮</span></div>
      <div class="msd-topo"><span class="msd-logo">CA</span><span class="msd-escola">Colégio Aurora</span>
        <button class="msd-sino" data-a="sino" aria-label="Avisos">${ic('bell')}<em>2</em></button></div>
      <div class="msd-cont">${corpo}</div>
      ${naChamada ? '' : `<nav class="msd-abas">
        <button class="msd-aba${abaAtiva('inicio') && !st.folha ? ' on' : ''}" data-a="ir:inicio"><span>${ic('home')}</span>Início</button>
        <button class="msd-aba${abaAtiva(P.abas[1][0]) && !st.folha ? ' on' : ''}${alvoAba(P.abas[1][0])}" data-a="ir:${P.abas[1][0]}"><span>${ic(P.abas[1][2])}</span>${P.abas[1][1]}</button>
        <button class="msd-orbe${marca('max')}" data-a="max" aria-label="Max"><span><img src="${MAX}" alt=""></span>Max</button>
        <button class="msd-aba${abaAtiva(P.abas[2][0]) && !st.folha ? ' on' : ''}${alvoAba(P.abas[2][0])}" data-a="ir:${P.abas[2][0]}"><span>${ic(P.abas[2][2])}</span>${P.abas[2][1]}</button>
        <button class="msd-aba${st.folha === 'menu' ? ' on' : ''}${st.folha !== 'menu' ? marca('escuro') : ''}" data-a="menu"><span>${ic('grid')}</span>Menu</button>
      </nav>`}
      ${folhaHtml()}
      ${st.toast ? `<div class="msd-toast" role="status"><i>${ic('check')}</i>${esc(st.toast)}</div>` : ''}`;
  }

  function renderApp() {
    const cont = $('.msd-cont', app);
    const rolagem = cont ? cont.scrollTop : 0;
    const telaAntes = app.dataset.tela;
    app.classList.toggle('escuro', st.escuro);
    app.innerHTML = appHtml();
    const novo = $('.msd-cont', app);
    if (novo && telaAntes === st.tela + st.perfil) {
      novo.scrollTop = rolagem;
      novo.querySelectorAll(':scope > *').forEach((el) => (el.style.animation = 'none'));
    }
    app.dataset.tela = st.tela + st.perfil;
    // O HTML é refeito a cada mudança: só anima o que acabou de aparecer
    // (senão a folha, o chat e o aviso piscam a cada letra do Max).
    const parar = (el) => el && (el.style.animation = 'none');
    if (app.dataset.folha === String(st.folha)) parar($('.msd-folha, .msd-max', app));
    app.dataset.folha = String(st.folha);
    const msgs = app.querySelectorAll('.msd-msg');
    const antes = +(app.dataset.msgs || 0);
    msgs.forEach((m, i) => { if (i < antes) parar(m); });
    app.dataset.msgs = String(msgs.length);
    if (app.dataset.toast === String(st.toast)) parar($('.msd-toast', app));
    app.dataset.toast = String(st.toast);
    const chat = $('.msd-chat', app);
    if (chat) chat.scrollTop = chat.scrollHeight;
  }

  function perguntar(i) {
    if (st.ocupado) return;
    const [q, r] = MAXQ[st.perfil][i];
    st.ocupado = true;
    st.msgs.push({ de: 'eu', txt: q }, { de: 'max', txt: null });
    render();
    setTimeout(() => {
      const m = st.msgs[st.msgs.length - 1];
      let n = 0;
      const passo = () => {
        n = Math.min(r.length, n + 6);
        m.txt = r.slice(0, n);
        // Não corta no meio de um "**negrito**".
        if ((m.txt.match(/\*\*/g) || []).length % 2) m.txt += '**';
        render();
        if (n < r.length) setTimeout(passo, 16);
        else { st.ocupado = false; feito('max'); render(); }
      };
      passo();
    }, 900);
  }

  function acaoApp(a) {
    const [cmd, x, y] = a.split(':');
    switch (cmd) {
      case 'ir': st.folha = null; st.tela = x; if (x === 'boletim') feito('boletim'); if (x === 'financas') feito('financas'); break;
      case 'menu': st.folha = st.folha === 'menu' ? null : 'menu'; break;
      case 'sino': st.folha = 'sino'; break;
      case 'max': st.folha = 'max'; st.msgs = []; break;
      case 'fechar': st.folha = null; break;
      case 'toast': toast(a.slice(6)); break;
      case 'escuro': st.escuro = !st.escuro; feito('escuro'); break;
      case 'filho': st.filho = +x; break;
      case 'bim': st.bim = +x; break;
      case 'pf': st.chamada[x] = y === '1'; break;
      case 'todos': ALUNOS.forEach(([n]) => (st.chamada[n] = x === '1')); break;
      case 'lancar':
        st.chamadaFeita = true; st.tela = 'inicio'; feito('chamada');
        toast(`Chamada lançada · ${ALUNOS.filter(([n]) => st.chamada[n]).length} presentes`);
        break;
      case 'pix': st.folha = 'pix'; break;
      case 'copiar':
        if (st.pix !== 'novo') break;
        st.pix = 'aguardando'; toast('Código Pix copiado');
        setTimeout(() => { st.pix = 'pago'; st.pago = true; feito('pagar', 'fam'); render(); }, 2600);
        break;
      case 'precisa': st.aberto = st.aberto === +x ? null : +x; break;
      case 'resolver': st.resolvidos.add(+x); st.aberto = null; feito('precisa'); toast(PRECISA[+x].ok); break;
      case 'perg': perguntar(+x); return;
    }
    render();
  }

  // ── Computador ────────────────────────────────────────────────────────
  const KPIS = [
    ['Frequência do mês', '93,4%', 'meta 75%', [91, 94, 92, 90, 93, 93.4], '%'],
    ['Aproveitamento', '7,9', '3º bimestre', [7.2, 7.4, 7.6, 7.5, 7.7, 7.9], ''],
    ['Receita do mês', 'R$ 184 mil', '91% do previsto', [168, 175, 171, 180, 178, 184], 'k'],
    ['Em atraso', 'R$ 8,5 mil', '6 famílias', [12.1, 11.4, 10.8, 9.9, 9.2, 8.5], 'k'],
  ];
  const MESES = ['Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out'];
  const PAL = [
    { g: 'Telas', t: 'Início', s: 'Painel da direção', i: 'home', ir: () => (pc.tela = 'home') },
    { g: 'Telas', t: 'Financeiro', s: 'Visão geral', i: 'wallet', ir: () => (pc.tela = 'fin') },
    { g: 'Telas', t: 'Recibos', s: 'Financeiro', i: 'file', ir: () => toast('Recibos: disponível na versão completa', true) },
    { g: 'Telas', t: 'Rematrícula', s: 'Secretaria', i: 'cal', ir: () => toast('Rematrícula: disponível na versão completa', true) },
    ...['5º Ano', '6º Ano', '8º Ano B', '9º Ano'].map((t) => ({ g: 'Turmas', t, s: 'Turma · abrir a chamada', i: 'users', ir: () => { pc.tela = 'chamada'; pc.turma = t; pc.chamada = {}; pc.feita = false; if (t === '5º Ano') feito('ctrlk', 'pc'); } })),
    ...ALUNOS.map(([n, p]) => ({ g: 'Alunos', t: n, s: 'Aluno · 5º Ano', i: 'users', ir: () => { pc.tela = 'aluno'; pc.aluno = [n, p]; } })),
  ];
  const semAc = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const palRes = () => {
    const t = semAc(pc.termo.trim());
    const r = PAL.filter((p) => !t ? p.g === 'Telas' : semAc(p.t + ' ' + p.s).includes(t)).slice(0, 8);
    if (t) r.push({ g: '', t: `Perguntar ao Max: “${pc.termo.trim()}”`, s: 'Max', i: 'spark', ir: () => toast('O Max responde no painel dele, com os dados da escola', true) });
    return r;
  };

  const pcEl = document.createElement('div');
  pcEl.className = 'msdk-janela';

  function pcHome() {
    const k = KPIS[pc.kpi];
    const max = Math.max(...k[3]);
    const min = Math.min(...k[3]) * 0.8;
    return `
      <div class="msdk-cam">Início</div>
      <div class="msdk-heroi"><small>Quinta-feira, 29 de outubro</small><b>Bom dia, Helena</b><small>8 de 11 chamadas lançadas · 3 avisos pedem sua atenção</small>
        <div class="msd-maxlinha"><img src="${MAX}" alt=""><span><b>Max:</b> a frequência subiu 1,2 ponto; o 9º Ano caiu para 84% e merece atenção.</span></div></div>
      <div class="msdk-kpis">${KPIS.map((x, i) => `<button class="msdk-kpi${i === pc.kpi ? ' on' : ''}${i === 1 ? marca('kpi') : ''}" data-k="kpi:${i}"><small>${x[0]}</small><b>${x[1]}</b><em>${x[2]}</em></button>`).join('')}</div>
      <div class="msdk-grid">
        <div class="msdk-card"><div class="msdk-card-t">${k[0]} por mês <span class="msd-tag">${ic('chart')} 2026</span></div>
          <div class="msdk-graf">${k[3].map((v) => `<div class="msdk-col"><b>${String(v).replace('.', ',')}${k[4] === '%' ? '%' : ''}</b><span style="height:${((v - min) / (max - min)) * 82 + 12}%"></span></div>`).join('')}</div>
          <div class="msdk-meses">${MESES.map((m) => `<i>${m}</i>`).join('')}</div></div>
        <div class="msdk-card"><div class="msdk-card-t">Precisa de você <span class="msd-tag msd-tag--alerta">3</span></div>
          ${PRECISA.map((p) => `<button class="msdk-item${p.tipo === 'erro' ? ' msdk-item--erro' : ''}" data-k="toast:${p.ok}"><span><b>${p.t}</b><small>${p.s}</small></span></button>`).join('')}</div>
      </div>`;
  }

  function pcChamada() {
    const n = (v) => ALUNOS.filter((a) => pc.chamada[a[0]] === v).length;
    const sem = ALUNOS.length - n(true) - n(false);
    return `
      <div class="msdk-cam">Minhas turmas › Frequência</div>
      <div class="msdk-h">Frequência · ${pc.turma}${pc.feita ? `<span class="msd-tag msd-tag--ok">${ic('check')} Lançada</span>` : '<span class="msd-tag msd-tag--alerta">Pendente</span>'}</div>
      <table class="msdk-tab"><thead><tr><th>Aluno</th><th>Presença</th><th>Frequência</th></tr></thead><tbody>
        ${ALUNOS.map(([nome, pct]) => {
          const v = pc.chamada[nome];
          return `<tr class="${v === true ? 'p' : v === false ? 'f' : ''}"><td>${nome}</td>
            <td><span class="msd-pf"><button class="p${v === true ? ' on' : ''}" data-k="pf:${nome}:1" ${pc.feita ? 'disabled' : ''}>P</button><button class="f${v === false ? ' on' : ''}" data-k="pf:${nome}:0" ${pc.feita ? 'disabled' : ''}>F</button></span></td>
            <td><span class="msdk-pct${pct < 75 ? ' msdk-pct--baixo' : ''}">${pct}%</span></td></tr>`;
        }).join('')}
      </tbody></table>
      ${pc.feita ? '' : `<div class="msdk-fixo"><span class="msd-resumo"><span class="p">${n(true)} P</span> · <span class="f">${n(false)} F</span> <small style="display:inline;margin-left:6px">${sem ? sem + ' sem marcar' : 'todos marcados'}</small></span>
        <button class="msd-btn msd-btn--sec" style="width:auto;height:36px" data-k="todos">${ic('check')} Todos presentes</button>
        <button class="msd-btn${sem ? '' : marca('chamadapc')}" data-k="lancar" ${sem ? 'disabled' : ''}>${ic('check')} Lançar frequência</button></div>`}`;
  }

  function pcAluno() {
    const [n, p] = pc.aluno;
    return `<div class="msdk-cam">Pessoas › Ficha do aluno</div>
      <div class="msdk-h"><span style="display:flex;align-items:center;gap:10px"><span class="msd-av" style="width:42px;height:42px">${ini(n)}</span>${n}</span><span class="msd-tag msd-tag--ok">5º Ano · Ativo</span></div>
      <div class="msdk-kpis"><div class="msdk-kpi"><small>Frequência</small><b>${p}%</b><em>no ano</em></div><div class="msdk-kpi"><small>Média</small><b>${(p / 11.2).toFixed(1).replace('.', ',')}</b><em>3º bimestre</em></div><div class="msdk-kpi"><small>Mensalidades</small><b>Em dia</b><em>contrato 2026</em></div><div class="msdk-kpi"><small>Responsáveis</small><b>2</b><em>com acesso ao app</em></div></div>
      <div class="msdk-card"><div class="msdk-card-t">Linha do tempo</div>
        ${[['Nota de Matemática lançada', 'hoje'], ['Presente na chamada', 'hoje, 08:05'], ['Mensalidade de outubro paga com Pix', '05/10']].map(([t, d]) => `<div class="msd-nota" style="padding:6px 0;border-bottom:1px solid var(--bd)"><span>${t}</span><small class="msd-mut">${d}</small></div>`).join('')}</div>`;
  }

  function pcFin() {
    return `<div class="msdk-cam">Financeiro</div><div class="msdk-h">Financeiro</div>
      <div class="msdk-kpis"><div class="msdk-kpi"><small>Previsto no mês</small><b>R$ 202 mil</b><em>186 parcelas</em></div><div class="msdk-kpi"><small>Recebido</small><b>R$ 184 mil</b><em>91% do previsto</em></div><div class="msdk-kpi"><small>Em aberto</small><b>R$ 9,5 mil</b><em>vence até o fim do mês</em></div><div class="msdk-kpi"><small>Inadimplência</small><b style="color:var(--erro)">4,2%</b><em>6 famílias</em></div></div>
      <div class="msdk-card"><div class="msdk-card-t">Últimos pagamentos <span class="msd-tag msd-tag--ok">baixa automática</span></div>
        ${[['Família Souza', 'Pix · agora', 'R$ 680,00'], ['Família Rocha', 'Boleto · 10:12', 'R$ 680,00'], ['Família Nunes', 'Pix · 09:40', 'R$ 720,00'], ['Família Campos', 'Pix · ontem', 'R$ 680,00']].map(([a, b, c]) => `<div class="msd-nota" style="padding:7px 0;border-bottom:1px solid var(--bd)"><span><b>${a}</b> <small class="msd-mut">${b}</small></span><b>${c}</b></div>`).join('')}</div>`;
  }

  function pcHtml() {
    const nav = (id, t, i) => `<button class="msdk-nav${pc.tela === id ? ' on' : ''}" data-k="${id ? 'tela:' + id : 'toast:' + t + ': disponível na versão completa'}">${ic(i)} ${t}</button>`;
    const corpo = { home: pcHome, chamada: pcChamada, aluno: pcAluno, fin: pcFin }[pc.tela]();
    const res = pc.pal ? palRes() : [];
    let grupo = '';
    return `
      <div class="msdk-cromo"><i></i><i></i><i></i><span class="msdk-url">${ic('search')} app.portalmasterschool.com.br</span></div>
      <div class="msdk">
        <aside class="msdk-lado"><div class="msdk-marca">MASTER</div>
          ${nav('home', 'Início', 'home')}${nav('', 'Max', 'spark')}${nav('', 'Comunicação', 'msg')}
          <div class="msdk-sec">PEDAGÓGICO</div>${nav('chamada', 'Minhas turmas', 'users')}${nav('', 'Planejamento', 'cal')}${nav('', 'Quadro de horários', 'clock')}${nav('', 'Diário de classe', 'book')}
          <div class="msdk-sec">GESTÃO</div>${nav('', 'Secretaria', 'grid')}${nav('fin', 'Financeiro', 'wallet')}${nav('', 'Administração', 'cog')}
        </aside>
        <div class="msdk-main">
          <div class="msdk-topo"><span class="msd-logo">CA</span><span class="msdk-escola">Colégio Aurora</span>
            <button class="msdk-busca${marca('ctrlk')}" data-k="pal">${ic('search')}<span>Ir para…</span><span class="msdk-kbd">Ctrl K</span></button>
            <span class="msd-sino" style="width:32px;height:32px">${ic('bell')}<em>2</em></span>
            <span class="msdk-user"><span class="msd-av">HM</span><span><b>Helena Martins</b><small>Direção</small></span></span></div>
          <div class="msdk-cont">${corpo}</div>
        </div>
        ${pc.pal ? `<div class="msdk-fundo" data-k="palx"></div><div class="msdk-pal" role="dialog" aria-label="Busca rápida">
          <label>${ic('search')}<input id="msdkBusca" value="${esc(pc.termo)}" placeholder="Ir para uma tela, turma ou aluno… (tente “5º”)" autocomplete="off"><span class="msdk-kbd">Esc</span></label>
          <div>${res.map((r, i) => {
            const g = r.g && r.g !== grupo ? `<div class="msdk-pal-g">${(grupo = r.g)}</div>` : '';
            return `${g}<button class="msdk-pal-i${i === pc.ativo ? ' on' : ''}" data-k="palir:${i}"><span>${ic(r.i)}</span><span><b>${esc(r.t)}</b><small>${esc(r.s)}</small></span></button>`;
          }).join('')}</div>
          <div class="msdk-pal-r"><span>↑↓ escolher</span><span>Enter abrir</span><span>Esc fechar</span></div></div>` : ''}
        ${pc.toast ? `<div class="msdk-toast" role="status"><i>${ic('check')}</i>${esc(pc.toast)}</div>` : ''}
      </div>`;
  }

  function renderPc() {
    const foco = document.activeElement && document.activeElement.id === 'msdkBusca';
    const pos = foco ? document.activeElement.selectionStart : 0;
    const cont = $('.msdk-cont', pcEl);
    const rolagem = cont ? cont.scrollTop : 0;
    const telaAntes = pcEl.dataset.tela;
    pcEl.innerHTML = pcHtml();
    const novo = $('.msdk-cont', pcEl);
    if (novo && telaAntes === pc.tela) { novo.scrollTop = rolagem; novo.querySelectorAll(':scope > *').forEach((el) => (el.style.animation = 'none')); }
    pcEl.dataset.tela = pc.tela;
    const parar = (el) => el && (el.style.animation = 'none');
    if (pcEl.dataset.pal === String(pc.pal)) parar($('.msdk-pal', pcEl));
    pcEl.dataset.pal = String(pc.pal);
    if (pcEl.dataset.toast === String(pc.toast)) parar($('.msdk-toast', pcEl));
    pcEl.dataset.toast = String(pc.toast);
    const inp = $('#msdkBusca', pcEl);
    if (inp && (foco || pc.pal)) { inp.focus(); inp.setSelectionRange(pos || inp.value.length, pos || inp.value.length); }
  }

  function acaoPc(a) {
    const [cmd, x, y] = a.split(':');
    switch (cmd) {
      case 'tela': pc.tela = x; if (x === 'chamada') { pc.turma = '5º Ano'; } break;
      case 'kpi': pc.kpi = +x; if (+x !== 0) feito('kpi', 'pc'); break;
      case 'toast': toast(a.slice(6), true); break;
      case 'pal': pc.pal = true; pc.termo = ''; pc.ativo = 0; break;
      case 'palx': pc.pal = false; break;
      case 'palir': { const r = palRes()[+x]; pc.pal = false; if (r) r.ir(); break; }
      case 'pf': pc.chamada[x] = y === '1'; break;
      case 'todos': ALUNOS.forEach(([n]) => (pc.chamada[n] = true)); break;
      case 'lancar': pc.feita = true; feito('chamadapc', 'pc'); toast(`Chamada do ${pc.turma} lançada`, true); break;
    }
    render();
  }

  pcEl.addEventListener('input', (e) => { if (e.target.id === 'msdkBusca') { pc.termo = e.target.value; pc.ativo = 0; renderPc(); } });
  pcEl.addEventListener('keydown', (e) => {
    if (!pc.pal || e.target.id !== 'msdkBusca') return;
    const n = palRes().length;
    if (e.key === 'ArrowDown') { e.preventDefault(); pc.ativo = (pc.ativo + 1) % n; renderPc(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); pc.ativo = (pc.ativo - 1 + n) % n; renderPc(); }
    else if (e.key === 'Enter') { e.preventDefault(); acaoPc('palir:' + pc.ativo); }
    else if (e.key === 'Escape') { e.preventDefault(); acaoPc('palx'); }
  });
  // Ctrl+K só enquanto a demonstração do computador está na tela.
  let pcVisivel = false;
  new IntersectionObserver(([e]) => (pcVisivel = e.isIntersecting), { threshold: 0.3 }).observe(pcEl);
  document.addEventListener('keydown', (e) => {
    if (st.modo === 'pc' && pcVisivel && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); acaoPc(pc.pal ? 'palx' : 'pal'); }
  });

  // ── Guia ao lado (perfis e tarefas) ───────────────────────────────────
  const palco = $('#xpPalco', sec);
  const guiaEl = document.createElement('div');
  guiaEl.className = 'xp-guia';
  const foneEl = document.createElement('div');
  foneEl.className = 'msd-fone';
  foneEl.appendChild(app);

  const TEXTO = {
    prof: ['Um dia do professor', 'A aula de agora aparece em destaque. Chamada em segundos, mesmo sem internet na sala.'],
    fam: ['A família no app', 'Mensalidade com Pix, boletim e a aula do filho na hora, tudo pelo celular.'],
    dir: ['A direção no bolso', 'O que precisa de você primeiro, os números do mês e o Max para perguntar.'],
    pc: ['No computador', 'O painel da direção, a busca rápida com Ctrl+K e a chamada com P/F.'],
  };

  function guia() {
    const p = st.modo === 'pc' ? 'pc' : st.perfil;
    const lista = TAREFAS[p];
    const atual = alvo();
    const todas = lista.every(([id]) => st.feitos[p].has(id));
    guiaEl.innerHTML = `
      ${st.modo === 'cel' ? `<div class="xp-perfis" role="group" aria-label="Escolha o perfil">${Object.entries(PERFIS).map(([id, x]) => `<button data-p="${id}" aria-pressed="${id === st.perfil}">${x.em} ${x.nome}</button>`).join('')}</div>` : ''}
      <div class="xp-tit">${TEXTO[p][0]}</div>
      <p class="xp-sub">${TEXTO[p][1]}</p>
      ${st.modo === 'cel' ? '<button class="xp-abrir" data-cheio>📱 Experimentar o app</button>' : ''}
      <ol class="xp-tarefas">${lista.map(([id, t, d], i) => {
        const ok = st.feitos[p].has(id);
        return `<li class="xp-tarefa${ok ? ' xp-tarefa--feita' : id === atual ? ' xp-tarefa--atual' : ''}"><i>${ok ? '✓' : i + 1}</i><span><b>${t}</b><small>${d}</small></span></li>`;
      }).join('')}</ol>
      <div class="xp-fim${todas ? ' on' : ''}">Viu como é simples? <a href="#contato">Agende uma demonstração com os dados da sua escola →</a></div>
      <div class="xp-selo">🔒 Demonstração com dados fictícios · Colégio Aurora</div>`;
    const dica = $('.msd-cheio-dica');
    if (dica) dica.textContent = atual ? '👉 ' + lista.find(([id]) => id === atual)[1] : '✓ Tudo feito! Troque o perfil para ver mais.';
  }

  guiaEl.addEventListener('click', (e) => {
    const b = e.target.closest('[data-p]');
    if (b) trocarPerfil(b.dataset.p);
    if (e.target.closest('[data-cheio]')) abrirCheio();
  });

  function trocarPerfil(p) {
    st.perfil = p; st.tela = 'inicio'; st.folha = null; st.msgs = []; st.aberto = null;
    render();
  }

  // Modo "No celular" / "No computador"
  sec.querySelectorAll('.xp-modos button').forEach((b) => b.addEventListener('click', () => {
    st.modo = b.dataset.modo;
    sec.querySelectorAll('.xp-modos button').forEach((x) => x.setAttribute('aria-selected', String(x === b)));
    montar();
  }));

  function montar() {
    palco.innerHTML = '';
    palco.className = 'xp-palco' + (st.modo === 'pc' ? ' xp-palco--pc' : '');
    palco.append(guiaEl, st.modo === 'pc' ? pcEl : foneEl);
    render();
  }

  // ── Tela cheia (visitante no celular) ─────────────────────────────────
  let cheio = null;
  function abrirCheio() {
    cheio = document.createElement('div');
    cheio.className = 'msd-cheio';
    cheio.innerHTML = `<div class="msd-cheio-barra"><span>Demonstração</span>
      <select aria-label="Perfil">${Object.entries(PERFIS).map(([id, x]) => `<option value="${id}"${id === st.perfil ? ' selected' : ''}>${x.em} ${x.nome}</option>`).join('')}</select>
      <button aria-label="Fechar demonstração">✕</button></div><div class="msd-cheio-dica"></div><div class="msd-wrap"></div>`;
    $('.msd-wrap', cheio).appendChild(app);
    $('select', cheio).addEventListener('change', (e) => trocarPerfil(e.target.value));
    $('button', cheio).addEventListener('click', fecharCheio);
    document.body.appendChild(cheio);
    document.documentElement.style.overflow = 'hidden';
    render();
  }
  function fecharCheio() {
    foneEl.appendChild(app);
    cheio.remove();
    cheio = null;
    document.documentElement.style.overflow = '';
    render();
  }

  // ── Cliques ───────────────────────────────────────────────────────────
  app.addEventListener('click', (e) => { const b = e.target.closest('[data-a]'); if (b && !b.disabled) acaoApp(b.dataset.a); });
  pcEl.addEventListener('click', (e) => { const b = e.target.closest('[data-k]'); if (b && !b.disabled) acaoPc(b.dataset.k); });

  function render() {
    renderApp();
    if (st.modo === 'pc') renderPc();
    guia();
  }

  montar();
})();
