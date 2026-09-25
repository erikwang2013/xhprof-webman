<?php

declare(strict_types=1);

/**
 * レポートページの文言 — 日本語。
 *
 * 鍵集合は契約：src/Core/I18n/lang/ 配下 12 の語彙は鍵が**完全に一致**していなければ
 * ならない（tests/Unit/Core/I18nTest.php が照合。1 つでも欠けると赤）。文言を足すときは
 * 先に zh_CN.php に鍵を足し、12 言語すべてを揃える。
 *
 * 値は**生テキスト**：`<br>` だけが手動折り返し用のマークアップとして許可され、それ以外の
 * 文字は出力時に I18n::html() が一括エスケープする。`<br>` が効くのは col.* だけである
 * ——他の鍵は I18n::plain() 経由で、そこでは `<br>` は空白に潰される。
 *
 * 列見出しは「合計（Incl.）/ 自身（Excl.）」＋対象（実時間・ユーザー・システム・CPU時間・
 * メモリ使用量・ピークメモリ・サンプル）の組み合わせで統一した。IUser% 系の `%` 列は
 * 上流の識別子なのでそのまま据え置く。
 */
return [
    '_meta' => [
        'lang' => 'ja',
        'name' => '日本語',
        'dir' => 'ltr',
    ],
    'report.title' => 'XHProf パフォーマンスレポート',
    'nav.brand' => 'XHProf パフォーマンス分析',
    'nav.home' => 'ホーム',
    'nav.runs' => '計測結果一覧',
    'nav.symbol' => 'メソッド詳細',
    'search.placeholder' => '関数/メソッド名を検索...',
    'search.button' => '検索',
    'run.col.method' => 'リクエストメソッド',
    'run.col.time' => 'リクエスト時刻',
    'run.col.ip' => '接続元 IP',
    'run.col.totalCalls' => '関数/メソッド呼び出し総数',
    'runs.title' => 'リクエスト記録',
    'runs.col.method' => 'メソッド',
    'runs.col.url' => 'リクエスト URL',
    'runs.col.time' => 'リクエスト時刻',
    'runs.col.wt' => '所要時間 (秒)',
    'runs.col.mu' => 'メモリ (Mb)',
    'runs.col.ip' => 'IP',
    'col.fn' => '関数/メソッド名',
    'col.ct' => '呼び出し<br>回数',
    'col.Calls%' => '呼び出し<br>回数<br>割合',
    'col.wt' => '合計<br>実時間<br>(µs)',
    'col.IWall%' => '合計<br>実時間<br>割合',
    'col.excl_wt' => '自身<br>実時間<br>(µs)',
    'col.EWall%' => '自身<br>実時間<br>割合',
    'col.ut' => '合計<br>ユーザー<br>(µs)',
    'col.IUser%' => 'IUser%',
    'col.excl_ut' => '自身<br>ユーザー<br>(µs)',
    'col.EUser%' => 'EUser%',
    'col.st' => '合計<br>システム<br>(µs)',
    'col.ISys%' => 'ISys%',
    'col.excl_st' => '自身<br>システム<br>(µs)',
    'col.ESys%' => 'ESys%',
    'col.cpu' => '合計<br>CPU時間<br>(µs)',
    'col.ICpu%' => '合計<br>CPU時間<br>割合',
    'col.excl_cpu' => '自身<br>CPU時間<br>(µs)',
    'col.ECpu%' => '自身<br>CPU時間<br>割合',
    'col.mu' => '合計<br>メモリ使用量<br>(bytes)',
    'col.IMUse%' => '合計<br>メモリ使用量<br>割合',
    'col.excl_mu' => '自身<br>メモリ使用量<br>(bytes)',
    'col.EMUse%' => '自身<br>メモリ使用量<br>割合',
    'col.pmu' => '合計<br>ピークメモリ<br>(bytes)',
    'col.IPMUse%' => '合計<br>ピークメモリ<br>割合',
    'col.excl_pmu' => '自身<br>ピークメモリ<br>(bytes)',
    'col.EPMUse%' => '自身<br>ピークメモリ<br>割合',
    'col.samples' => '合計<br>サンプル',
    'col.ISamples%' => 'ISamples%',
    'col.excl_samples' => '自身<br>サンプル',
    'col.ESamples%' => 'ESamples%',
];
