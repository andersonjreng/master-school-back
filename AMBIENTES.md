# Ambientes — MasterSchool (Laravel)

Este documento explica como funcionam os ambientes de teste local, o deploy
manual (sem SSH) e a arquitetura de produção multi-escola. É o ponto de
referência pra retomar o trabalho depois de um tempo parado.

## Visão geral

O backend está sendo migrado, módulo por módulo, do PHP legado (pasta `api/`
no repositório `master-school`, que também contém o Angular) pra este projeto
Laravel novo (`max-agent-laravel`, sem remote no GitHub por enquanto —
decisão do usuário, "só local por enquanto").

**Multi-tenant por banco de dados**: uma escola = um banco MySQL. O mesmo
código Laravel atende todas as escolas — resolvido pelo subdomínio da
requisição em `App\Http\Middleware\ResolveTenantDatabase` (registrado como
middleware global em `bootstrap/app.php`), que troca a conexão do banco
conforme `config/tenants.php` (alimentado por variáveis `TENANT_*_HOST` /
`TENANT_*_DB` no `.env`). Fora de um host mapeado, usa `DB_DATABASE` do
`.env` normalmente — é por isso que o ambiente local não precisa de nenhuma
configuração especial de tenant.

⚠️ **Bug grave encontrado e corrigido em 2026-09-30**: o multi-tenant por
banco **nunca funcionou de verdade em produção**, desde que foi implantado.
Duas causas, descobertas testando o login real do `criarte` (que só criou
em 2026-09-28 — por isso não tinha aparecido antes):
1. **`config/tenants.php` nunca tinha sido enviado ao servidor** em nenhum
   deploy anterior (os deploys depois do inicial foram sempre parciais —
   só a pasta `public/`, só o `.env`, só controllers específicos — nunca um
   redeploy completo do código). `config('tenants.hosts')` retornava `NULL`
   em produção, então `ResolveTenantDatabase` nunca trocava o banco pra
   NENHUM host. `teste.portalmasterschool.com.br` só parecia funcionar
   porque o banco padrão do `.env` (`DB_DATABASE`) já é coincidentemente o
   mesmo do tenant teste — `cepelc` e `criarte` estavam **caindo
   silenciosamente no banco de teste** o tempo todo. Corrigido com um
   redeploy completo (`git archive HEAD | gzip` → `scp` → extrair por cima
   do deploy existente, sem tocar `.env`/`vendor`/`storage` — nenhum
   arquivo tracked é removido, só sobrescrito/adicionado) + `php artisan
   config:clear`. **Lição**: depois de qualquer deploy parcial, não dá pra
   assumir que o código do servidor está 100% sincronizado com o git — o
   jeito confiável de verificar é testar o comportamento real (nesse caso,
   só apareceu testando tenant-switching de verdade pela primeira vez).
2. **Usuário de banco sem permissão no banco real do cepelc**: mesmo com o
   tenant resolvendo certo, `ande2326_laravel_app` nunca tinha `GRANT` em
   `ande2326_master_school_db` (só tinha em teste e no criarte recém-criado)
   — dava 500 (`Access denied`). Corrigido via `uapi Mysql
   set_privileges_on_database` (ação sensível, feita só após confirmação
   explícita do usuário — o modo automático bloqueou a tentativa inicial
   por ser uma mudança de permissão em banco de produção).

Confirmado depois do fix: `teste` segue dando os mesmos 99 alunos de
sempre, `criarte` dá 0 (banco genuinamente vazio) e `cepelc` dá 102 (dados
reais, antes inacessíveis) — os três bancos agora realmente isolados entre
si.

Escolas conhecidas:
- **cepelc** — única escola oficialmente em produção hoje. O **acesso real
  dos usuários continua 100% no PHP legado**
  (`portalmasterschool.com.br/cepelc/`), sem nenhuma mudança. Banco real:
  `ande2326_master_school_db` (não é intuitivo pelo nome — confirmado lendo
  o `config.php` real em produção, `/home2/ande2326/portalmasterschool.com.br/cepelc/api-teste/config.php`).
  Desde 2026-09-28 existe também `cepelc.portalmasterschool.com.br`
  (Laravel+Angular novo, mesmo padrão do `teste`), conectado a esse MESMO
  banco real — mas é **só um ambiente de teste em paralelo**, pra validar o
  backend novo com dados reais antes de decidir migrar o acesso de verdade.
  **Nunca apontar usuários reais pra esse subdomínio sem decisão explícita**
  — os dois sistemas (legado + Laravel) escrevendo no mesmo banco ao mesmo
  tempo por usuários diferentes seria arriscado.
