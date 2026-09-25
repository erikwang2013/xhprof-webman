<?php
/*
 * Derived from phacility/xhprof — Copyright (c) 2009 Facebook.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * CHANGES FROM UPSTREAM: namespaced under ErikWang2013\Xhprof\Core\XhprofLib,
 * ten-framework adapters in place of the original PHP superglobals, an i18n
 * layer, and the fixes recorded in this repository's history. The rest of this
 * package (everything outside src/Core/XhprofLib/) is the MIT-licensed work of
 * this project — see LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\XhprofLib\Utils;

interface XHProfRuns
{

    public static function get_run($run_id, $type, &$run_desc);

    public static function save_run($xhprof_data, $type, $run_id = null);
}
