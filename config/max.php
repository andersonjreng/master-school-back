<?php

return [
    'anthropic_api_key' => env('ANTHROPIC_API_KEY'),
    'anthropic_model'   => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),

    // API PHP legada (ainda em produção) — as "tools" do Max chamam esses endpoints
    // repassando o Bearer token do usuário, exatamente como o agent.php original.
    'legacy_api_base_url' => env('MAX_LEGACY_API_BASE_URL'),

    // Mesmo segredo HS256 do login legado (api/config.php: jwt_secret).
    'jwt_secret' => env('JWT_SECRET'),

    'limite_padrao' => 20,

    // Cap de mensagens antigas recarregadas do banco como contexto pra Anthropic a cada turno.
    'historico_max_mensagens' => env('MAX_HISTORICO_LIMITE', 30),
];