- **teste** — ambiente de testes, mesmo propósito do `portalmasterschool.com.br/teste/api-teste`
  legado. Banco real no servidor: `ande2326_master_school_teste_db`.
- **criarte** — escola nova, entra em produção ano que vem. Subdomínio
  `criarte.portalmasterschool.com.br` criado em 2026-09-28 (mesma conta
  `ande2326`, sem hospedagem separada), banco novo e **vazio**
  `ande2326_master_school_criarte_db` — schema clonado via `mysqldump
  --no-data` do banco `teste` (56 tabelas, já com todas as correções de
  portabilidade aplicadas). Usuário admin inicial criado em 2026-09-30
  (`admin@criarte.com`, papel Administrador) — login testado e funcionando.
  As tabelas de referência (`funcoes` etc.) também precisaram ser semeadas
  manualmente, já que o `--no-data` não traz linhas nem de tabelas de
  lookup.
- **Colégio Batista** — era cliente, não é mais. Ignorar (o código legado
  ainda tem referências a ele em `api/config.php`, mas não precisa suportar).

## Ambiente local

Stack: XAMPP (só o MySQL) + Laravel via Octane/RoadRunner + Angular via `ng serve`.

### Backend (`max-agent-laravel`)

- Banco: MySQL do XAMPP, schema `master_school_teste`, importado do dump
  mais atualizado em `master-school/db/export/`.
- `.env` local aponta `DB_DATABASE=master_school_teste` — sem variáveis
  `TENANT_*` setadas (o middleware de tenant não interfere, cai no default).
- **Servidor de dev: Laravel Octane + RoadRunner, não `php artisan serve`.**
  O `php artisan serve` no Windows não tem `pcntl`, atende uma requisição
  por vez — virou gargalo real quando a maior parte do backend já estava
  migrada. Pra subir o backend local:

  ```powershell
  .\serve-octane.ps1
  ```

  (na raiz do projeto). Isso substitui `php artisan serve` mantendo a mesma
  porta (8010), então o `environment.ts` do Angular não precisa mudar.

  **Gotcha de Windows**: `php artisan octane:start` trava com erro fatal —
  usa as constantes `SIGINT`/`SIGTERM`/`SIGHUP` do `pcntl` sem checar se a
  extensão existe, e o PHP pra Windows nunca teve `pcntl`. O
  `serve-octane.ps1` contorna isso rodando o `rr.exe` (binário Go) direto,
  sem passar pelo comando Artisan.

  **Importante**: diferente do `php artisan serve`, o Octane mantém a
  aplicação carregada em memória entre requisições. **Qualquer mudança de
  código (PHP, rotas, `.env`) só é aplicada depois de reiniciar o processo**
  (Ctrl+C e rodar `.\serve-octane.ps1` de novo).

  `rr.exe` (binário, ~63MB, específico do Windows) fica fora do git — cada
  ambiente baixa o seu via `php artisan octane:install --server=roadrunner`
  (mesmo que esse comando trave no bug do pcntl acima, o binário já foi
  baixado antes do erro).

### Frontend (`master-school`, repo separado)

```
ng serve
```

`src/environments/environment.ts` tem `laravelApiUrl` apontando pra
`http://127.0.0.1:8010/api` — cada módulo migrado deriva sua URL dali
(`documentosApiUrl`, `financeiroApiUrl` etc.). Módulos ainda não migrados
continuam apontando pro PHP legado em produção
(`https://portalmasterschool.com.br/teste/api-teste`).

## O que já foi migrado / o que falta

