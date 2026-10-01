# Fluxo de versionamento

Vale para os dois repositórios do projeto:
[master-school](https://github.com/andersonjreng/master-school) (front, Angular) e
[master-school-back](https://github.com/andersonjreng/master-school-back) (back, Laravel).

## Branches

| Branch | Para quê | Sai de | Volta para |
|---|---|---|---|
| `main` | Código em produção. Nunca recebe commit direto, só merge. | — | — |
| `develop` | Integração das mudanças antes de irem para produção. | `main` | `main` (no release) |
| `feature/<nome>` | Funcionalidade nova. | `develop` | `develop` |
| `fix/<nome>` | Correção sem urgência. | `develop` | `develop` |
| `hotfix/<nome>` | Correção urgente em produção. | `main` | `main` **e** `develop` |
| `demo` (só no front) | Versão genérica de demonstração para outras escolas. Segue separada. | — | — |

Nomes curtos, em minúsculas e com hífen: `feature/boleto-sicoob-registro`, `fix/frequencia-data-vazia`.

## Dia a dia

```bash
# começar uma feature
git switch develop
git pull
git switch -c feature/minha-feature

# ...commits...

git push -u origin feature/minha-feature
# abrir PR no GitHub: feature/minha-feature -> develop
```

Depois do merge do PR, apague a branch, no GitHub e localmente (`git branch -d feature/minha-feature`).

## Release (develop → main)

1. Confira se a `develop` está testada (no back: endpoints via curl/teste; no front: `ng build` sem erro e teste no navegador).
2. Abra um PR `develop -> main` e faça o merge.
3. Crie a tag da versão na `main`:
   ```bash
   git switch main && git pull
   git tag -a v1.2.0 -m "v1.2.0"
   git push origin v1.2.0
   ```

Versão no formato `MAIOR.MENOR.CORREÇÃO`: mudança que quebra compatibilidade → MAIOR; funcionalidade nova → MENOR; só correções → CORREÇÃO.

## Hotfix

```bash
git switch main && git pull
git switch -c hotfix/descricao
# ...correção...
# PR hotfix/descricao -> main; depois do merge, tag de CORREÇÃO (ex: v1.2.1)
# e leve a mesma correção para a develop:
git switch develop && git pull && git merge main && git push
```

## Mensagens de commit

Padrão [Conventional Commits](https://www.conventionalcommits.org/pt-br/), em português:

```
<tipo>(<escopo opcional>): <descrição no imperativo>
```

| Tipo | Quando |
|---|---|
| `feat` | Funcionalidade nova |
| `fix` | Correção de bug |
| `refactor` | Mudança de código sem mudar o comportamento |
| `docs` | Só documentação |
| `chore` | Configuração, dependências, `.gitignore` etc. |
| `build` | Build/deploy |
| `test` | Testes |

Exemplos: `feat(financeiro): gera remessa CNAB400`, `fix(frequencia): aceita data vazia na listagem`.

## Nunca versionar

- `.env` e qualquer arquivo com senha, token ou chave
- Dumps de banco e arquivos de retorno bancário (dados pessoais reais)
- `vendor/`, `node_modules/`, logs e builds
