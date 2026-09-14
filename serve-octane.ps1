# Sobe o backend local via Laravel Octane + RoadRunner (substitui `php artisan serve`).
#
# Por que não usar "php artisan octane:start": o comando Artisan trava com um
# erro fatal no Windows (usa as constantes SIGINT/SIGTERM/SIGHUP do pcntl sem
# checar se a extensão existe, e o PHP para Windows nunca teve pcntl). Rodar o
# rr.exe diretamente contorna esse trecho de código — o binário em si (Go, não
# PHP) não depende de pcntl.
#
# Uso: abra este projeto no terminal e rode:
#   .\serve-octane.ps1
$env:APP_BASE_PATH = $PSScriptRoot
& "$PSScriptRoot\rr.exe" serve -c "$PSScriptRoot\.rr.yaml"
