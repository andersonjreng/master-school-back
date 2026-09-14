<?php

return [
    // Token de acesso da Meta (WhatsApp Business API) — sem valor padrão aqui de
    // propósito: fica só no .env (não versionado), nunca commitado num config.
    'token' => env('WHATSAPP_TOKEN'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'template_name' => env('WHATSAPP_TEMPLATE_NAME', 'notificacao_nota'),
    'template_name_avaliacao' => env('WHATSAPP_TEMPLATE_NAME_AVALIACAO', 'aviso_avaliacao_agendada'),
    'template_lang' => env('WHATSAPP_TEMPLATE_LANG', 'pt_BR'),
    'api_version' => env('WHATSAPP_API_VERSION', 'v20.0'),
];
