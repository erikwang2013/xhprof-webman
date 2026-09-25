<?php

declare(strict_types=1);

/**
 * Report-page strings — Portuguese (Português, pt-BR).
 *
 * O conjunto de chaves é o contrato: as 12 tabelas em src/Core/I18n/lang/ precisam
 * ter exatamente as mesmas chaves, na mesma ordem da fonte (tests/Unit/Core/I18nTest.php
 * compara). Para acrescentar um texto à página de relatório, adicione a chave primeiro
 * em zh_CN.php e depois preencha as 12 tabelas.
 *
 * Os valores são texto cru: só `<br>` é marcação (cabeçalhos de coluna estreitos,
 * quebrados à mão); todo o resto é escapado na saída por I18n::html() / I18n::plain().
 * As quebras são escolhidas para o português — não copiei as do chinês.
 *
 * Escolhas terminológicas:
 *   - "Incl."/"Excl." ficam como prefixo (o relatório é sobre os identificadores
 *     IUser%/EUser%/ISys%/... que seguem existindo em ASCII), e "Wall"/"User"/"Sys"
 *     ficam como empréstimos consagrados: "tempo real" em português se lê como
 *     "tempo real / ao vivo", que é outra coisa.
 *   - Microssegundos aparecem como "(µs)", o símbolo usado no Brasil; "(Mb)" e "(s)"
 *     são unidades e ficaram como no original.
 *   - "run.col.time"/"runs.col.time" são rótulo de data e hora (o valor é
 *     date('Y-m-d H:i:s', create_time)), não de duração — daí "Data/Hora".
 *   - Colunas User/Sys/Samples mantêm a palavra em inglês onde ela já era o literal
 *     de XhprofDisplay::$descriptions (en.php faz o mesmo), exceto "amostras", que
 *     tem uso corrente em português.
 */
return [
    '_meta' => [
        'lang' => 'pt',
        'name' => 'Português',
        'dir' => 'ltr',
    ],
    'report.title' => 'Relatório de Performance do XHProf',
    'nav.brand' => 'Análise de Performance do XHProf',
    'nav.home' => 'Início',
    'nav.runs' => 'Relatório de Execução',
    'nav.symbol' => 'Detalhes do Método',
    'search.placeholder' => 'Buscar função/método...',
    'search.button' => 'Buscar',
    'run.col.method' => 'Método da Requisição',
    'run.col.time' => 'Data/Hora da Requisição',
    'run.col.ip' => 'IP de Origem',
    'run.col.totalCalls' => 'Total de Chamadas de Funções/Métodos',
    'runs.title' => 'Registro de Requisições',
    'runs.col.method' => 'Método',
    'runs.col.url' => 'URL da Requisição',
    'runs.col.time' => 'Data/Hora',
    'runs.col.wt' => 'Tempo (s)',
    'runs.col.mu' => 'Memória (Mb)',
    'runs.col.ip' => 'IP',
    'col.fn' => 'Função/Método',
    'col.ct' => 'Chamadas',
    'col.Calls%' => 'Chamadas<br>%',
    'col.wt' => 'Incl. Wall<br>(µs)',
    'col.IWall%' => 'Incl. Wall<br>%',
    'col.excl_wt' => 'Excl. Wall<br>(µs)',
    'col.EWall%' => 'Excl. Wall<br>%',
    'col.ut' => 'Incl. User<br>(µs)',
    'col.IUser%' => 'IUser%',
    'col.excl_ut' => 'Excl. User<br>(µs)',
    'col.EUser%' => 'EUser%',
    'col.st' => 'Incl. Sys<br>(µs)',
    'col.ISys%' => 'ISys%',
    'col.excl_st' => 'Excl. Sys<br>(µs)',
    'col.ESys%' => 'ESys%',
    'col.cpu' => 'Incl.<br>Tempo de CPU<br>(µs)',
    'col.ICpu%' => 'Incl.<br>Tempo de CPU<br>%',
    'col.excl_cpu' => 'Excl.<br>Tempo de CPU<br>(µs)',
    'col.ECpu%' => 'Excl.<br>Tempo de CPU<br>%',
    'col.mu' => 'Incl.<br>Uso de Memória<br>(bytes)',
    'col.IMUse%' => 'Incl.<br>Uso de Memória<br>%',
    'col.excl_mu' => 'Excl.<br>Uso de Memória<br>(bytes)',
    'col.EMUse%' => 'Excl.<br>Uso de Memória<br>%',
    'col.pmu' => 'Incl.<br>Pico de Memória<br>(bytes)',
    'col.IPMUse%' => 'Incl.<br>Pico de Memória<br>%',
    'col.excl_pmu' => 'Excl.<br>Pico de Memória<br>(bytes)',
    'col.EPMUse%' => 'Excl.<br>Pico de Memória<br>%',
    'col.samples' => 'Incl. Amostras',
    'col.ISamples%' => 'ISamples%',
    'col.excl_samples' => 'Excl. Amostras',
    'col.ESamples%' => 'ESamples%',
];
