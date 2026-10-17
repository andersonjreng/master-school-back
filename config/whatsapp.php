<?php

return [
    // Token de acesso da Meta (WhatsApp Business API) — sem valor padrão aqui de
    // propósito: fica só no .env (não versionado), nunca commitado num config.
    'token' => env('WHATSAPP_TOKEN'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'template_name' => env('WHATSAPP_TEMPLATE_NAME', 'notificacao_nota'),
    'template_name_avaliacao' => env('WHATSAPP_TEMPLATE_NAME_AVALIACAO', 'aviso_avaliacao_agendada'),
    // Central de Comunicação (mural, recados, ocorrências). Vazio = avisos desligados
    // até o template ser aprovado na Meta. Corpo sugerido (4 variáveis):
    // "Olá, {{1}}! Você recebeu {{2}} da escola referente a {{3}}. Para ler, acesse o
    //  portal da escola: {{4}}. Obrigado!"
    'template_name_comunicacao' => env('WHATSAPP_TEMPLATE_NAME_COMUNICACAO', ''),
    'portal_url' => env('WHATSAPP_PORTAL_URL', env('APP_URL', '')),
    'template_lang' => env('WHATSAPP_TEMPLATE_LANG', 'pt_BR'),
    'api_version' => env('WHATSAPP_API_VERSION', 'v20.0'),
];