Migrados: Max (agente de IA), Documentos, Frequência, Registros de Aula,
Avaliações, Professores, Alunos/Responsáveis, Financeiro (núcleo + boleto
Sicoob), Auth, WhatsApp (testar/histórico + cron diário), Admin, BNCC,
Escola (leitura + escrita). **Backend com paridade completa** em relação a
tudo que o frontend Angular usa. **Front (Angular) já em produção** dividindo
subdomínio com o back em `teste.portalmasterschool.com.br` (ver seção "Front
+ back no mesmo subdomínio" abaixo) — `cepelc` e `criarte` ainda não migrados
pra esse esquema.

### Cron diário do WhatsApp (`whatsapp:notificacoes-diarias`)

Substitui `api/whatsapp/enviar_notificacoes_diarias.php`. Diferente do
legado (2 bancos fixos hardcoded em `config.php`), itera todo banco
distinto em `config('tenants.hosts')` (ou seja, escala sozinho conforme
`cepelc`/`criarte` forem configurados em `TENANT_*_DB`) + o banco padrão
do `.env`.

Configurar no cPanel → **Cron Jobs** (não precisa do `schedule:run` do
Laravel nem de rodar a cada minuto — só uma entrada direta, mesmo padrão do
cron legado):

```
0 18 * * * php /home2/ande2326/laravel-deploy/artisan whatsapp:notificacoes-diarias >> /home2/ande2326/laravel-deploy/storage/logs/whatsapp-cron.log 2>&1
```

(horário sugerido: 18h, ajustar conforme a rotina real da escola).

## Deploy no servidor

A conta cPanel é `ande2326` (Hostgator, servidor `br48.hostgator.com.br`).

**SSH liberado em 2026-09-27** (pedido pro suporte da Hostgator — o Plano M
não tem SSH habilitado por padrão, e o servidor rejeitava a conexão antes
mesmo da autenticação com `"Not allowed at this time"`; o suporte confirmou
e habilitou manualmente). Chave privada salva em `~/.ssh/hostgator_ande2326`
nesta máquina (fora de qualquer repositório — nunca commitar).

```bash
ssh -i ~/.ssh/hostgator_ande2326 -p 22 ande2326@br48.hostgator.com.br
```

⚠️ **Porta 22, não 2222** — 2222 é recusada nessa conta (motivo desconhecido,
só a 22 funciona). Se o SSH voltar a falhar no futuro, é o primeiro lugar
pra verificar antes de reabrir chamado com o suporte.

Com SSH, o deploy vira trivial: `scp`/`rsync` pra copiar arquivos, e
`php artisan migrate/storage:link/...` direto no servidor, exatamente como
localmente. As seções abaixo sobre FTP/Gerenciador de Arquivos ficam como
**plano B**, caso o acesso SSH seja revogado de novo — o histórico de como
resolvemos isso sem SSH (achado real: zip feito no Windows não preserva
permissões Unix, tinha que corrigir na unha) fica registrado por precaução.

### Estrutura no servidor

```
/home2/ande2326/
├── portalmasterschool.com.br/   ← domínio principal, NÃO tocar
│   ├── cepelc/                  ← front do cepelc (PHP legado + Angular)
│   ├── teste/                   ← front/API de teste legado
│   └── ...
└── laravel-deploy/              ← projeto Laravel novo, FORA da pasta pública
    ├── app/, bootstrap/, config/, ...
    ├── vendor/
    ├── .env                     ← produção, nunca committar
    └── public/                  ← só esta pasta é exposta na web
```

**Por quê fora da pasta pública**: o `.env` do Laravel tem senha de banco,
segredo JWT, tokens — não pode ficar em pasta acessível por URL. Só
`laravel-deploy/public/` deve ser o Document Root de qualquer subdomínio.

### Conta FTP

Criada uma conta restrita só a essa pasta: `laravel-deploy@portalmasterschool.com.br`,
host `ftp.andersonjrdev.com.br`, porta 21. Serve só pra esse deploy — trocar
a senha ou excluir a conta quando não precisar mais (a senha ficou registrada
na conversa com o Claude).

### Passo a passo do deploy (repetir a cada atualização de código)

1. Localmente, exportar o código commitado (sem histórico do git):
   ```bash
   git archive HEAD | tar -x -C /caminho/de/staging
   ```
2. Dentro do staging, instalar dependências de produção:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Remover o que é só de dev local: `tests/`, `phpunit.xml`, `serve-octane.ps1`, `.rr.yaml`.
4. Zipar (⚠️ **zip feito no Windows via `Compress-Archive` não preserva
   permissões Unix** — isso já causou um 500 em produção por
   `vendor/` inteiro vir sem permissão de leitura/execução depois de
   extraído pelo Gerenciador de Arquivos. Depois de extrair, sempre conferir
   se o app sobe; se der 500 "Permission denied", é isso).
5. Subir o zip via FTP, extrair pelo Gerenciador de Arquivos, apagar o zip.
6. Subir o `.env` de produção separado (nunca dentro do zip).
7. Conferir/reaplicar permissões: `755` em `app/`, `bootstrap/`, `config/`,
   `database/`, `public/`, `resources/`, `routes/`, `vendor/` (recursivo —
   pelo Gerenciador de Arquivos, selecionar **uma pasta por vez**, não em
   lote, senão a opção de recursão não aparece); `775` em `storage/` e
   `bootstrap/cache/` (o Laravel precisa escrever ali).
8. Sem `php artisan migrate` disponível via CLI: usar um Cron Job (cPanel
   sempre permite, mesmo sem Terminal) rodando o comando artisan uma única
   vez, ou um script PHP temporário em `public/` que roda o comando e é
   apagado depois.

### `.env` de produção — variáveis específicas de cada tenant

```
DB_DATABASE=ande2326_master_school_teste_db   # banco do tenant default/fallback
DB_USERNAME=ande2326_laravel_app               # usuário dedicado, criado só pro Laravel
DB_PASSWORD=...

TENANT_TESTE_HOST=teste.portalmasterschool.com.br
TENANT_TESTE_DB=ande2326_master_school_teste_db
# TENANT_CEPELC_HOST=cepelc.portalmasterschool.com.br
# TENANT_CEPELC_DB=
# TENANT_CRIARTE_HOST=criarte.portalmasterschool.com.br
# TENANT_CRIARTE_DB=
```

`JWT_SECRET`, `ANTHROPIC_API_KEY`, `WHATSAPP_TOKEN` etc. são os mesmos
valores já usados pelo PHP legado (decisão do usuário: não separar
chave de teste/produção por enquanto).

### Subdomínios (cPanel → Domínios/Subdomínios)

Cada escola ganha um subdomínio próprio. Front e back dividem o mesmo
subdomínio (ver seção abaixo), em vez do padrão antigo de pasta
(`portalmasterschool.com.br/cepelc/`). O `teste.portalmasterschool.com.br`
já está assim (Document Root em `teste-front/`, ver detalhes abaixo).

⚠️ A interface nova e unificada de "Domínios" do cPanel tem um bug ao tentar
excluir um subdomínio criado com Document Root errado (erro de domínio
duplicado). Nesse caso, usar a interface clássica "Subdomínios" (buscar por
esse nome exato na busca do cPanel) — a exclusão funciona direito por lá.

AutoSSL do cPanel demora um pouco (minutos a horas, às vezes só minutos —
em 2026-09-28 os subdomínios `cepelc`/`criarte` propagaram em menos de 30min)
pra emitir certificado pra um subdomínio recém-criado — um erro de SSL/SNI
logo depois de criar é esperado, não é bug. **A mesma propagação também
pode se manifestar como um `302` redirecionando pra `/404.html`, inclusive
por HTTP puro (sem TLS)** — não é só coisa de certificado, é o vhost em si
ainda não totalmente sincronizado no Apache do servidor. Testar de novo
depois de esperar; se quiser automatizar a espera, um loop simples
(`curl -sk -o /dev/null -w "%{http_code}" https://X/ | grep -q 200`) resolve.

Criar via `uapi SubDomain addsubdomain domain=<nome> rootdomain=portalmasterschool.com.br dir=<pasta>`
(mais confiável que a UI, principalmente com o SSH instável — ver nota
sobre isso na seção "Front + back no mesmo subdomínio").

### Front + back no mesmo subdomínio (implementado em `teste`, 2026-09-27)

Decisão: cada escola tem um subdomínio só, servindo front e back juntos —
mesma origem, sem precisar de CORS entre eles.

**Como ficou (testado e funcionando em `teste.portalmasterschool.com.br`)**:

- Document Root do subdomínio aponta pra uma pasta só com o **build do
  Angular** (`teste-front/`), trocado via
  `uapi SubDomain changedocroot domain=<subdominio> docroot=<pasta>`
  (o parâmetro certo é `docroot`, não `dir` — `uapi --help` lista errado/
  incompleto, só o erro de "Provide the docroot argument" revela o nome certo).
- **Não** usamos `mod_rewrite` pra apontar pra um caminho absoluto fora da
  pasta pública (abordagem inicialmente cogitada, mas `Alias` não é permitido
  em `.htaccess` e reescrever pra um caminho fora do Document Root é frágil
  em mod_rewrite). Em vez disso, **symlinks** dentro de `teste-front/`:
  - `teste-front/backend -> laravel-deploy/public`
  - `teste-front/storage -> laravel-deploy/public/storage`
  Isso funciona porque o `.htaccess` do próprio Laravel (dentro de
  `laravel-deploy/public/`) continua sendo aplicado normalmente pelo Apache
  ao percorrer o link simbólico (mesmo dono do link e do alvo, então
  `FollowSymLinks`/`SymLinksIfOwnerMatch` não bloqueia).
- ⚠️ **Bug real encontrado e corrigido**: a primeira tentativa nomeou o
  symlink de `api` (nome óbvio, mas errado) e deu 404 em toda rota
  (`"The route auth/login could not be found."`). Causa: o Laravel já
  registra `routes/api.php` com prefixo `/api` automaticamente
  (`withRouting(api: ...)` em `bootstrap/app.php`). Quando o symlink também
  se chama `api`, o Apache monta `SCRIPT_NAME=/api/index.php` ao executar o
  `index.php` através dele, e o Symfony (usado por baixo do Laravel) calcula
  o `baseUrl` da requisição comparando `REQUEST_URI` com o diretório de
  `SCRIPT_NAME` — encontra `/api` nos dois e **descarta esse prefixo do
  path antes do roteador do Laravel ver a requisição**, sobrando só
  `auth/login`, que não bate com nenhuma rota registrada (que espera
  `api/auth/login`). Corrigido nomeando o symlink como `backend` (qualquer
  nome que não apareça literalmente na URL resolve — o importante é o
  `SCRIPT_NAME` não compartilhar prefixo com o `REQUEST_URI`) e reescrevendo
  `/api/*` pra ele no `.htaccess` do front, em vez de deixar a URL bater
  direto no symlink.
- ⚠️ **2º bug real encontrado e corrigido, mais sutil**: depois do bug acima
  corrigido, login funcionava mas `alunos`/`professores`/`responsaveis`
  (e por extensão qualquer módulo que segue o mesmo padrão de service)
  continuavam dando `"The route alunos could not be found."` **só em
  produção**, mesmo com a rota certa. Causa: o `aluno.service.ts` (e outros)
  chamava a API com uma **barra final solta** (`` `${alunosApiUrl}/` ``),
  hábito que nunca deu problema no dev local (Octane não usa Apache/
  `.htaccess`) mas em produção cai no redirect padrão "remove trailing
  slash" do próprio `.htaccess` do Laravel — que, por causa do symlink
  `backend`, redirecionava pra `/backend/alunos` (nome interno vazando na
  URL) em vez de `/api/alunos`, e essa URL vazada não batia com nenhuma
  rota. Pior: em `POST`/`PUT`, um redirect 301 é convertido pelo **navegador**
  em `GET` automaticamente, derrubando o body inteiro — ou seja, mesmo
  corrigindo só o redirect, criar/editar aluno continuaria quebrado
  silenciosamente (o front acharia que deu certo, mas teria batido no
  endpoint de listagem, não no de criação). Corrigido em duas frentes:
  1. **Raiz do problema**: removida a barra final solta em todas as 12
     chamadas afetadas (`aluno.service.ts`, `professor.service.ts`,
     `responsavel.service.ts`, `avaliacao.service.ts`,
     `frequencia.service.ts`) — as rotas do Laravel nunca a exigiram.
  2. **Rede de segurança** no `.htaccess`: o redirect de barra final só
     acontece pra `GET` (nunca pra `POST`/`PUT`/`DELETE`, que agora dão 404
     limpo em vez de silenciosamente virar outro request), e usa o path
     *original* (`/api/%1`) em vez de deixar o `.htaccess` do Laravel gerar
     o redirect (que vazaria `backend/`).
- `.htaccess` na raiz de `teste-front/` (versão final, com as duas
  correções acima):
  ```apache
  RewriteEngine On

  # Normaliza barra final ANTES de repassar pro Laravel (so em GET: um 301
  # em POST/PUT vira GET no navegador e derruba o body silenciosamente).
  # Sem isso, o .htaccess do Laravel redireciona usando o path JA reescrito
  # (backend/alunos em vez de api/alunos), vazando o nome interno do
  # symlink numa URL que nao bate com nenhuma rota. O frontend Angular ja
  # nao manda mais barra final nessas chamadas — isso aqui e so rede de
  # seguranca pra qualquer caso futuro.
  RewriteCond %{REQUEST_METHOD} =GET
  RewriteCond %{REQUEST_URI} ^/api/(.+)/$
  RewriteRule ^ /api/%1 [R=301,L]

  # /api/* -> Laravel via symlink 'backend' (ver nota do 1º bug acima: NAO
  # pode se chamar 'api', colide com o prefixo interno de rota do Laravel)
  RewriteRule ^api/(.*)$ backend/$1 [L]

  # /storage/* -> arquivos estaticos do Laravel (nao passa por PHP, sem o
  # problema de SCRIPT_NAME acima)
  RewriteRule ^storage/(.*)$ storage/$1 [L]

  # Angular SPA fallback
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ index.html [L]
  ```
- **Lição pra próximos módulos/telas**: nunca chamar a API com barra final
  solta (`` `${xApiUrl}/` ``) — sempre `xApiUrl` puro para o endpoint base.
  Isso é invisível no dev local (Octane) mas quebra em produção (Apache).
- Deploy do build: `ng build` local → `tar -czf` da pasta
  `dist/master-school/browser/` (inclui o `.htaccess` acima) → `scp` do
  tarball único pro servidor → extrair via SSH direto em `teste-front/` (bem
  mais confiável que `scp -r` arquivo por arquivo, dado que a conexão SSH
  deste servidor cai com frequência — ver nota abaixo).

⚠️ **SSH desse servidor (br48.hostgator.com.br) cai com frequência** —
não é específico de nenhum comando (confirmado testando `echo` puro em
sequência: ~metade das conexões fecha com "Connection closed by ... port
22" ou às vezes "Connection refused"). Não é rate-limit óbvio (não piora com
mais tentativas nem melhora com espera). Mitigação: sempre envolver comandos
remotos importantes num laço de retry (3-6 tentativas, alguns segundos de
intervalo) e, pra transferência de arquivos, preferir um único `tar.gz` via
`scp` + extração remota (com verificação de tamanho do arquivo antes de
seguir) em vez de `scp -r`/`rsync` de muitos arquivos pequenos.

## Achados de portabilidade (schema mais estrito localmente que em produção)

Vários módulos migrados encontraram diferença de comportamento entre o
MySQL local (mais estrito) e o de produção (mais permissivo) — sempre
resolvidos com migration aditiva, nunca afrouxando o modo estrito local:
- `ONLY_FULL_GROUP_BY` (notas: `mediaFinalAnual`/`mediasBimestrais`).
- `aluno_responsavel.parentesco` NOT NULL sem default.
- `alunos.status_matricula` sem o valor `'Inativo'` no ENUM.
- `unidades_letivas.data_inicio`/`data_fim` NOT NULL, mas o código legado
  grava `'0000-00-00'` (produção não bloqueia isso; local sim) — resolvido
  tornando as colunas nullable e gravando `NULL`.
- `matriculas_financeiras` e `notas` não têm UNIQUE key que o código legado
  presumia existir (contava com `errno 1062` pra barrar duplicata, que nunca
  disparava de fato) — resolvido com checagem em nível de aplicação.

## Achados de configuração do PHP (produção ≠ local, mesmo código)

- **`serialize_precision`**: o `ea-php83` do Hostgator vem com `100` em vez
  do padrão `-1` do PHP 7.1+. Com 100, todo float num JSON sai com lixo de
  ponto flutuante (`4914.6999999999998181...` em vez de `4914.7`) — visto
  primeiro em `financeiro/dashboard`. Corrigido de vez com
  `ini_set('serialize_precision', -1)` em `AppServiceProvider::register()`,
  pra não depender do `php.ini` do host (nem local nem produção precisam de
  configuração manual depois disso).
