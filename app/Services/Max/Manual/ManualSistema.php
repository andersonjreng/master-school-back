<?php

namespace App\Services\Max\Manual;

/**
 * Manual do MasterSchool para o Max — a fonte de verdade de "como faço X" e
 * "onde fica Y". Cada item diz onde fica (caminho do menu), quem pode usar
 * (mesmas regras do menu/rotas do front) e o passo a passo.
 *
 * ATUALIZE AQUI sempre que uma tela nova entrar ou mudar de lugar: o Max só
 * sabe o que está neste arquivo.
 *
 * acesso: [] = qualquer pessoa logada. Com chaves, basta atender UMA:
 *   'pode'      => permissões (App\Support\Permissoes) — basta uma
 *   'professor' => true se professor também usa
 *   'perfis'    => nomes de perfil (ex.: 'Responsável', 'Aluno', 'Administrador')
 */
final class ManualSistema
{
    /** @return array<int, array{id:string, titulo:string, area:string, menu:string, acesso:array, palavras:string, passos:string}> */
    public static function itens(): array
    {
        return [
            // ═══ Uso geral ═══════════════════════════════════════════════════
            [
                'id' => 'instalar-app', 'area' => 'Geral', 'titulo' => 'Instalar o MasterSchool como aplicativo no celular',
                'menu' => 'Navegador do celular (Chrome ou Safari)', 'acesso' => [],
                'palavras' => 'app aplicativo celular instalar tela inicial android iphone playstore appstore atalho',
                'passos' => "O MasterSchool funciona como aplicativo, sem precisar de loja.\n"
                    . "- Android (Chrome): entre no endereço da escola e toque em 'Instalar' no aviso que aparece no rodapé; ou menu ⋮ do Chrome > 'Instalar app'.\n"
                    . "- iPhone (Safari): toque em Compartilhar (quadrado com seta) > 'Adicionar à Tela de Início'.\n"
                    . "- Computador: botão de download no topo da tela, ao lado do sino.\n"
                    . "Quando sair uma versão nova, aparece 'Nova versão disponível' no rodapé: toque em Atualizar.",
            ],
            [
                'id' => 'push', 'area' => 'Geral', 'titulo' => 'Receber avisos no celular (notificações push)',
                'menu' => 'Aviso "Receba os avisos da escola" no rodapé, ou Sino > "Avisos neste aparelho"', 'acesso' => [],
                'palavras' => 'push notificacao celular aviso app fechado receber avisos ativar desativar desligar bloqueado tela de bloqueio',
                'passos' => "Os avisos do sino (nota lançada, avaliação marcada, falta, boleto, comunicado, recado, ocorrência, rematrícula) também chegam na tela do "
                    . "celular, mesmo com o app fechado.\n1. Toque em 'Ativar' no aviso 'Receba os avisos da escola' (rodapé) — ou abra o Sino e toque em "
                    . "'Ativar' em 'Avisos neste aparelho'.\n2. Permita as notificações quando o celular perguntar. Chega um aviso de teste.\n"
                    . "- No iPhone, primeiro instale o app na tela de início (iOS 16.4 ou mais novo); no Android funciona também pelo Chrome.\n"
                    . "- Para testar ou desligar: Sino > 'Avisos neste aparelho' > Testar / Desligar. Ao sair da conta, o aparelho para de receber.\n"
                    . "- Se aparecer 'bloqueados', libere as notificações nas configurações do site (cadeado ao lado do endereço) ou do celular.",
            ],
            [
                'id' => 'sino', 'area' => 'Geral', 'titulo' => 'Notificações (sino)',
                'menu' => 'Sino no topo da tela', 'acesso' => [],
                'palavras' => 'notificacao aviso sino alerta lido nao lido novidade',
                'passos' => "O sino mostra o que é novo para você: notas lançadas, avaliações marcadas, faltas, boletos, comunicados, recados e "
                    . "ocorrências (família); e pendências da equipe conforme o perfil (chamadas atrasadas, notas pendentes, cadastros incompletos, "
                    . "planejamentos para aprovar, pagamentos). Toque num aviso para ir direto à tela. 'Marcar como lidas' limpa o contador.",
            ],
            [
                'id' => 'tema', 'area' => 'Geral', 'titulo' => 'Modo escuro',
                'menu' => 'Botão de lua/sol no topo da tela', 'acesso' => [],
                'palavras' => 'tema escuro claro noturno dark cor',
                'passos' => 'Toque no botão de lua (ou sol) no topo para alternar entre o modo claro e o escuro. A escolha fica salva no aparelho.',
            ],
            [
                'id' => 'max', 'area' => 'Geral', 'titulo' => 'Max — o assistente do sistema',
                'menu' => 'Max (menu) ou o botão redondo do Max no canto da tela', 'acesso' => [],
                'palavras' => 'max assistente ia inteligencia artificial chat pergunta conversa historico limite mensagens',
                'passos' => "O Max responde dúvidas sobre o sistema e consulta dados conforme o seu perfil. Abra pelo menu 'Max' (tela cheia, com histórico "
                    . "de conversas no botão ☰) ou pelo botão redondo no canto. Há um limite de mensagens por mês, mostrado no topo da conversa.",
            ],
            [
                'id' => 'home', 'area' => 'Geral', 'titulo' => 'Tela inicial (Home)',
                'menu' => 'Home', 'acesso' => [],
                'palavras' => 'home inicio painel resumo indicadores dashboard',
                'passos' => "A Home muda conforme o perfil: direção vê indicadores da escola (frequência, aproveitamento, receita, inadimplência), gráficos "
                    . "e o briefing do Max; professor vê as chamadas de hoje, registros e pendências das turmas dele; a família vê frequência, notas, "
                    . "próximas avaliações, comunicação e mensalidades de cada filho (troque o filho pelas abas no topo).",
            ],
            [
                'id' => 'perfis', 'area' => 'Geral', 'titulo' => 'Perfis de acesso e por que alguém não vê um menu',
                'menu' => 'Administração > Permissões', 'acesso' => [],
                'palavras' => 'perfil permissao acesso negado nao aparece menu sumiu liberar bloqueado papel funcao',
                'passos' => "Cada pessoa vê os menus do seu perfil: Administrador (tudo), Diretoria, Coordenação Pedagógica, Secretaria, Tesouraria, "
                    . "Professor, Responsável e Aluno. A escola pode liberar ou retirar áreas de cada perfil em Administração > Permissões "
                    . "(só o Administrador acessa). Se faltar acesso a algo, peça à direção.",
            ],

            // ═══ Pedagógico ══════════════════════════════════════════════════
            [
                'id' => 'minhas-turmas', 'area' => 'Pedagógico', 'titulo' => 'Minhas Turmas (painel do professor)',
                'menu' => 'Minhas Turmas', 'acesso' => ['professor' => true, 'pode' => ['pedagogico.ver']],
                'palavras' => 'minhas turmas card turma professor painel atalhos chamada notas avaliacoes registro',
                'passos' => "Mostra um card por turma com a semana de chamadas, pendências e atalhos: Chamada, Registro (de aula), Notas e Avaliações "
                    . "(na Educação Infantil, Relatório BNCC). Filtre por ano e por Ativas/Encerradas. Turma encerrada fica só para consulta.",
            ],
            [
                'id' => 'chamada', 'area' => 'Pedagógico', 'titulo' => 'Fazer a chamada (lançar frequência)',
                'menu' => 'Minhas Turmas > card da turma > Chamada', 'acesso' => ['professor' => true, 'pode' => ['pedagogico.editar']],
                'palavras' => 'chamada frequencia presenca falta lancar marcar justificar justificativa faltou presente',
                'passos' => "1. Minhas Turmas > no card da turma, toque em Chamada.\n2. Confira a data no topo (troque se for outro dia).\n"
                    . "3. Marque P ou F para cada aluno (ou use 'Presentes'/'Faltantes' para marcar todos).\n"
                    . "4. Em uma falta, toque no balão ao lado para justificar.\n5. Toque em 'Lançar frequência'.\n"
                    . "Para corrigir depois: abra a mesma data > Editar (ou Excluir). O ícone de calendário mostra os dias já lançados. "
                    . "Se o dia não tiver aula no calendário escolar, o sistema avisa antes de lançar.",
            ],
            [
                'id' => 'registro-aula', 'area' => 'Pedagógico', 'titulo' => 'Registrar a aula dada (conteúdo)',
                'menu' => 'Minhas Turmas > card da turma > Registro (aba Registro de Aula)', 'acesso' => ['professor' => true, 'pode' => ['pedagogico.editar']],
                'palavras' => 'registro aula conteudo ministrado diario aulas dadas registrar',
                'passos' => "1. Minhas Turmas > Registro (ou a aba 'Registro de Aula' na tela de frequência).\n2. Na disciplina do dia, toque em 'Registrar aula'.\n"
                    . "3. Informe o nº de aulas, o conteúdo ministrado e observações > Salvar.\n"
                    . "Dica: no Planejamento, o botão 'Aula dada' cria o registro a partir do plano.",
            ],
            [
                'id' => 'avaliacoes', 'area' => 'Pedagógico', 'titulo' => 'Cadastrar avaliação (prova, trabalho, teste)',
                'menu' => 'Minhas Turmas > card da turma > Avaliações', 'acesso' => ['professor' => true, 'pode' => ['pedagogico.editar']],
                'palavras' => 'avaliacao prova trabalho teste cadastrar marcar agendar calendario provas',
                'passos' => "1. Minhas Turmas > Avaliações > 'Cadastrar Avaliação'.\n2. Escolha o tipo (o valor vem do modelo de avaliação da escola), "
                    . "a data, a disciplina e o bimestre (unidade letiva); observação é opcional.\n3. Salvar.\n"
                    . "O calendário (ícone) mostra as avaliações do mês. Avaliação com notas lançadas só o administrador edita/exclui. "
                    . "As famílias são avisadas no sino.",
            ],
            [
                'id' => 'lancar-notas', 'area' => 'Pedagógico', 'titulo' => 'Lançar notas de uma avaliação',
                'menu' => 'Minhas Turmas > Avaliações > Lançar notas', 'acesso' => ['professor' => true, 'pode' => ['pedagogico.editar']],
                'palavras' => 'nota notas lancar digitar avaliacao segunda chamada corrigir editar nota',
                'passos' => "1. Minhas Turmas > Avaliações.\n2. Na avaliação, toque em 'Lançar notas' (ou 'Editar notas').\n"
                    . "3. Digite a nota de cada aluno (até o valor máximo mostrado; no celular aceita vírgula e o 'Próximo' do teclado pula de aluno). "
                    . "Marque '2ª ch.' para segunda chamada.\n4. Salvar notas. As famílias recebem aviso no sino.",
            ],
            [
                'id' => 'medias', 'area' => 'Pedagógico', 'titulo' => 'Médias do bimestre e correção de notas',
                'menu' => 'Minhas Turmas > card da turma > Notas', 'acesso' => ['professor' => true, 'pode' => ['pedagogico.ver']],
                'palavras' => 'media medias bimestral aprovado reprovado calculo corrigir nota parcial',
                'passos' => "1. Minhas Turmas > Notas.\n2. Escolha o bimestre e a disciplina > Aplicar.\n3. Veja a média de cada aluno; toque no aluno para ver as notas "
                    . "e em 'Editar notas' para corrigir.\nA média = soma de (nota ÷ valor máximo × peso) ÷ 10, de 0 a 10. 'Parcial' = avaliações do "
                    . "bimestre ainda não somam 100 de peso.",
            ],
            [
                'id' => 'bncc-infantil', 'area' => 'Pedagógico', 'titulo' => 'Relatório BNCC (Educação Infantil)',
                'menu' => 'Minhas Turmas > card da turma > Relatório BNCC', 'acesso' => ['professor' => true, 'pode' => ['pedagogico.editar']],
                'palavras' => 'bncc educacao infantil habilidades semestre relatorio campos de experiencia avaliacao infantil',
                'passos' => "Nas turmas de Educação Infantil a avaliação é por habilidades da BNCC: Minhas Turmas > Relatório BNCC > escolha o semestre e o aluno "
                    . "> marque o nível de cada habilidade (com observação) > Salvar.",
            ],
            [
                'id' => 'planejamento', 'area' => 'Pedagógico', 'titulo' => 'Planejamento de aulas (plano de aula, com ajuda do Max)',
                'menu' => 'Planejamento', 'acesso' => ['professor' => true, 'pode' => ['planejamento.aprovar', 'pedagogico.editar']],
                'palavras' => 'planejamento plano de aula planejar semana bncc habilidades objetivos aula dada aprovacao gerar plano max',
                'passos' => "1. Menu Planejamento > escolha a turma e a semana (setas).\n2. No dia, toque em 'Planejar' (ou 'Nova aula').\n"
                    . "3. Preencha disciplina, tema, objetivos, habilidades da BNCC (busca pelo código ou texto), desenvolvimento, recursos e avaliação/tarefa.\n"
                    . "   Ou use 'Planejar com o Max': descreva o que quer trabalhar e toque em 'Gerar plano'.\n"
                    . "4. Salve. Se a escola exigir aprovação, toque em 'Enviar para aprovação'.\n"
                    . "5. Depois da aula, 'Aula dada' cria o registro de aula a partir do plano.\n"
                    . "Direção/coordenação (Aprovar planejamentos): aba 'Aguardando aprovação' > Aprovar ou Devolver (com comentário). "
                    . "A exigência de aprovação é ligada em Administração > Parametrizações > Pedagógico.",
            ],
            [
                'id' => 'diario', 'area' => 'Pedagógico', 'titulo' => 'Diário de Classe (frequência, conteúdos, notas e impressão)',
                'menu' => 'Diário de Classe', 'acesso' => ['professor' => true, 'pode' => ['pedagogico.ver']],
                'palavras' => 'diario de classe imprimir pdf conteudos bimestre planejado realizado cumprimento',
                'passos' => "1. Menu Diário de Classe > escolha a turma e o bimestre.\n2. Abas: Frequência (grade de presenças), Conteúdos (registros de aula), "
                    . "Notas e Planejado × realizado.\n3. 'Imprimir / PDF' gera o diário oficial do bimestre. 'Analisar com o Max' resume o bimestre.",
            ],
            [
                'id' => 'boletim', 'area' => 'Pedagógico', 'titulo' => 'Boletim',
                'menu' => 'Boletim', 'acesso' => ['pode' => ['pedagogico.ver'], 'perfis' => ['Responsável', 'Aluno']],
                'palavras' => 'boletim notas medias bimestre ano completo imprimir exportar excel word pdf turma',
                'passos' => "1. Menu Boletim.\n2. Escolha 'Individual' (um aluno) ou 'Turma' (todos; só a equipe), e 'Por bimestre' ou 'Ano letivo completo'.\n"
                    . "3. Escolha turma/aluno (a família escolhe o filho), ano e bimestre > Aplicar.\n"
                    . "4. Exporte em Excel, imprima em Word ou, na turma, gere PDF só das médias ou completo.",
            ],
            [
                'id' => 'relatorio-frequencia', 'area' => 'Pedagógico', 'titulo' => 'Relatório de frequência (mensal/anual, por aluno)',
                'menu' => 'Minhas Turmas > Chamada > ícone de gráfico (Relatório de Frequência)', 'acesso' => ['professor' => true, 'pode' => ['pedagogico.ver']],
                'palavras' => 'relatorio frequencia percentual faltas mes ano aluno exportar pdf 75',
                'passos' => "Na tela de chamada da turma, toque no ícone de gráfico (Relatório de Frequência). Escolha Mensal ou Anual, o mês, o ano e, "
                    . "se quiser, um aluno > Exportar PDF. Abaixo de 75% de presença o aluno corre risco de reprovação por falta.",
            ],

            // ═══ Comunicação ═════════════════════════════════════════════════
            [
                'id' => 'mural', 'area' => 'Comunicação', 'titulo' => 'Mural de comunicados',
                'menu' => 'Comunicação > Mural', 'acesso' => [],
                'palavras' => 'comunicado mural aviso publicar circular recado geral confirmar leitura anexo pdf imagem fixar max escreve escrever texto redigir melhorar texto',
                'passos' => "Publicar (equipe): Comunicação > Mural > 'Escrever um comunicado'. Escolha 'Para quem' (escola toda, equipe, todas as famílias "
                    . "ou turmas), título, texto, anexo (imagem/PDF), validade, 'Pedir confirmação de leitura' e 'Fixar no topo' > Publicar. "
                    . "Professores publicam para a equipe ou para as famílias das próprias turmas; a escola toda é com a gestão. "
                    . "'Ver quem leu' mostra quem leu/confirmou.\n'Max, escreve pra mim' (abaixo do texto): escreva só as ideias (ex.: 'passeio dia "
                    . "14, sair 7h30, trazer lanche') e o Max devolve título e texto prontos; dá para pedir 'Mais curto', 'Mais formal' ou 'Mais "
                    . "acolhedor', usar ou descartar ('Desfazer' volta o rascunho). Ele não inventa datas nem valores: o que faltar vem entre "
                    . "[colchetes] para completar. Nada é publicado sem tocar em Publicar.\nFamília: lê, reage e toca em 'Confirmar leitura' quando pedido (não comenta).",
            ],
            [
                'id' => 'recados', 'area' => 'Comunicação', 'titulo' => 'Recados (conversa privada escola ↔ família)',
                'menu' => 'Comunicação > Recados', 'acesso' => [],
                'palavras' => 'recado mensagem conversa falar com professor professora secretaria familia chat privado contato escola mandar mensagem duvida',
                'passos' => "Comunicação > Recados > botão '+' (Nova conversa).\n- Equipe: fale com a família de um ou mais alunos, ou com todas as famílias de "
                    . "uma turma; dá para 'Enviar como secretaria'.\n- Família: escolha o filho e com quem falar (a secretaria ou um professor dele), "
                    . "o assunto e a mensagem > Enviar.\nAs respostas aparecem na mesma conversa e avisam no sino.",
            ],
            [
                'id' => 'ocorrencias', 'area' => 'Comunicação', 'titulo' => 'Ocorrências e anotações sobre alunos',
                'menu' => 'Comunicação > Ocorrências e anotações', 'acesso' => [],
                'palavras' => 'ocorrencia anotacao comportamento elogio saude grave atencao ciencia registro disciplinar',
                'passos' => "Equipe: Comunicação > Ocorrências e anotações > Registrar. Escolha o aluno, tipo, nível (Informativo, Atenção, Grave), título e "
                    . "descrição. Deixe como 'Anotação interna' (só a equipe vê) ou 'Compartilhar com a família' e, se quiser, peça ciência. "
                    . "'Max, escreve pra mim' reescreve o registro de forma objetiva e respeitosa (sem rótulos sobre a criança), para revisar antes de salvar.\n"
                    . "Família: vê as ocorrências compartilhadas do filho e toca em 'Estou ciente'.",
            ],

            // ═══ Secretaria ══════════════════════════════════════════════════
            [
                'id' => 'alunos', 'area' => 'Secretaria', 'titulo' => 'Cadastrar e gerenciar alunos',
                'menu' => 'Secretaria > Pessoas > Alunos', 'acesso' => ['pode' => ['pessoas.ver', 'pessoas.gerenciar']],
                'palavras' => 'aluno cadastrar matricular novo aluno inativar transferir ficha foto acesso portal login aluno exportar',
                'passos' => "Secretaria > Pessoas > aba Alunos.\n- Novo: botão '+' > dados do aluno, turma (o nº de matrícula é sugerido), responsáveis "
                    . "(pelo menos um; marque o financeiro) > Cadastrar aluno. Em seguida o sistema oferece gerar o contrato do ano.\n"
                    . "- Editar: lápis. Ficha completa: toque no aluno.\n- Inativar: desligue o status e informe o motivo (encerra a matrícula).\n"
                    . "- Acesso do aluno ao portal (login próprio): liberado na ficha do aluno.\n- Exportar para Excel: botão de download nos filtros.",
            ],
            [
                'id' => 'responsaveis', 'area' => 'Secretaria', 'titulo' => 'Cadastrar responsáveis e vincular aos alunos',
                'menu' => 'Secretaria > Pessoas > Responsáveis', 'acesso' => ['pode' => ['pessoas.ver', 'pessoas.gerenciar']],
                'palavras' => 'responsavel pai mae familia cadastrar vincular cpf telefone endereco pendencia cadastro incompleto',
                'passos' => "Secretaria > Pessoas > aba Responsáveis > '+'. CPF válido, celular e endereço completo são obrigatórios (o responsável é quem "
                    . "recebe boleto e WhatsApp). 'Falta CPF/telefone/endereço' na lista = cadastro incompleto: toque para completar. "
                    . "Vincular alunos: botão '+' na coluna Alunos.",
            ],
            [
                'id' => 'equipe', 'area' => 'Secretaria', 'titulo' => 'Professores e equipe (usuários e perfis)',
                'menu' => 'Secretaria > Pessoas > Professores / Equipe', 'acesso' => ['pode' => ['equipe.gerenciar']],
                'palavras' => 'professor cadastrar equipe usuario funcionario secretaria perfil senha acesso vincular turma disciplina',
                'passos' => "Secretaria > Pessoas > aba Professores (ou Equipe) > '+'. Para o professor, vincule turmas e disciplinas (botão '+' na coluna Turmas). "
                    . "Na Equipe, escolha o perfil (Diretoria, Coordenação, Secretaria, Tesouraria...). Desligar o status bloqueia o acesso.",
            ],
            [
                'id' => 'turmas', 'area' => 'Secretaria', 'titulo' => 'Turmas (criar, enturmar, encerrar)',
                'menu' => 'Secretaria > Turmas', 'acesso' => ['pode' => ['turmas.gerenciar', 'pedagogico.ver']],
                'palavras' => 'turma criar nova turma enturmar vincular alunos disciplinas professores encerrar reabrir sala turno capacidade',
                'passos' => "Secretaria > Turmas > '+' (nome, ano letivo, turno, sala, capacidade). Em cada turma: vincular alunos, disciplinas e professores "
                    . "(menu ⋮ no celular), editar, encerrar ou reabrir. Turma encerrada fica só para consulta (chamada, notas e registros travados) até "
                    . "um administrador reabrir. Turmas do ano novo podem ser copiadas em Parametrizações > Ano Letivo > Duplicar turmas.",
            ],
            [
                'id' => 'disciplinas', 'area' => 'Secretaria', 'titulo' => 'Disciplinas',
                'menu' => 'Secretaria > Disciplinas', 'acesso' => ['pode' => ['turmas.gerenciar']],
                'palavras' => 'disciplina materia componente curricular cadastrar vincular professor',
                'passos' => "Secretaria > Disciplinas > '+' (Adicionar Disciplina) com nome e descrição. Vincule professores pelo botão de pessoa+. "
                    . "A disciplina entra na turma em Secretaria > Turmas > vincular disciplinas.",
            ],
            [
                'id' => 'documentos', 'area' => 'Secretaria', 'titulo' => 'Emitir documentos (declarações, histórico, atas, carteirinha...)',
                'menu' => 'Secretaria > Documentos (família: Documentos)', 'acesso' => ['pode' => ['documentos.emitir'], 'perfis' => ['Responsável']],
                'palavras' => 'documento declaracao matricula frequencia escolaridade conclusao transferencia historico escolar historico de matriculas inspecao escolar numero de matricula por ano ficha ocorrencias comprovante pagamentos imposto de renda ir quitacao nada consta inscricao carteirinha ata resultados lista alunos atestado vaga imprimir pdf',
                'passos' => "1. Abra Documentos.\n2. Escolha ano, turma e aluno (a família escolhe o filho).\n3. Escolha o documento no catálogo, ajuste as opções "
                    . "(finalidade, data...) > 'Gerar documento'.\n4. Confira a prévia > 'Imprimir / Salvar PDF'.\n"
                    . "Catálogo: Declaração de Matrícula, de Frequência, de Escolaridade/Conclusão e de Transferência; Histórico Escolar; Ficha do Aluno; "
                    . "Relatório de Ocorrências; Comprovante Anual de Pagamentos (IR); Declaração Anual de Quitação; Nada Consta Financeiro; Ficha de "
                    . "Inscrição; Histórico de Matrículas (secretaria — dados do aluno e dos responsáveis e o número de matrícula, turma e situação "
                    . "de cada ano letivo, para a inspeção escolar); Carteirinha Estudantil; Ata de Resultados Finais e Lista de Alunos (da turma); Atestado de Vaga. "
                    . "Logo e dados da escola vêm de Administração > Dados da Escola.",
            ],
            [
                'id' => 'rematricula-escola', 'area' => 'Secretaria', 'titulo' => 'Abrir e acompanhar a rematrícula (online e na secretaria)',
                'menu' => 'Secretaria > Rematrícula', 'acesso' => ['pode' => ['rematricula.gerenciar']],
                'palavras' => 'rematricula renovacao matricula ano que vem proximo ano contrato aceite campanha desconto antecipado taxa matricula presencial balcao secretaria garantir vaga numero de matricula novo desconto combinado desconto individual desconto de um aluno bolsa tirar desconto',
                'passos' => "Antes: crie as turmas do ano novo (Parametrizações > Ano Letivo) e vincule um plano a cada uma (Financeiro > Contratos > Planos).\n"
                    . "1. Secretaria > Rematrícula > 'Nova rematrícula'.\n2. Ano e período; para qual turma do ano novo vai cada turma atual (é só a sugestão — "
                    . "cada aluno pode ir para outra); o que a família paga na hora (1ª parcela, taxa de matrícula ou nada) e o prazo; desconto para quem "
                    . "fizer cedo (opcional); texto do contrato (já vem um modelo com campos automáticos; use Pré-visualizar).\n3. 'Salvar e abrir para as famílias' "
                    . "(elas são avisadas no sino).\nFamília na secretaria: no acompanhamento, abra a turma e toque em 'Rematricular' no aluno > confira a turma "
                    . "do ano novo, o plano e o desconto (como num contrato novo) > 'Recebido agora' (dinheiro, Pix, cartão — gera o recibo) ou 'Pagar depois' "
                    . "(boleto/Pix no Asaas) > 'Rematricular' > 'Imprimir contrato' para a família assinar. Bolsa integral ou cobrança fora do sistema: "
                    . "'Sem cobrança neste ano' (vaga sem contrato). Funciona também depois de encerrar a campanha.\n"
                    . "Desconto só para um aluno (ex.: 10% combinado com a diretoria): no acompanhamento, toque no ícone de % do aluno > desconto "
                    . "padrão e/ou extra > Salvar. Vale quando a família faz pelo app (plano e contrato já saem com ele) e vem preenchido na secretaria. "
                    . "O padrão soma com o desconto antecipado da campanha; o extra fica no lugar dele. A tela mostra o desconto que o aluno teve no "
                    . "ano anterior — o contrato novo não herda nada: quem tinha desconto e não terá mais é só não marcar. Depois da rematrícula feita, "
                    . "para mudar o desconto é preciso cancelar e refazer.\n"
                    . "Só segue para o ano novo quem é rematriculado. A rematrícula garante a vaga: gera o contrato do ano novo e já reserva o número de "
                    . "matrícula do ano (ex.: 2027001). O aluno continua na turma atual até o encerramento do ano (Secretaria > Encerramento do Ano), "
                    . "quando muda de turma e passa a usar o número novo. Aluno que saiu no encerramento (não renovou / formado) e voltou: aparece no "
                    . "acompanhamento com 'Rematricular' e já entra direto na turma nova.\n"
                    . "No acompanhamento: confirmadas, falta pagar, sem resposta e não vão renovar, por turma (com turma reservada e número); "
                    . "'Lembrar quem falta'; ver/imprimir o contrato; cancelar uma rematrícula (cancela o contrato junto); Encerrar. Pagou → confirma sozinha.",
            ],
            [
                'id' => 'rematricula-familia', 'area' => 'Família', 'titulo' => 'Fazer a rematrícula do filho pelo app',
                'menu' => 'Cartão "Rematrícula aberta" na Home (ou aviso no sino)', 'acesso' => ['perfis' => ['Responsável']],
                'palavras' => 'rematricula renovar matricula proximo ano vaga contrato aceitar pagar nao vamos renovar',
                'passos' => "Quando a escola abre a rematrícula aparece um cartão na Home e um aviso no sino.\n1. Toque em 'Fazer a rematrícula'.\n"
                    . "2. Confira os dados > escolha o plano (com o desconto, se houver) > leia o contrato, marque 'Li e concordo' e toque em 'Aceitar o contrato'.\n"
                    . "3. Pague o valor pedido com Pix ou boleto ('Pagar com Pix ou boleto' ou copiando a linha digitável). Pagou, a vaga é confirmada sozinha.\n"
                    . "Só o responsável financeiro do aluno faz a rematrícula. Também dá para responder 'Não vamos renovar' (e depois 'Mudei de ideia').",
            ],
            [
                'id' => 'virada-ano', 'area' => 'Secretaria', 'titulo' => 'Encerramento do ano: aprovado, retido, formado, não volta',
                'menu' => 'Secretaria > Encerramento do Ano', 'acesso' => ['pode' => ['turmas.gerenciar']],
                'palavras' => 'virada de ano promocao promover promocao de alunos aprovado reter retido repetir reprovado concluir formando formado nao renovou nao volta ano novo passar de ano encerrar turma efetivar rematricula fim do ano letivo numero de matricula novo conselho de classe frequencia 75 revisao do max',
                'passos' => "Caminho da renovação: 1º Rematrícula (garante vaga, contrato e número do ano novo) → 2º Encerramento do Ano (resultado do ano; "
                    . "o aluno muda de turma aqui). Faça no fim do ano letivo, depois do resultado final. Antes: turmas do ano novo criadas "
                    . "(Parametrizações > Ano Letivo > 'Duplicar turmas') e rematrícula aberta.\n1. Secretaria > Encerramento do Ano.\n2. Escolha o ano e a turma.\n"
                    . "3. Para cada aluno, o resultado: Aprovado ou Retido (só para quem tem rematrícula — vem com a turma reservada e o número novo; as médias "
                    . "sugerem retenção quando há disciplina abaixo da média), Formado — sai (última etapa da escola) ou Não volta. Quem está sem "
                    . "rematrícula tem o botão 'Rematricular' ali mesmo (família presente). Formando que continua na escola (ex.: 3º Período → 1º Ano) é "
                    . "Aprovado, com rematrícula para a turma da etapa seguinte.\n4. 'Aplicar': quem segue entra na turma do ano novo com o número de "
                    . "matrícula novo. Retido vai para a turma escolhida (a vaga e o contrato acompanham; o sistema avisa se o plano não for o da turma). "
                    . "Rematriculado marcado para sair tem a rematrícula e o contrato cancelados. Dá para aplicar só a alguns alunos; quem fica sem decisão "
                    . "continua na turma. Quando o último sai, a turma é encerrada.\n"
                    . "Revisão do Max: o botão 'N casos para olhar' (ao lado dos contadores) mostra os alunos que merecem um segundo olhar — frequência "
                    . "abaixo de 75% (a LDB exige 75% para aprovar), retido por pouco (uma só disciplina, a menos de 1 ponto da mínima: caso de "
                    . "conselho de classe), vários alunos retidos na mesma disciplina (pode ser a avaliação) e notas faltando. Cada caso tem opções "
                    . "(ex.: 'Aprovar pelo conselho', 'Reter por frequência'); escolher destaca os alunos na lista e 'Aplicar' só marca a decisão na "
                    . "tela ('Desfazer' volta) — nada é gravado até 'Aplicar' o encerramento.",
            ],

            [
                'id' => 'quadro-horarios', 'area' => 'Pedagógico', 'titulo' => 'Quadro de horários das turmas',
                'menu' => 'Pedagógico > Quadro de Horários', 'acesso' => [],
                'palavras' => 'quadro de horarios horario de aula grade horaria grade semanal que aula tem hoje que horas aula agora proxima aula recreio intervalo sinal agenda calendario celular professor turma sala choque conflito professor em duas turmas resolver trocar horario',
                'passos' => "Todos consultam: a família e o aluno veem a grade da sua turma (e o card 'Hoje na escola' na Home mostra a aula de agora e a "
                    . "próxima); o professor vê as aulas dele na Home. Abas da equipe: Turmas (a semana de cada turma), Professores (a semana de um "
                    . "professor em todas as turmas) e Agora na escola (o que cada turma está tendo neste momento).\n"
                    . "Montar (permissão 'Montar o quadro de horários'): 1. Pedagógico > Quadro de Horários > escolha a turma > 'Editar'. 2. Escolha o "
                    . "horário da turma (Manhã, Tarde...) e os dias. 3. Arraste as disciplinas da lateral para a grade; arraste uma aula para outra casa "
                    . "para trocar; toque numa aula para mudar o professor, pôr a sala ou tirar. O número ao lado da disciplina é aulas na semana / meta "
                    . "(toque para mudar a meta). O triângulo indica professor em duas turmas no mesmo horário. 4. 'Avisar famílias' manda aviso no app.\n"
                    . "Choques de horário: quem pode editar vê, ao lado do triângulo, o botão 'Deixa que eu resolvo' (do Max). Ele lista cada choque com até 3 "
                    . "opções de troca (as que mexem menos primeiro; nenhuma cria choque novo) e, se houver vários, 'Resolver ... de uma vez'. Tocar numa "
                    . "opção mostra na grade como fica (as aulas que mudam piscam); nada muda até tocar em 'Aplicar', e 'Desfazer' volta como estava. "
                    . "Quando trocar não resolve (ex.: o mesmo professor com todas as aulas de duas turmas no mesmo turno), o Max explica que uma das "
                    . "turmas precisa de outro professor.\n"
                    . "Horários de aula e intervalo (o 'sinal'): botão 'Horários e intervalos' (no modo Editar) — um horário serve para várias turmas; 'Montar rápido' cria as faixas pelo início, número de aulas, duração (40, 45, 50, 60 min) e intervalos (10, 15, 20, 30 min); mudar a duração de um intervalo empurra os horários seguintes.\n"
                    . "'Adicionar à agenda' (ícone de calendário) baixa as aulas para o calendário do celular, sem os feriados; o ícone de impressora imprime a grade.",
            ],

            // ═══ Financeiro ══════════════════════════════════════════════════
            [
                'id' => 'financeiro-visao', 'area' => 'Financeiro', 'titulo' => 'Visão geral do financeiro',
                'menu' => 'Financeiro > Visão geral', 'acesso' => ['pode' => ['financeiro.operar']],
                'palavras' => 'financeiro resumo previsto recebido inadimplencia em aberto mes receita',
                'passos' => 'Financeiro > Visão geral: previsto e recebido no mês, em aberto, % de inadimplência, recebido nos últimos 12 meses e '
                    . "'O que precisa de atenção' (ex.: alunos sem contrato), com atalhos para cada área.",
            ],
            [
                'id' => 'cobrancas', 'area' => 'Financeiro', 'titulo' => 'Cobranças: dar baixa, editar parcela, boleto e recibo',
                'menu' => 'Financeiro > Cobranças', 'acesso' => ['pode' => ['financeiro.operar']],
                'palavras' => 'parcela mensalidade baixa pagamento receber registrar pagamento editar vencimento desconto boleto segunda via recibo',
                'passos' => "Financeiro > Cobranças (aba Parcelas): busque o aluno ou filtre por status, turma e período.\n- Dar baixa: botão 'Dar baixa' > "
                    . "forma, data e valor (encargos de atraso calculados) > confirmar; gera recibo.\n- Editar parcela: lápis (valor, desconto, vencimento).\n"
                    . "- Boleto: Asaas (link da fatura) ou Sicoob (2ª via), conforme o modo de cobrança.\n- Recibo: botão Recibo nas pagas.\n"
                    . "Os cartões no topo filtram por Pagas, A vencer, Vencidas e Renegociadas.",
            ],
            [
                'id' => 'inadimplencia', 'area' => 'Financeiro', 'titulo' => 'Inadimplência: receber e renegociar atrasados',
                'menu' => 'Financeiro > Cobranças > aba Inadimplência', 'acesso' => ['pode' => ['financeiro.operar']],
                'palavras' => 'inadimplencia atrasado devedor vencido renegociar acordo multa juros encargos cobrar',
                'passos' => "Financeiro > Cobranças > aba Inadimplência: parcelas vencidas com dias de atraso e valor com encargos.\n- Receber: registra o "
                    . "pagamento com multa e juros.\n- Renegociar: mostra os encargos, permite desconto neles e define o novo vencimento/valor; a parcela "
                    . "original vira 'Renegociada' e nasce uma nova (com comprovante).",
            ],
            [
                'id' => 'contratos', 'area' => 'Financeiro', 'titulo' => 'Contratos (matrícula financeira) e alunos sem contrato',
                'menu' => 'Financeiro > Contratos', 'acesso' => ['pode' => ['financeiro.operar']],
                'palavras' => 'contrato matricula financeira gerar parcelas plano desconto bolsa sem contrato sem cobranca cancelar contrato por turma lote',
                'passos' => "Financeiro > Contratos.\n- 'Novo contrato': aluno, ano, plano, descontos e início > gera as parcelas.\n"
                    . "- 'Por turma': gera contratos de uma turma inteira com desconto por aluno.\n- Aba 'Sem contrato': alunos do ano sem contrato; "
                    . "confira o plano sugerido, selecione e gere em lote, ou marque 'Sem cobrança' (bolsa integral, filho de funcionário).\n"
                    . "- Aba 'Sem cobrança': dispensados (dá para desfazer).\n- Na lista de contratos, toque para ver as parcelas e cancelar/excluir.",
            ],
            [
                'id' => 'planos', 'area' => 'Financeiro', 'titulo' => 'Planos de pagamento',
                'menu' => 'Financeiro > Contratos > aba Planos', 'acesso' => ['pode' => ['financeiro.configurar']],
                'palavras' => 'plano pagamento mensalidade valor anual parcelas dia vencimento criar plano sugerir turma',
                'passos' => "Financeiro > Contratos > aba Planos > 'Novo Plano': nome, valor total, nº de parcelas e dia de vencimento. Em 'Sugerido para', "
                    . "vincule às turmas (ou 'Sugerir para as turmas'): é o plano que aparece nos contratos e na rematrícula daquela turma.",
            ],
            [
                'id' => 'central-asaas', 'area' => 'Financeiro', 'titulo' => 'Central Asaas (boleto com Pix e baixa automática)',
                'menu' => 'Financeiro > Central Asaas', 'acesso' => ['pode' => ['financeiro.operar']],
                'palavras' => 'asaas boleto pix emitir cobranca automatica pagadores sincronizar baixa automatica webhook',
                'passos' => "Aparece quando a escola cobra pelo Asaas. Abas: Emitir (parcelas que vencem nos próximos dias, com pendências de cadastro), "
                    . "Cobranças (situação de cada boleto), Pagadores e Avisos. 'Sincronizar com o Asaas' atualiza na hora. Com a emissão automática "
                    . "ligada, os boletos saem sozinhos todo dia; quando a família paga, a baixa e o recibo são automáticos. "
                    . "Chave da API e emissão automática: Administração > Parametrizações > Financeiro.",
            ],
            [
                'id' => 'central-sicoob', 'area' => 'Financeiro', 'titulo' => 'Central Sicoob (remessa e retorno de boletos)',
                'menu' => 'Financeiro > Central Sicoob', 'acesso' => ['pode' => ['financeiro.operar']],
                'palavras' => 'sicoob remessa retorno cnab arquivo banco boleto',
                'passos' => "Aparece quando a escola cobra por boleto Sicoob em arquivo: 1) gerar a remessa, 2) marcar como enviada ao banco, 3) importar o "
                    . "arquivo de retorno (dá as baixas). Histórico de remessas e retornos na mesma tela.",
            ],
            [
                'id' => 'recibos', 'area' => 'Financeiro', 'titulo' => 'Recibos',
                'menu' => 'Financeiro > Recibos', 'acesso' => ['pode' => ['financeiro.operar']],
                'palavras' => 'recibo segunda via imprimir comprovante pagamento',
                'passos' => 'Financeiro > Recibos: busque por aluno ou nº do recibo, filtre por período e toque em imprimir. Cada baixa gera um recibo automaticamente.',
            ],
            [
                'id' => 'meus-pagamentos', 'area' => 'Família', 'titulo' => 'Pagar mensalidade (Pix/boleto) e ver recibos',
                'menu' => 'Meus Pagamentos', 'acesso' => ['perfis' => ['Responsável']],
                'palavras' => 'pagar mensalidade boleto pix copia e cola codigo de barras recibo parcela atrasada segunda via',
                'passos' => "Menu Meus Pagamentos: escolha o filho; veja a próxima mensalidade, o contrato e todas as parcelas.\n- Pagar: 'Pagar com Pix ou boleto' > "
                    . "copie o código Pix (ou leia o QR Code no app do banco), baixe o boleto em PDF ou copie o código de barras.\n- Recibo: nas parcelas pagas.\n"
                    . "O pagamento é reconhecido sozinho; não precisa enviar comprovante.",
            ],

            // ═══ Administração ═══════════════════════════════════════════════
            [
                'id' => 'dados-escola', 'area' => 'Administração', 'titulo' => 'Dados da escola e logo',
                'menu' => 'Administração > Dados da Escola', 'acesso' => ['pode' => ['configuracoes.escola']],
                'palavras' => 'dados escola nome cnpj endereco logo diretor mec telefone email cabecalho',
                'passos' => 'Administração > Dados da Escola: nome, CNPJ, endereço, autorização do MEC, diretor(a), telefone, e-mail e logo. Usados nos documentos, no contrato da rematrícula e no topo do sistema.',
            ],
            [
                'id' => 'ano-letivo', 'area' => 'Administração', 'titulo' => 'Ano letivo, bimestres e turmas do ano novo',
                'menu' => 'Administração > Parametrizações > Ano Letivo', 'acesso' => ['pode' => ['configuracoes.escola']],
                'palavras' => 'ano letivo bimestre semestre datas novo ano duplicar turmas copiar turmas encerrar turmas virada',
                'passos' => "Parametrizações > Ano Letivo: 'Novo ano letivo'; 'Editar períodos' para as datas dos bimestres (e semestres da BNCC); "
                    . "'Duplicar turmas' copia as turmas de um ano para o outro (opção de manter os professores); 'Encerrar turmas' trava o ano que acabou.",
            ],
            [
                'id' => 'calendario', 'area' => 'Administração', 'titulo' => 'Calendário escolar (feriados, recessos, sábados letivos)',
                'menu' => 'Administração > Parametrizações > Calendário Escolar', 'acesso' => ['pode' => ['calendario.editar']],
                'palavras' => 'calendario feriado recesso sabado letivo ponto facultativo evento dias letivos imprimir',
                'passos' => "Parametrizações > Calendário Escolar: toque num dia > 'Nova marcação' (Feriado, Recesso, Sábado letivo, Ponto facultativo, Evento; "
                    . "pode ser um período). 'Feriados nacionais' preenche o ano. Mostra os dias letivos previstos e 'Imprimir' gera o calendário anual. "
                    . "O calendário define os dias com aula para chamada, painéis e diário.",
            ],
            [
                'id' => 'modelos-avaliacao', 'area' => 'Administração', 'titulo' => 'Modelos de avaliação (composição da nota)',
                'menu' => 'Administração > Parametrizações > Modelos de Avaliação', 'acesso' => ['pode' => ['configuracoes.escola']],
                'palavras' => 'modelo avaliacao composicao nota peso tipo de avaliacao prova bimestral trabalho media pontos',
                'passos' => "Parametrizações > Modelos de Avaliação > 'Novo modelo': adicione os tipos (ex.: Prova 6 + Trabalho 4) — os pontos somam 10 — e "
                    . "escolha para quais turmas vale. O tipo define o valor da avaliação quando o professor cadastra.",
            ],
            [
                'id' => 'param-pedagogico', 'area' => 'Administração', 'titulo' => 'Exigir aprovação dos planejamentos',
                'menu' => 'Administração > Parametrizações > Pedagógico', 'acesso' => ['pode' => ['configuracoes.escola']],
                'palavras' => 'aprovacao planejamento exigir direcao coordenacao pedagogico configurar',
                'passos' => 'Parametrizações > Pedagógico: ligue a aprovação para que os planos de aula passem pela direção/coordenação (padrão: desligada).',
            ],
            [
                'id' => 'param-financeiro', 'area' => 'Administração', 'titulo' => 'Configurar o financeiro (modo de cobrança, multa, juros, Asaas)',
                'menu' => 'Administração > Parametrizações > Financeiro', 'acesso' => ['pode' => ['financeiro.configurar']],
                'palavras' => 'configurar financeiro multa juros carencia asaas chave api sandbox producao sicoob convenio controle manual contrato na matricula emissao automatica',
                'passos' => "Parametrizações > Financeiro: como a escola cobra (Controle manual, Boleto com Pix pelo Asaas ou Boleto Sicoob por arquivo); "
                    . "multa, juros e carência de atraso (com simulação); contrato oferecido no cadastro e na virada; conta Asaas (chave, Sandbox/Produção, "
                    . "aviso automático de pagamento, emitir automaticamente) ou convênio Sicoob > 'Salvar parâmetros'.",
            ],
            [
                'id' => 'param-max', 'area' => 'Administração', 'titulo' => 'Configurar o Max (briefing diário)',
                'menu' => 'Administração > Parametrizações > Assistente Max', 'acesso' => ['pode' => ['configuracoes.escola']],
                'palavras' => 'max configurar briefing diario ia recursos uso limite',
                'passos' => 'Parametrizações > Assistente Max: briefing diário da direção e recursos de IA; o uso do Max por pessoa aparece na Home da direção ("Uso do Max").',
            ],
            [
                'id' => 'permissoes', 'area' => 'Administração', 'titulo' => 'Permissões dos perfis',
                'menu' => 'Administração > Permissões', 'acesso' => ['perfis' => ['Administrador']],
                'palavras' => 'permissao perfil liberar acesso retirar acesso secretaria tesouraria coordenacao diretoria excecao',
                'passos' => "Administração > Permissões (só Administrador): escolha o perfil e ligue/desligue cada área (liberada ou retirada por exceção); "
                    . "'Voltar ao padrão' desfaz as exceções > Salvar.",
            ],
            [
                'id' => 'whatsapp', 'area' => 'Administração', 'titulo' => 'Avisos por WhatsApp para as famílias',
                'menu' => 'Administração > Notificações WhatsApp', 'acesso' => ['pode' => ['whatsapp.gerenciar']],
                'palavras' => 'whatsapp aviso notificacao familia telefone ligar desligar envio automatico historico',
                'passos' => "Administração > Notificações WhatsApp: Visão geral (quem recebe, 'Ligar para os que têm telefone', 'Completar telefones', envio "
                    . "automático e 'Rodar o envio agora'), Responsáveis (ligar/desligar por pessoa) e Histórico de envios (com falhas).",
            ],
            [
                'id' => 'logs', 'area' => 'Administração', 'titulo' => 'Logs do sistema (auditoria)',
                'menu' => 'Administração > Logs do Sistema', 'acesso' => ['pode' => ['logs.ver']],
                'palavras' => 'log auditoria quem alterou historico acesso sessao exportacao seguranca',
                'passos' => 'Administração > Logs do Sistema: Visão geral (eventos, usuários, sessões, alterações, logins recusados), Eventos e Sessões; filtre o período e exporte em CSV.',
            ],
        ];
    }
}
