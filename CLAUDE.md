<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

## Manual do Max (obrigatório a cada funcionalidade nova ou alterada)

O Max é o suporte dos usuários e só sabe o que está em `app/Services/Max/Manual/ManualSistema.php`.
Toda vez que uma tela/funcionalidade for criada, mudar de lugar no menu, ganhar botão novo ou mudar de
permissão, atualize o item correspondente (ou crie um) nesse arquivo **no mesmo commit**: `menu` com o
caminho real do menu, `acesso` com as mesmas regras do menu/rota do front, `palavras` com sinônimos que
o usuário usaria e `passos` com o passo a passo curto. `php artisan test --filter=ManualSistemaTest`
confere a estrutura e as permissões citadas.
