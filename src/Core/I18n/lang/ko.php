<?php

declare(strict_types=1);

/**
 * Report-page strings — Korean (한국어).
 *
 * 기계 번역이며 한국어 원어민의 검수를 받지 않았습니다 (unreviewed machine
 * translation). 값의 어미·용어는 tools/i18n/glossary/ko.json 과 맞춰 두었습니다.
 *
 * 규칙:
 *   1. 키 집합은 zh_CN.php 와 키 단위로 같아야 합니다. 늘리거나 줄이거나 이름을
 *      바꿀 수 없습니다. 새 문구가 필요하면 zh_CN.php 를 먼저 고치고 12개 어휘를
 *      모두 채우십시오 (tests/Unit/Core/I18nTest.php 가 빨간불이 됩니다).
 *   2. 값에서 표시(markup)는 `<br>` 뿐이고 나머지 문자는 출력 시 일괄
 *      이스케이프됩니다. 줄바꿈 위치는 언어마다 다르니 중국어의 줄바꿈 위치를
 *      그대로 베끼지 마십시오.
 *   3. 변수·식별자(XHProf, Redis, CPU, IP, bytes, microsec, IWall% 같은 열 식별자)는
 *      각 언어의 관용 표기를 따르되 다른 식별자로 바꾸지 마십시오.
 *   4. _meta 세 항목은 건드리지 마십시오. lang 은 <html lang>, dir=rtl 일 때만
 *      페이지 전체가 오른쪽에서 왼쪽으로 배치됩니다.
 *
 * User/Sys/Samples 계열(ut·st·samples)은 상류 xhprof 어휘라 zh_CN·en 과 마찬가지로
 * 영문 그대로 둡니다 — XhprofDisplay::$descriptions 의 리터럴이 그 형태입니다.
 */
return [
    '_meta' => [
        'lang' => 'ko',
        'name' => '한국어',
        'dir' => 'ltr',
    ],
    'report.title' => 'XHProf 성능 분석 보고서',
    'nav.brand' => 'XHProf 성능 분석',
    'nav.home' => '홈',
    'nav.runs' => '실행 보고서',
    'nav.symbol' => '메서드 상세',
    'search.placeholder' => '함수/메서드 이름 검색...',
    'search.button' => '검색',
    'run.col.method' => '요청 메서드',
    'run.col.time' => '요청 시간',
    'run.col.ip' => '출처 IP',
    'run.col.totalCalls' => '함수/메서드 총 호출 횟수',
    'runs.title' => '요청 기록',
    'runs.col.method' => '메서드',
    'runs.col.url' => '요청 URL',
    'runs.col.time' => '요청 시간',
    'runs.col.wt' => '소요 시간(s)',
    'runs.col.mu' => '메모리(Mb)',
    'runs.col.ip' => 'IP',
    'col.fn' => '함수/메서드',
    'col.ct' => '호출<br>횟수',
    'col.Calls%' => '호출<br>비율',
    'col.wt' => '총 소요 시간<br>(마이크로초)',
    'col.IWall%' => '총 소요 시간<br>비율',
    'col.excl_wt' => '자체 소요 시간<br>(마이크로초)',
    'col.EWall%' => '자체 소요 시간<br>비율',
    'col.ut' => 'Incl. User<br>(microsecs)',
    'col.IUser%' => 'IUser%',
    'col.excl_ut' => 'Excl. User<br>(microsec)',
    'col.EUser%' => 'EUser%',
    'col.st' => 'Incl. Sys <br>(microsec)',
    'col.ISys%' => 'ISys%',
    'col.excl_st' => 'Excl. Sys <br>(microsec)',
    'col.ESys%' => 'ESys%',
    'col.cpu' => '총<br>CPU 시간<br>(마이크로초)',
    'col.ICpu%' => '총<br>CPU 시간<br>비율',
    'col.excl_cpu' => '자체<br>CPU 시간<br>(마이크로초)',
    'col.ECpu%' => '자체<br>CPU 시간<br>비율',
    'col.mu' => '총<br>메모리 사용량<br>(bytes)',
    'col.IMUse%' => '총<br>메모리 사용량<br>비율',
    'col.excl_mu' => '자체<br>메모리 사용량<br>(bytes)',
    'col.EMUse%' => '자체<br>메모리 사용량<br>비율',
    'col.pmu' => '총<br>메모리 피크<br>(bytes)',
    'col.IPMUse%' => '총<br>메모리 피크<br>비율',
    'col.excl_pmu' => '자체<br>메모리 피크<br>(bytes)',
    'col.EPMUse%' => '자체<br>메모리 피크<br>비율',
    'col.samples' => 'Incl. Samples',
    'col.ISamples%' => 'ISamples%',
    'col.excl_samples' => 'Excl. Samples',
    'col.ESamples%' => 'ESamples%',
];
