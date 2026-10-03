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
    'report.noData' => 'Os dados de profiler desta execução não existem mais (podem ter expirado ou sido removidos), portanto o relatório não pode ser gerado.',
    'nav.brand' => 'Análise de Performance do XHProf',
    'nav.home' => 'Início',
    'nav.runs' => 'Relatório de Execução',
    'nav.symbol' => 'Detalhes do Método',
    'nav.language' => 'Idioma',
    'search.placeholder' => 'Buscar função/método...',
    'search.button' => 'Buscar',
    'run.col.method' => 'Método da Requisição',
    'run.col.time' => 'Data/Hora da Requisição',
    'run.col.ip' => 'IP de Origem',
    'run.col.totalCalls' => 'Total de Chamadas de Funções/Métodos',
    'run.desc' => 'Execução XHProf (namespace=%s)',
    'runs.title' => 'Registro de Requisições',
    'runs.col.method' => 'Método',
    'runs.col.url' => 'URL da Requisição',
    'runs.col.time' => 'Data/Hora',
    'runs.col.wt' => 'Tempo (s)',
    'runs.col.mu' => 'Memória (Mb)',
    'runs.col.ip' => 'IP',
    'runs.compare' => 'Comparar selecionados',
    'runs.selectAll' => 'Selecionar tudo',
    'runs.selectRow' => 'Selecionar esta linha',
    'runs.compareHint' => 'Selecione exatamente duas execuções para comparar',
    'flat.title.top' => 'Exibindo as %s funções mais relevantes: ordenado por %s',
    'flat.title.sorted' => 'Ordenado por %s',
    'flat.title.diff' => 'Top 100 Regressões/Melhorias: ordenado por diferença em %s',
    'flat.title.diffAll' => 'Relatório completo de diferenças: ordenado pelo valor absoluto de regressão/melhoria em %s',
    'flat.displayAll' => 'exibir tudo',
    'symbol.notFound' => 'Função/Método %s não encontrado na execução do XHProf.',
    'pc.current' => 'Função Atual',
    'pc.exclusive' => 'Métricas Exclusivas%s da Função Atual',
    'pc.report' => 'Relatório Pai/Filho de %s',
    'pc.child' => 'Função filha',
    'pc.childMany' => 'Funções filhas',
    'pc.parent' => 'Função pai',
    'pc.parentMany' => 'Funções pai',
    'diff.run' => 'Execução #%s: %s',
    'agg.title' => 'Relatório Agregado de %s execuções: %s %s',
    'agg.titleOne' => 'Relatório Agregado de 1 execução: %s %s',
    'agg.ratio' => 'na proporção (%s)',
    'common.diff' => 'Diff',
    'diff.summary' => 'Resumo Geral de Diferenças',
    'diff.runShort' => 'Execução #%s',
    'diff.diffPct' => 'Diff%',
    'diff.callCount' => 'Número de Chamadas de Funções/Métodos',
    'pc.reportDiff' => 'Relatório Pai/Filho de %s: %s',
    'diag.title' => 'Diagnóstico',
    'diag.whySlow' => 'Por que está lento',
    'diag.otherFindings' => 'Outras descobertas',
    'diag.view' => 'ver',
    'diag.empty' => 'Nenhum gargalo evidente encontrado (limiares: tempo exclusivo ≥ %s%%, número de chamadas ≥ %s, chamadas por aresta ≥ %s, tempo exclusivo da função chamada ≥ %s%%, pico de memória ≥ %s%%)',
    'diag.r1.title' => '%s: tempo exclusivo %s, %s desta requisição',
    'diag.r1.detail' => 'O tempo exclusivo não inclui as chamadas de funções filhas — é apenas o custo do corpo da função',
    'diag.r2.title' => '%s: %s chamadas',
    'diag.r2.detail' => 'Número de chamadas elevado — vale confirmar se é o esperado',
    'diag.r3.title' => '%s → %s: %s chamadas, %s no total',
    'diag.r3.detail' => 'Essa aresta de chamada responde por uma parcela considerável do tempo',
    'diag.r4.title' => 'Recursão detectada em %s(), profundidade máxima %d',
    'diag.r4.detail' => 'Profundidade de recursão excessiva pode causar estouro de pilha ou crescimento exponencial do tempo',
    'diag.r5.title' => '%s: pico de memória %s, %s do total',
    'diag.r5.detail' => 'O pico de memória está concentrado em uma única função — verifique primeiro as suas estruturas de dados',
    'diag.r6.title' => '%s: tempo exclusivo %s excede o tempo inclusivo %s em %s',
    'diag.r6.detail' => 'O tempo exclusivo nunca deveria exceder o tempo inclusivo — os dados amostrados ou o cálculo podem estar errados',
    'unit.microsecs' => 'microssegundos',
    'unit.bytes' => 'bytes',
    'unit.samples' => 'amostras',
    'agg.invalidInput' => 'Entrada inválida.',
    'common.run' => 'Execução',
    'diff.invert' => 'Inverter Relatório de %s',
    'diff.viewRun' => 'Ver a Execução #%s',
    'common.regression' => 'Regressão',
    'common.improvement' => 'Melhoria',
    'pc.summary' => 'Resumo de %s: %s',
    'runs.dt.processing' => 'Processando...',
    'runs.dt.loadingRecords' => 'Carregando...',
    'runs.dt.lengthMenu' => 'Exibir _MENU_ registros',
    'runs.dt.zeroRecords' => 'Nenhum resultado correspondente',
    'runs.dt.emptyTable' => 'Nenhum dado disponível na tabela',
    'runs.dt.info' => 'Mostrando de _START_ até _END_ de _TOTAL_ registros',
    'runs.dt.infoEmpty' => 'Mostrando 0 até 0 de 0 registros',
    'runs.dt.infoFiltered' => '(Filtrado de _MAX_ registros no total)',
    'runs.dt.search' => 'Buscar:',
    'runs.dt.first' => 'Primeiro',
    'runs.dt.previous' => 'Anterior',
    'runs.dt.next' => 'Próximo',
    'runs.dt.last' => 'Último',
    'runs.dt.sortAsc' => ': Ordenar coluna em ordem ascendente',
    'runs.dt.sortDesc' => ': Ordenar coluna em ordem descendente',
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
    'common.na' => 'N/A',
    'pc.perCall' => 'Por chamada: %s',
    'sym.source' => 'Código-fonte',
    'diffcol.fn' => 'Função/Método',
    'diffcol.ct' => 'Chamadas<br>Diferença',
    'diffcol.Calls%' => 'Chamadas<br>Diferença %',
    'diffcol.wt' => 'Incl. Wall<br>Diferença<br>(µs)',
    'diffcol.IWall%' => 'Incl. Wall<br>Diferença %',
    'diffcol.excl_wt' => 'Excl. Wall<br>Diferença<br>(µs)',
    'diffcol.EWall%' => 'Excl. Wall<br>Diferença %',
    'diffcol.ut' => 'Incl. User<br>Diferença<br>(µs)',
    'diffcol.IUser%' => 'IUser<br>Diferença%',
    'diffcol.excl_ut' => 'Excl. User<br>Diferença<br>(µs)',
    'diffcol.EUser%' => 'EUser<br>Diferença%',
    'diffcol.cpu' => 'Incl.<br>Tempo de CPU<br>Diferença<br>(µs)',
    'diffcol.ICpu%' => 'Incl.<br>Tempo de CPU<br>Diferença %',
    'diffcol.excl_cpu' => 'Excl.<br>Tempo de CPU<br>Diferença<br>(µs)',
    'diffcol.ECpu%' => 'Excl.<br>Tempo de CPU<br>Diferença %',
    'diffcol.st' => 'Incl. Sys<br>Diferença<br>(µs)',
    'diffcol.ISys%' => 'ISys<br>Diferença%',
    'diffcol.excl_st' => 'Excl. Sys<br>Diferença<br>(µs)',
    'diffcol.ESys%' => 'ESys<br>Diferença%',
    'diffcol.mu' => 'Incl.<br>Uso de Memória<br>Diferença<br>(bytes)',
    'diffcol.IMUse%' => 'Incl.<br>Uso de Memória<br>Diferença %',
    'diffcol.excl_mu' => 'Excl.<br>Uso de Memória<br>Diferença<br>(bytes)',
    'diffcol.EMUse%' => 'Excl.<br>Uso de Memória<br>Diferença %',
    'diffcol.pmu' => 'Incl.<br>Pico de Memória<br>Diferença<br>(bytes)',
    'diffcol.IPMUse%' => 'Incl.<br>Pico de Memória<br>Diferença %',
    'diffcol.excl_pmu' => 'Excl.<br>Pico de Memória<br>Diferença<br>(bytes)',
    'diffcol.EPMUse%' => 'Excl.<br>Pico de Memória<br>Diferença %',
    'diffcol.samples' => 'Incl. Amostras<br>Diferença',
    'diffcol.ISamples%' => 'ISamples<br>Diferença%',
    'diffcol.excl_samples' => 'Excl. Amostras<br>Diferença',
    'diffcol.ESamples%' => 'ESamples<br>Diferença%',
    // ——— 2026-10: entrada de agregação multi-execução / cartão de caminho crítico / busca por substring ———
    // A ordem das chaves é o contrato: estas seis vêm depois de diffcol.ESamples%, e as 13 tabelas seguem a mesma ordem.
    // runs.aggregate: rótulo que o botão "Comparar selecionados" assume quando há mais de 2 execuções marcadas
    // (o HTML estático fica na camada Utils; o JS troca o texto conforme a contagem, via window.xpI18n).
    'runs.aggregate' => 'Agregar selecionados',
    'path.title' => 'Caminho Crítico',
    'path.empty' => 'Nenhuma cadeia de chamadas para mostrar (os dados não têm arestas de chamada a partir de main()).',
    // %s = termo buscado; o "30" é o mesmo limite de truncamento de XhprofDisplay — mude um, mude o outro
    'search.matches' => 'Funções que contêm “%s” (no máximo 30 exibidas):',
    // Gráfico de chamas (o módulo FlameGraph não tem texto próprio; título e nota são renderizados no lado do Display).
    // Os dois %s de flame.note = quadros realmente desenhados e a fração de tempo não desenhada; por cento literal escreve-se %%
    'flame.title' => 'Gráfico de chamas',
    'flame.note' => 'Exibindo %s quadros; %s%% do tempo total não é desenhado para manter a página leve',
    // 与上次运行对比（run 详情页里指向同 URI 的上一条 run 的 diff 链接）。
    'run.previous' => 'Comparar com a execução anterior',
    // 火焰图 metric 选中 mu/pmu 时的口径说明，跟在指标名后面。
    'flame.muInclusive' => '(memória incluindo as chamadas filhas)',
    // Separadores de milhar / decimal dos números da página de relatório (apenas exibição HTML; exportações e a lista de execuções não os usam): medidos conforme a CLDR.
    'num.thousands' => '.',
    'num.decimal' => ',',
    // Outras execuções da mesma URL: aponta para a lista com `requrl`, que o JS dela lê para preencher a busca.
    'run.otherRuns' => 'Outras execuções desta URL',
    // Rodapé. Marca + URL independem do idioma: os 13 catálogos trazem o mesmo valor ao pé da letra (a guarda do I18nTest só olha valores-fonte com han); render_footer() transforma a URL em link.
    'footer.credit' => '© erik · https://erik.xyz',

    // Barra de status da lista de execuções: armazenadas / limite / dias de retenção / datas mais antiga e mais recente.
    'runs.status' => 'Execuções armazenadas: %s / limite %s · retenção %s dias · mais antiga %s · mais recente %s',

];
