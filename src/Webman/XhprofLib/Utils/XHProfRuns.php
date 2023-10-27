<?php
declare(strict_types=1);
namespace Aaron\Xhprof\Webman\XhprofLib\Utils;

interface XHProfRuns
{

    public static function get_run($run_id, $type, &$run_desc);

    public static function save_run($xhprof_data, $type, $run_id = null);
}
