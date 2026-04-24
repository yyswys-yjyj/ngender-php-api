<?php
/**
 * NGender 纯PHP单文件版 - 优化
 */
header('Content-Type: text/html; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
header('Access-Control-Allow-Headers', 'Content-Type');

// 前置检查
if (version_compare(PHP_VERSION, '7.0.0', '<')) jsonExit(500, 'PHP版本要求7.0及以上');
if (!extension_loaded('mbstring')) jsonExit(500, '缺少必要扩展：mbstring（php.ini中启用）');

// 核心配置
define('BASE_MALE', 0.581915415729593);
define('BASE_FEMALE', 0.418084584270407);
define('JSON_FILE_PATH', __DIR__ . '/charfreq.json');
define('TIPS_JSON_FILE_PATH', __DIR__ . '/tips.json');

// 模式定义
define('METHOD_NORMAL', 0);
define('METHOD_REVERSE', 1);
define('METHOD_OPPOSITE', 2);
define('METHOD_RANDOM', 3);
define('METHOD_LABELS', [
    METHOD_NORMAL   => '正常',
    METHOD_REVERSE  => '反转性别',
    METHOD_OPPOSITE => '反向性别',
    METHOD_RANDOM   => '随机模式'
]);

// 工具函数：XSS过滤
function xssFilter($str) {
    if (is_null($str) || !is_string($str)) return '';
    return htmlspecialchars(trim($str), ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
}

// 工具函数：API统一JSON输出
function jsonExit($code = 200, $msg = 'success', $data = []) {
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['code'=>$code,'msg'=>$msg,'data'=>$data], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// 工具函数：路由解析
function getRoute() {
    $uri = strstr($_SERVER['REQUEST_URI'], '?', true) ?: $_SERVER['REQUEST_URI'];
    return preg_replace('/\/+/', '/', '/' . trim($uri, '/'));
}

// 工具函数：姓名验证
function checkName($name, $limitLength = true) {
    if ($limitLength) {
        return preg_match('/^[\x{4e00}-\x{9fa5}]{2,4}$/u', $name);
    } else {
        return preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $name);
    }
}

// 工具函数：多方式获取参数
function getParam($key) {
    if (isset($_GET[$key])) return xssFilter($_GET[$key]);
    if (isset($_POST[$key])) return xssFilter($_POST[$key]);
    $json = json_decode(file_get_contents('php://input'), true);
    return json_last_error() === JSON_ERROR_NONE && isset($json[$key]) ? xssFilter($json[$key]) : null;
}

// 解析并验证mapping参数（增强版，返回详细错误信息）
function parseMapping($mappingStr, &$debugInfo = null) {
    if (empty($mappingStr)) return null;
    $mappingStr = trim($mappingStr);
    $decoded = html_entity_decode($mappingStr, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($debugInfo !== null) {
        $debugInfo['mapping_raw'] = $mappingStr;
        $debugInfo['mapping_decoded'] = $decoded;
    }
    $mapping = json_decode($decoded, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        if ($debugInfo !== null) {
            $debugInfo['mapping_error'] = json_last_error_msg();
        }
        return ['error' => 'mapping参数JSON格式错误: ' . json_last_error_msg()];
    }
    if (!is_array($mapping)) {
        return ['error' => 'mapping参数必须是JSON对象/数组格式'];
    }
    $validGenders = ['male', 'female'];
    $validated = [];
    foreach ($mapping as $name => $rule) {
        if (!preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $name)) {
            return ['error' => "映射键名「{$name}」必须是纯中文字符"];
        }
        // 正确判断规则格式：先检查对象格式（关联数组）
        if (is_array($rule) && isset($rule['gender'])) {
            // 对象格式：{"gender":"male","min":0.8,"max":0.95}
            $gender = strtolower($rule['gender']);
            $minProb = isset($rule['min']) ? floatval($rule['min']) : 0.5;
            $maxProb = isset($rule['max']) ? floatval($rule['max']) : 1.0;
        } elseif (is_array($rule) && count($rule) >= 2) {
            // 索引数组格式：["male", 0.8, 0.95] 或 ["male", 0.8]
            $gender = strtolower($rule[0]);
            $minProb = isset($rule[1]) ? floatval($rule[1]) : 0.5;
            $maxProb = isset($rule[2]) ? floatval($rule[2]) : 1.0;
        } else {
            return ['error' => "映射「{$name}」规则格式无效，必须是对象或索引数组"];
        }
        if (!in_array($gender, $validGenders)) {
            return ['error' => "映射「{$name}」性别必须是 male 或 female，当前值: {$gender}"];
        }
        if ($minProb < 0.5 || $minProb > 1 || $maxProb < 0.5 || $maxProb > 1) {
            return ['error' => "映射「{$name}」概率范围必须在 0.5~1 之间，当前 min={$minProb}, max={$maxProb}"];
        }
        if ($minProb > $maxProb) {
            return ['error' => "映射「{$name}」最小概率不能大于最大概率，当前 min={$minProb}, max={$maxProb}"];
        }
        $validated[$name] = [
            'gender' => $gender,
            'min_prob' => $minProb,
            'max_prob' => $maxProb
        ];
    }
    if ($debugInfo !== null) {
        $debugInfo['mapping_parsed'] = $validated;
    }
    return $validated;
}

// 检查映射表并返回结果（增强调试）
function checkMapping($name, $mapping, &$debugInfo = null) {
    if (empty($mapping) || !is_array($mapping)) return null;
    foreach ($mapping as $mapName => $rule) {
        if ($mapName === $name) {
            $random = mt_rand() / mt_getrandmax();
            $prob = $rule['min_prob'] + $random * ($rule['max_prob'] - $rule['min_prob']);
            $prob = round($prob, 6);
            if ($debugInfo !== null) {
                $debugInfo['mapping_hit'] = [
                    'name' => $name,
                    'rule' => $rule,
                    'random_value' => $random,
                    'generated_prob' => $prob
                ];
            }
            return [
                'gender' => $rule['gender'],
                'final_prob' => $prob,
                'ismodified' => true,
                'mapped_name' => $mapName
            ];
        }
    }
    return null;
}

// 加载字符频次数据
function loadJsonData() {
    if (!file_exists(JSON_FILE_PATH)) jsonExit(500, '未找到charfreq.json，请放在根目录');
    if (!is_readable(JSON_FILE_PATH)) jsonExit(500, 'charfreq.json无读取权限，设置为644');
    $content = file_get_contents(JSON_FILE_PATH);
    $charFreq = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) jsonExit(500, 'JSON解析失败', ['err'=>json_last_error_msg()]);
    if (!is_array($charFreq) || empty($charFreq)) jsonExit(500, 'JSON非有效字典格式');
    $maleTotal = $femaleTotal = 0;
    foreach ($charFreq as $char => $data) {
        if (!isset($data['male'], $data['female']) || !is_numeric($data['male']) || !is_numeric($data['female'])) {
            jsonExit(500, "字符【{$char}】格式错误，需包含male/female数字");
        }
        $maleTotal += (int)$data['male'];
        $femaleTotal += (int)$data['female'];
    }
    if ($maleTotal === 0 || $femaleTotal === 0) jsonExit(500, 'JSON数据频次为0，数据异常');
    return ['charFreq'=>$charFreq, 'maleTotal'=>$maleTotal, 'femaleTotal'=>$femaleTotal];
}

// 加载外置文案tips.json
function loadTipsData() {
    if (!file_exists(TIPS_JSON_FILE_PATH)) jsonExit(500, '未找到tips.json，请放在根目录');
    if (!is_readable(TIPS_JSON_FILE_PATH)) jsonExit(500, 'tips.json无读取权限，设置为644');
    $content = file_get_contents(TIPS_JSON_FILE_PATH);
    $tipsData = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) jsonExit(500, 'Tips JSON解析失败', ['err'=>json_last_error_msg()]);
    $requiredKeys = ['male_sure', 'male_uncertain', 'male_reverse', 'female_sure', 'female_uncertain', 'female_reverse'];
    foreach ($requiredKeys as $key) {
        if (!isset($tipsData[$key]) || !is_array($tipsData[$key]) || empty($tipsData[$key])) {
            jsonExit(500, "Tips JSON缺少有效分区【{$key}】，需为非空数组");
        }
    }
    return $tipsData;
}

// 生成趣味文案
function getRandomTip($prob, $final_gender, $tipsData) {
    $final_gender = strtolower(trim($final_gender));
    if (!in_array($final_gender, ['male', 'female'])) $final_gender = 'male';
    $g_cn = $final_gender === 'male' ? '男' : '女';
    $rg_cn = $final_gender === 'male' ? '女' : '男';
    if ($prob > 0.6) $level = 'sure';
    elseif ($prob >= 0.4) $level = 'uncertain';
    else $level = 'reverse';
    $tipKey = $final_gender . '_' . $level;
    if (!isset($tipsData[$tipKey]) || empty($tipsData[$tipKey])) $tipKey = $final_gender . '_sure';
    $tipList = $tipsData[$tipKey];
    $randomTip = $tipList[array_rand($tipList)];
    $randomTip = str_replace(['{targetG}', '{targetRG}'], [$g_cn, $rg_cn], $randomTip);
    return $randomTip;
}

// 核心NGender算法类（增加调试支持）
class NGender {
    private $charFreq, $maleTotal, $femaleTotal, $baseMale, $baseFemale;
    public function __construct($cf, $mt, $ft, $bm, $bf) {
        $this->charFreq = $cf; $this->maleTotal = $mt; $this->femaleTotal = $ft;
        $this->baseMale = $bm; $this->baseFemale = $bf;
    }
    private function calcProb($name, $g, &$debugInfo = null) {
        $prob = log($g === 'male' ? $this->baseMale : $this->baseFemale);
        $total = $g === 'male' ? $this->maleTotal : $this->femaleTotal;
        $charLogs = [];
        for ($i=0; $i<mb_strlen($name, 'UTF-8'); $i++) {
            $c = mb_substr($name, $i, 1, 'UTF-8');
            $cnt = isset($this->charFreq[$c]) ? $this->charFreq[$c] : ['male'=>1, 'female'=>1];
            $p = ($g === 'male' ? $cnt['male'] : $cnt['female']) / $total;
            $logP = log($p <= 0 ? 1e-10 : $p);
            $prob += $logP;
            if ($debugInfo !== null) {
                $charLogs[] = ['char'=>$c, 'count'=>($g==='male'?$cnt['male']:$cnt['female']), 'p'=>$p, 'logP'=>$logP];
            }
        }
        if ($debugInfo !== null) {
            $debugInfo['calc_prob'][$g] = ['prob_log'=>$prob, 'char_logs'=>$charLogs];
        }
        return $prob;
    }
    public function guess($name, &$debugInfo = null) {
        $pM = $this->calcProb($name, 'male', $debugInfo);
        $pF = $this->calcProb($name, 'female', $debugInfo);
        $maxP = max($pM, $pF);
        $eM = exp($pM - $maxP);
        $eF = exp($pF - $maxP);
        $pMale = $eM / ($eM + $eF);
        $pFemale = 1 - $pMale;
        if ($debugInfo !== null) {
            $debugInfo['normal'] = [
                'pM_log' => $pM,
                'pF_log' => $pF,
                'maxP' => $maxP,
                'eM' => $eM,
                'eF' => $eF,
                'pMale' => $pMale,
                'pFemale' => $pFemale
            ];
        }
        return [
            'gender' => $pMale > $pFemale ? 'male' : 'female',
            'prob_male' => round($pMale, 6),
            'prob_female' => round($pFemale, 6),
            'final_prob' => $pMale > $pFemale ? round($pMale, 6) : round($pFemale, 6)
        ];
    }
    public function guessOpposite($name, &$debugInfo = null) {
        $pM = $this->calcProb($name, 'male', $debugInfo);
        $pF = $this->calcProb($name, 'female', $debugInfo);
        $maxP = max($pM, $pF);
        $eM = exp($pM - $maxP);
        $eF = exp($pF - $maxP);
        $pMale = $eM / ($eM + $eF);
        $pFemale = 1 - $pMale;
        $swapMale = $pFemale;
        $swapFemale = $pMale;
        if ($debugInfo !== null) {
            $debugInfo['opposite'] = [
                'original_pMale' => $pMale,
                'original_pFemale' => $pFemale,
                'swap_pMale' => $swapMale,
                'swap_pFemale' => $swapFemale
            ];
        }
        return [
            'gender' => $swapMale > $swapFemale ? 'male' : 'female',
            'prob_male' => round($swapMale, 6),
            'prob_female' => round($swapFemale, 6),
            'final_prob' => $swapMale > $swapFemale ? round($swapMale, 6) : round($swapFemale, 6)
        ];
    }
    public function guessRandom($name, &$debugInfo = null) {
        $genderRand = mt_rand(0, 1);
        $gender = $genderRand === 0 ? 'male' : 'female';
        $randVal = mt_rand() / mt_getrandmax();
        $prob = 0.5 + $randVal * 0.5;
        $prob = round($prob, 6);
        if ($debugInfo !== null) {
            $debugInfo['random'] = [
                'gender_rand' => $genderRand,
                'gender' => $gender,
                'prob_rand' => $randVal,
                'final_prob' => $prob
            ];
        }
        return [
            'gender' => $gender,
            'final_prob' => $prob,
            'prob_male' => $gender === 'male' ? $prob : 1 - $prob,
            'prob_female' => $gender === 'female' ? $prob : 1 - $prob
        ];
    }
}

// 调整结果（支持mapping和调试）
function adjustResultByMethod($ngender, $name, $method, $mapping = null, &$debugInfo = null) {
    // 优先检查映射表
    $mappedResult = null;
    if (!empty($mapping) && is_array($mapping)) {
        $mappedResult = checkMapping($name, $mapping, $debugInfo);
    }
    if ($mappedResult !== null) {
        if ($debugInfo !== null) {
            $debugInfo['mode'] = 'mapping';
            $debugInfo['result'] = $mappedResult;
        }
        return [
            'gender' => $mappedResult['gender'],
            'final_prob' => $mappedResult['final_prob'],
            'method' => $method,
            'method_label' => METHOD_LABELS[$method],
            'ismodified' => true
        ];
    }
    // 未命中映射表，按原模式计算
    $res = null;
    switch ($method) {
        case METHOD_NORMAL:
            $res = $ngender->guess($name, $debugInfo);
            if ($debugInfo !== null) $debugInfo['mode'] = 'normal';
            break;
        case METHOD_REVERSE:
            $res = $ngender->guess($name, $debugInfo);
            $prob = 1 - $res['final_prob'];
            $gender = $res['gender'] === 'male' ? 'female' : 'male';
            if ($prob > 0.4) $prob = 0.4 - ($prob - 0.4);
            $prob = round(max(0, min(1, $prob)), 6);
            if ($debugInfo !== null) {
                $debugInfo['reverse'] = [
                    'original_gender' => $res['gender'],
                    'original_prob' => $res['final_prob'],
                    'new_prob' => $prob,
                    'new_gender' => $gender
                ];
                $debugInfo['mode'] = 'reverse';
            }
            return [
                'gender' => $gender,
                'final_prob' => $prob,
                'method' => $method,
                'method_label' => METHOD_LABELS[$method],
                'ismodified' => false
            ];
        case METHOD_OPPOSITE:
            $res = $ngender->guessOpposite($name, $debugInfo);
            if ($debugInfo !== null) $debugInfo['mode'] = 'opposite';
            break;
        case METHOD_RANDOM:
            $res = $ngender->guessRandom($name, $debugInfo);
            if ($debugInfo !== null) $debugInfo['mode'] = 'random';
            break;
        default:
            $res = $ngender->guess($name, $debugInfo);
            $method = METHOD_NORMAL;
            if ($debugInfo !== null) $debugInfo['mode'] = 'normal (default)';
    }
    return [
        'gender' => $res['gender'],
        'final_prob' => $res['final_prob'],
        'method' => $method,
        'method_label' => METHOD_LABELS[$method],
        'ismodified' => false
    ];
}

// 加载数据
$jsonData = loadJsonData();
$tipsData = loadTipsData();
$ngender = new NGender($jsonData['charFreq'], $jsonData['maleTotal'], $jsonData['femaleTotal'], BASE_MALE, BASE_FEMALE);

// 路由处理
$route = getRoute();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonExit(200);

if ($route === '/api/v1/genderguess') {
    $name = getParam('name');
    $nolimit = getParam('nolimit');
    $method = isset($_GET['method']) || isset($_POST['method']) ? (int)getParam('method') : METHOD_NORMAL;
    $mappingStr = getParam('mapping');
    $debug = isset($_GET['debug']) ? (int)getParam('debug') : 0;
    if (!in_array($method, [METHOD_NORMAL, METHOD_REVERSE, METHOD_OPPOSITE, METHOD_RANDOM])) $method = METHOD_NORMAL;
    $debugInfo = ($debug == 1) ? ['request' => ['name'=>$name, 'method'=>$method, 'mapping'=>$mappingStr]] : null;
    $mapping = null;
    if (!empty($mappingStr)) {
        $parsed = parseMapping($mappingStr, $debugInfo);
        if (isset($parsed['error'])) {
            if ($debugInfo !== null) {
                $debugInfo['error'] = $parsed['error'];
                jsonExit(400, $parsed['error'], ['debug_info' => $debugInfo]);
            } else {
                jsonExit(400, $parsed['error']);
            }
        }
        $mapping = $parsed;
    }
    $isNoLimit = in_array(strtolower((string)$nolimit), ['true', '1', 'yes', 'on']);
    if (is_null($name) || $name === '') jsonExit(400, '缺少参数name');
    if (!checkName($name, !$isNoLimit)) {
        $errorMsg = $isNoLimit ? '姓名必须是纯中文字符（无字数限制）' : '姓名必须是2-4个纯中文字符';
        jsonExit(400, $errorMsg);
    }
    $adjusted = adjustResultByMethod($ngender, $name, $method, $mapping, $debugInfo);
    $gCn = $adjusted['gender'] === 'male' ? '男' : '女';
    $responseData = [
        'name'=>$name, 'gender'=>$adjusted['gender'], 'gender_cn'=>$gCn,
        'probability'=>$adjusted['final_prob'],
        'fun_tip'=>getRandomTip($adjusted['final_prob'], $adjusted['gender'], $tipsData),
        'nolimit_used' => $isNoLimit, 'mode' => $adjusted['method'], 'ismodified' => $adjusted['ismodified']
    ];
    if ($debugInfo !== null) {
        $responseData['debug_info'] = $debugInfo;
    }
    jsonExit(200, '查询成功', $responseData);
}

elseif ($route === '/') {
    $inputName = ''; $error = ''; $result = null; $randomTip = ''; $defaultMethod = METHOD_NORMAL;
    if (isset($_GET['data']) && !empty($_GET['data'])) {
        $rawData = xssFilter(trim($_GET['data']));
        $method = isset($_GET['method']) ? (int)$_GET['method'] : METHOD_NORMAL;
        if (str_starts_with($rawData, '#')) { $inputName = substr($rawData, 1); $method = METHOD_REVERSE; }
        elseif (str_starts_with($rawData, '@')) { $inputName = substr($rawData, 1); $method = METHOD_OPPOSITE; }
        else $inputName = $rawData;
        if (!in_array($method, [METHOD_NORMAL, METHOD_REVERSE, METHOD_OPPOSITE, METHOD_RANDOM])) $method = METHOD_NORMAL;
        $defaultMethod = $method;
        if (checkName($inputName)) {
            $adjusted = adjustResultByMethod($ngender, $inputName, $method);
            $result = [
                'name'=>$inputName, 'gender'=>$adjusted['gender'], 'gender_cn'=>$adjusted['gender']==='male'?'男':'女',
                'prob'=>$adjusted['final_prob'], 'method'=>$adjusted['method'], 'method_label'=>$adjusted['method_label'],
                'ismodified'=>$adjusted['ismodified']
            ];
            $randomTip = getRandomTip($adjusted['final_prob'], $adjusted['gender'], $tipsData);
        } else { $error = '分享链接无效，姓名格式错误！'; $inputName = ''; }
    }
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>中文姓名性别猜测</title>
        <meta name="description" content="开源的中文姓名性别猜测工具，支持使用API">
        <meta name="keywords" content="中文姓名性别猜测,姓名性别猜测,中文姓名性别,性别预测,NGender,星辰落锤">
        <meta name="author" content="Serveryyswys">
        <meta name="robots" content="index, follow">
        <meta property="og:title" content="中文姓名性别猜测">
        <meta property="og:description" content="基于贝叶斯算法的中文姓名性别猜测工具，支持自定义映射表、多种模式及随机模式。">
        <meta property="og:type" content="website">
        <meta property="og:url" content="https://fun.serveryyswys.top/">
        <meta name="twitter:card" content="summary">
        <meta name="twitter:title" content="中文姓名性别猜测">
        <meta name="twitter:description" content="基于贝叶斯算法的中文姓名性别猜测工具，支持自定义映射表、多种模式及随机模式。">
        <!-- 关键修改：恢复正常加载 CSS，同时使用加载动画遮罩避免 FOUC -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="style.css">
        <!-- 内联样式：保证加载动画在 CSS 加载前也能全屏覆盖，防止布局错乱 -->
        <style>
            /* 确保页面加载动画始终全屏，背景与深色主题一致 */
            .page-loader {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: #0f172a;  /* 深色背景，与页面风格一致 */
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 9999;
                transition: opacity 0.5s ease, visibility 0.5s ease;
                opacity: 1;
                visibility: visible;
            }
            .page-loader.loader-hidden {
                opacity: 0;
                visibility: hidden;
            }
            .loader-spinner {
                width: 48px;
                height: 48px;
                border: 5px solid rgba(139, 92, 246, 0.3);
                border-top-color: #8b5cf6;
                border-radius: 50%;
                animation: spin 1s linear infinite;
            }
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
        </style>
    </head>
    <body>
        <!-- 全局加载动画 -->
        <div id="pageLoader" class="page-loader">
            <div class="loader-spinner"></div>
        </div>

        <div class="floating-btn" id="openSidebarBtn">
            <i class="fa-solid fa-table"></i>
        </div>
        <div id="sidebarOverlay" class="sidebar-overlay"></div>
        <div id="mappingSidebar" class="mapping-sidebar">
            <h2 class="text-xl font-bold mb-4 text-white"><i class="fa-solid fa-list-ul mr-2"></i>自定义映射表</h2>
            <p class="text-sm text-gray-400 mb-4"><i class="fa-solid fa-info-circle mr-1"></i>添加映射组，手动指定一个姓名的性别与权重</p>
            <div id="mappingItemsContainer"></div>
            <button id="addMappingItem" class="add-item-btn"><i class="fa-solid fa-plus mr-2"></i>添加映射组</button>
            <button id="applyMappingBtn" class="apply-mapping-btn"><i class="fa-solid fa-check mr-2"></i>应用映射表</button>
            <button id="debugToggleBtn" class="debug-toggle-btn"><i class="fa-solid fa-bug mr-2"></i>启用调试功能</button>
            <button id="closeSidebarBtn" class="add-item-btn close-sidebar-btn"><i class="fa-solid fa-xmark mr-2"></i>关闭</button>
        </div>
        <div class="container">
            <div class="card text-center">
                <h1 class="text-2xl font-bold mb-4"><i class="fa-solid fa-venus-mars mr-2"></i>中文姓名性别猜测</h1>
                <p class="text-gray-400 mb-8">基于贝叶斯算法 | 仅供娱乐 请勿当真<br>参考项目：<a href="https://github.com/observerss/NGender">observerss:NGender</a><br>开源：<a href="https://github.com/yyswys-yjyj/ngender-php-api">yyswys-yjyj:ngender-php-api</a></p>
                <form id="nameForm" class="mb-6" onsubmit="return false;">
                    <div class="mb-4">
                        <input type="text" id="nameInput" name="name" value="<?php echo $inputName; ?>" 
                               placeholder="输入2-4个中文字符（如：赵本山、宋丹丹）" 
                               class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-purple-500"
                               required>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm text-gray-300 mb-2 text-left"><i class="fa-solid fa-sliders mr-1"></i>模式：</label>
                        <select id="methodSelect" class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                            <option value="<?php echo METHOD_NORMAL; ?>" <?php echo $defaultMethod == METHOD_NORMAL ? 'selected' : ''; ?>><i class="fa-solid fa-check mr-1"></i>正常</option>
                            <option value="<?php echo METHOD_REVERSE; ?>" <?php echo $defaultMethod == METHOD_REVERSE ? 'selected' : ''; ?>><i class="fa-solid fa-repeat mr-1"></i>反转性别</option>
                            <option value="<?php echo METHOD_OPPOSITE; ?>" <?php echo $defaultMethod == METHOD_OPPOSITE ? 'selected' : ''; ?>><i class="fa-solid fa-exchange mr-1"></i>反向性别</option>
                            <option value="<?php echo METHOD_RANDOM; ?>" <?php echo $defaultMethod == METHOD_RANDOM ? 'selected' : ''; ?>><i class="fa-solid fa-shuffle mr-1"></i>随机模式</option>
                        </select>
                    </div>
                    <input type="hidden" id="mappingHidden" value="">
                    <button type="submit" id="submitBtn" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 px-4 rounded-lg transition duration-300 transform hover:scale-105 active:scale-95">
                        <i class="fa-solid fa-magnifying-glass mr-2"></i>开始猜测性别
                    </button>
                </form>
                <div id="errorMsg" class="bg-red-900/30 border border-red-700/50 rounded-lg p-3 text-red-400 mb-4 hidden"><i class="fa-solid fa-circle-exclamation mr-2"></i></div>
                <div id="resultContainer" class="transition-all duration-300">
                    <?php if ($result): ?>
                    <div class="bg-gray-800/50 rounded-lg p-4 mt-4 animate-fadeInUp">
                        <p class="text-lg mb-2">
                            <i class="fa-solid fa-user mr-2"></i>姓名：<span class="font-bold text-white"><?php echo $result['name']; ?></span>
                        </p>
                        <p class="text-xl">
                            <i class="fa-solid fa-venus-mars mr-2"></i>猜测性别：
                            <?php if($result['gender'] == 'male'): ?>
                                <span class="gender-male"><i class="fa-solid fa-mars mr-1"></i><?php echo $result['gender_cn']; ?></span>
                            <?php else: ?>
                                <span class="gender-female"><i class="fa-solid fa-venus mr-1"></i><?php echo $result['gender_cn']; ?></span>
                            <?php endif; ?>
                            <span class="prob"><i class="fa-solid fa-percent mr-1"></i>置信度：<?php echo $result['prob']; ?></span>
                        </p>
                        <p class="mt-2 text-yellow-400 text-sm"><i class="fa-solid fa-quote-left mr-1"></i><?php echo $randomTip; ?></p>
                    </div>
                    <div class="share-section animate-fadeInUp">
                        <h3 class="text-lg font-medium mb-3 text-gray-200"><i class="fa-solid fa-share-alt mr-2"></i>分享设置</h3>
                        <div class="mb-4 text-left">
                            <label class="block text-sm text-gray-400 mb-2"><i class="fa-solid fa-sliders mr-1"></i>分享模式：</label>
                            <select id="shareMethod" class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                                <option value="<?php echo METHOD_NORMAL; ?>" <?php echo $result['method'] == METHOD_NORMAL ? 'selected' : ''; ?>>正常</option>
                                <option value="<?php echo METHOD_REVERSE; ?>" <?php echo $result['method'] == METHOD_REVERSE ? 'selected' : ''; ?>>反转性别</option>
                                <option value="<?php echo METHOD_OPPOSITE; ?>" <?php echo $result['method'] == METHOD_OPPOSITE ? 'selected' : ''; ?>>反向性别</option>
                                <option value="<?php echo METHOD_RANDOM; ?>" <?php echo $result['method'] == METHOD_RANDOM ? 'selected' : ''; ?>>随机模式</option>
                            </select>
                        </div>
                        <button class="share-btn w-full" onclick="copyShareLink('<?php echo $result['name']; ?>')"><i class="fa-solid fa-copy mr-2"></i>复制分享链接</button>
                        <p class="copy-tip" id="copyTip"><i class="fa-solid fa-check mr-1"></i>链接已复制！打开直接看结果</p>
                    </div>
                    <?php endif; ?>
                </div>
                <div id="debugPanel" class="debug-panel" style="display: none;">
                    <div class="debug-header"><i class="fa-solid fa-code mr-2"></i>调试信息</div>
                    <pre id="debugContent"></pre>
                </div>
                <div class="mt-8" id="historySection">
                    <h3 class="text-lg font-medium mb-4 text-gray-300"><i class="fa-solid fa-clock-rotate-left mr-2"></i>查询历史</h3>
                    <div id="historyList" class="max-h-48 overflow-y-auto pr-2"></div>
                    <button class="text-sm text-gray-400 mt-3 hover:text-white" onclick="clearAllHistory()"><i class="fa-solid fa-trash mr-1"></i>清空所有历史</button>
                </div>
                <div class="mt-8 text-sm text-gray-500">
                    <p><i class="fa-solid fa-book mr-1"></i>API文档：<code class="bg-gray-800 px-2 py-1 rounded">https://apihelp.serveryyswys.top/8408398m0</code></p>
                    <p><i class="fa-solid fa-database mr-1"></i>数据源：<code class="bg-gray-800 px-2 py-1 rounded">/charfreq.json</code></p>
                </div>
            </div>
        </div>
        <script>
            // 页面加载完成后隐藏加载动画（此时 CSS 已加载并应用，布局稳定）
            window.addEventListener('load', function() {
                const loader = document.getElementById('pageLoader');
                if (loader) loader.classList.add('loader-hidden');
            });

            const HISTORY_KEY = 'ngender_guess_history';
            const API_BASE = window.location.origin + '/api/v1/genderguess';
            const sidebar = document.getElementById('mappingSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const openBtn = document.getElementById('openSidebarBtn');
            const closeSidebarBtn = document.getElementById('closeSidebarBtn');
            const addMappingBtn = document.getElementById('addMappingItem');
            const applyMappingBtn = document.getElementById('applyMappingBtn');
            const mappingItemsContainer = document.getElementById('mappingItemsContainer');
            const mappingHidden = document.getElementById('mappingHidden');
            const debugToggleBtn = document.getElementById('debugToggleBtn');
            const debugPanel = document.getElementById('debugPanel');
            const debugContent = document.getElementById('debugContent');
            let mappingItems = [];
            let debugEnabled = false;

            function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('active'); }
            function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('active'); }
            openBtn.addEventListener('click', openSidebar);
            closeSidebarBtn.addEventListener('click', closeSidebar);
            overlay.addEventListener('click', closeSidebar);

            debugToggleBtn.addEventListener('click', function() {
                debugEnabled = !debugEnabled;
                if (debugEnabled) {
                    debugToggleBtn.innerHTML = '<i class="fa-solid fa-bug mr-2"></i>关闭调试功能';
                    debugToggleBtn.style.background = '#ef4444';
                    debugPanel.style.display = 'block';
                } else {
                    debugToggleBtn.innerHTML = '<i class="fa-solid fa-bug mr-2"></i>启用调试功能';
                    debugToggleBtn.style.background = '#f59e0b';
                    debugPanel.style.display = 'none';
                    debugContent.textContent = '';
                }
            });

            function renderMappingItems() {
                mappingItemsContainer.innerHTML = '';
                mappingItems.forEach((item, index) => {
                    const div = document.createElement('div');
                    div.className = 'mapping-item';
                    div.innerHTML = `
                        <button class="delete-item" data-index="${index}"><i class="fa-solid fa-trash"></i></button>
                        <input type="text" placeholder="姓名（如：张三）" value="${escapeHtml(item.name)}" data-field="name" data-index="${index}">
                        <select data-field="gender" data-index="${index}">
                            <option value="male" ${item.gender === 'male' ? 'selected' : ''}><i class="fa-solid fa-mars mr-1"></i>男</option>
                            <option value="female" ${item.gender === 'female' ? 'selected' : ''}><i class="fa-solid fa-venus mr-1"></i>女</option>
                        </select>
                        <input type="number" step="0.01" min="0.5" max="1" placeholder="最小概率 (0.5~1)" value="${item.min}" data-field="min" data-index="${index}">
                        <input type="number" step="0.01" min="0.5" max="1" placeholder="最大概率 (0.5~1)" value="${item.max}" data-field="max" data-index="${index}">
                    `;
                    mappingItemsContainer.appendChild(div);
                });
                document.querySelectorAll('[data-field]').forEach(el => {
                    el.addEventListener('change', function(e) {
                        const idx = parseInt(this.dataset.index);
                        const field = this.dataset.field;
                        if (field === 'name') mappingItems[idx].name = this.value;
                        else if (field === 'gender') mappingItems[idx].gender = this.value;
                        else if (field === 'min') mappingItems[idx].min = parseFloat(this.value) || 0.5;
                        else if (field === 'max') mappingItems[idx].max = parseFloat(this.value) || 1.0;
                    });
                });
                document.querySelectorAll('.delete-item').forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        const idx = parseInt(this.dataset.index);
                        mappingItems.splice(idx, 1);
                        renderMappingItems();
                    });
                });
            }

            function addMappingItem() {
                mappingItems.push({ name: '', gender: 'male', min: 0.8, max: 0.95 });
                renderMappingItems();
            }
            addMappingBtn.addEventListener('click', addMappingItem);

            function applyMapping() {
                const mappingObj = {};
                let hasError = false;
                for (const item of mappingItems) {
                    const name = item.name.trim();
                    if (!name) { alert('请填写所有姓名，不能为空'); hasError = true; break; }
                    if (!/^[\u4e00-\u9fa5]+$/.test(name)) { alert(`姓名“${name}”必须为纯中文字符`); hasError = true; break; }
                    const min = parseFloat(item.min);
                    const max = parseFloat(item.max);
                    if (isNaN(min) || isNaN(max) || min < 0.5 || min > 1 || max < 0.5 || max > 1 || min > max) {
                        alert(`姓名“${name}”的概率范围无效，需满足 0.5 ≤ min ≤ max ≤ 1`);
                        hasError = true; break;
                    }
                    mappingObj[name] = { gender: item.gender, min: min, max: max };
                }
                if (hasError) return;
                const mappingJson = JSON.stringify(mappingObj);
                mappingHidden.value = mappingJson;
                closeSidebar();
                alert('映射表已应用，提交表单时将一同发送。');
            }
            applyMappingBtn.addEventListener('click', applyMapping);

            window.onload = function() {
                renderHistory();
                <?php if ($result): ?>saveToHistory(<?php echo json_encode($result); ?>);<?php endif; ?>
            };

            document.getElementById('nameForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                const name = document.getElementById('nameInput').value.trim();
                const method = document.getElementById('methodSelect').value;
                const mapping = mappingHidden.value;
                if (!name) { showError('请输入中文姓名！'); return; }
                const submitBtn = document.getElementById('submitBtn');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<span class="loading-spinner"></span> 猜测中...';
                submitBtn.classList.add('btn-loading');
                hideError();
                const resultContainer = document.getElementById('resultContainer');
                if (resultContainer.firstChild) { resultContainer.style.opacity = '0'; resultContainer.style.transform = 'translateY(-10px)'; }
                try {
                    let url = `${API_BASE}?name=${encodeURIComponent(name)}&method=${method}`;
                    if (mapping) url += `&mapping=${encodeURIComponent(mapping)}`;
                    if (debugEnabled) url += `&debug=1`;
                    const response = await fetch(url);
                    const data = await response.json();
                    if (data.code !== 200) {
                        showError(data.msg);
                        if (debugEnabled && data.data && data.data.debug_info) {
                            displayDebugInfo(data.data.debug_info);
                        }
                        return;
                    }
                    const result = data.data;
                    const historyRecord = {
                        name: result.name, gender: result.gender, gender_cn: result.gender_cn,
                        prob: result.probability, method: result.mode,
                        method_label: getMethodLabel(result.mode), ismodified: result.ismodified || false,
                        time: new Date().toLocaleString('zh-CN', {hour12: false})
                    };
                    saveToHistory(historyRecord);
                    updateResultUI(result);
                    if (debugEnabled && result.debug_info) {
                        displayDebugInfo(result.debug_info);
                    } else if (debugEnabled && !result.debug_info) {
                        displayDebugInfo({ note: '未返回调试信息，可能未启用debug参数或后端未支持。' });
                    }
                    setTimeout(() => { resultContainer.style.opacity = '1'; resultContainer.style.transform = 'translateY(0)'; }, 50);
                } catch (err) { console.error(err); showError('网络错误，请稍后重试'); }
                finally { submitBtn.innerHTML = originalText; submitBtn.classList.remove('btn-loading'); }
            });

            function displayDebugInfo(info) {
                debugContent.textContent = JSON.stringify(info, null, 2);
                debugPanel.style.display = 'block';
            }

            function getMethodLabel(method) { const labels = {0:'正常',1:'反转性别',2:'反向性别',3:'随机模式'}; return labels[method]||'正常'; }
            function getMethodTagClass(method) { const classes = {0:'tag-normal',1:'tag-reverse',2:'tag-opposite',3:'tag-random'}; return classes[method]||'tag-normal'; }
            function updateResultUI(result) {
                const container = document.getElementById('resultContainer');
                const genderClass = result.gender === 'male' ? 'gender-male' : 'gender-female';
                const genderIcon = result.gender === 'male' ? 'fa-mars' : 'fa-venus';
                const genderCn = result.gender_cn;
                container.innerHTML = `
                    <div class="bg-gray-800/50 rounded-lg p-4 mt-4 animate-fadeInUp">
                        <p class="text-lg mb-2"><i class="fa-solid fa-user mr-2"></i>姓名：<span class="font-bold text-white">${escapeHtml(result.name)}</span></p>
                        <p class="text-xl"><i class="fa-solid fa-venus-mars mr-2"></i>猜测性别：<span class="${genderClass}"><i class="fa-solid ${genderIcon} mr-1"></i>${genderCn}</span><span class="prob"><i class="fa-solid fa-percent mr-1"></i>置信度：${result.probability}</span></p>
                        <p class="mt-2 text-yellow-400 text-sm"><i class="fa-solid fa-quote-left mr-1"></i>${escapeHtml(result.fun_tip)}</p>
                    </div>
                    <div class="share-section animate-fadeInUp mt-4">
                        <h3 class="text-lg font-medium mb-3 text-gray-200"><i class="fa-solid fa-share-alt mr-2"></i>分享设置</h3>
                        <div class="mb-4 text-left">
                            <label class="block text-sm text-gray-400 mb-2"><i class="fa-solid fa-sliders mr-1"></i>分享模式：</label>
                            <select id="shareMethod" class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                                <option value="0">正常</option><option value="1">反转性别</option><option value="2">反向性别</option><option value="3">随机模式</option>
                            </select>
                        </div>
                        <button class="share-btn w-full" onclick="copyShareLink('${escapeHtml(result.name)}')"><i class="fa-solid fa-copy mr-2"></i>复制分享链接</button>
                        <p class="copy-tip" id="copyTip"><i class="fa-solid fa-check mr-1"></i>链接已复制！打开直接看结果</p>
                    </div>
                `;
                const shareMethodSelect = document.getElementById('shareMethod');
                if (shareMethodSelect) shareMethodSelect.value = result.mode;
            }
            function saveToHistory(record) {
                let history = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
                history = history.filter(item => !(item.name === record.name && item.method === record.method));
                history.unshift(record);
                if (history.length > 15) history = history.slice(0, 15);
                localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
                renderHistory();
            }
            function renderHistory() {
                const historyList = document.getElementById('historyList');
                const history = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
                if (history.length === 0) { historyList.innerHTML = '<p class="text-gray-500 text-sm py-4"><i class="fa-solid fa-inbox mr-2"></i>暂无查询记录，猜一个姓名看看吧～</p>'; return; }
                historyList.innerHTML = '';
                history.forEach((item, index) => {
                    const itemEl = document.createElement('div'); itemEl.className = 'history-item';
                    const genderIcon = item.gender === 'male' ? 'fa-mars' : 'fa-venus';
                    itemEl.innerHTML = `
                        <div class="flex justify-between items-center flex-wrap">
                            <div><span class="font-medium">${escapeHtml(item.name)}</span><span class="gender-${item.gender} ml-2"><i class="fa-solid ${genderIcon} mr-1"></i>${item.gender_cn}</span><span class="prob">${item.prob}</span><span class="method-tag ${getMethodTagClass(item.method)}">${item.method_label}</span></span></div>
                            <span class="text-xs text-gray-400 mt-1 sm:mt-0">${item.time}</span>
                        </div>
                        <div class="text-right mt-1"><span class="history-remove" onclick="removeHistory(${index})"><i class="fa-solid fa-trash mr-1"></i>删除</span></div>
                    `;
                    itemEl.addEventListener('click', () => { document.getElementById('nameInput').value = item.name; document.getElementById('methodSelect').value = item.method; document.getElementById('submitBtn').click(); });
                    itemEl.querySelector('.history-remove').addEventListener('click', (e) => { e.stopPropagation(); removeHistory(index); });
                    historyList.appendChild(itemEl);
                });
            }
            function removeHistory(index) { let history = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]'); history.splice(index,1); localStorage.setItem(HISTORY_KEY,JSON.stringify(history)); renderHistory(); }
            function clearAllHistory() { if(confirm('确定要清空所有查询历史吗？清空后不可恢复！')){ localStorage.removeItem(HISTORY_KEY); renderHistory(); } }
            function copyShareLink(name) { const method = document.getElementById('shareMethod').value; let shareUrl = `${window.location.origin}/?data=${encodeURIComponent(name)}`; if(method != 0) shareUrl += `&method=${encodeURIComponent(method)}`; navigator.clipboard.writeText(shareUrl).then(()=>{ const tip = document.getElementById('copyTip'); if(tip){ tip.style.display='block'; setTimeout(()=>tip.style.display='none',2000); } }).catch(()=>alert('复制失败，请手动复制：\n'+shareUrl)); }
            function showError(msg) { const errorDiv = document.getElementById('errorMsg'); errorDiv.innerHTML = '<i class="fa-solid fa-circle-exclamation mr-2"></i>' + msg; errorDiv.classList.remove('hidden'); }
            function hideError() { document.getElementById('errorMsg').classList.add('hidden'); }
            function escapeHtml(str) { if(!str) return ''; return str.replace(/[&<>]/g, function(m){ if(m==='&') return '&amp;'; if(m==='<') return '&lt;'; if(m==='>') return '&gt;'; return m;}); }
        </script>
    </body>
    </html>
    <?php
}
else {
    jsonExit(404, '路由不存在', ['support'=>[
        '/' => '网页界面',
        '/api/v1/genderguess' => '性别猜测API',
        '/api/v1/genderguess?name=xxx&nolimit=1' => '解除字数限制',
        '/api/v1/genderguess?name=xxx&method=1' => '反转性别',
        '/api/v1/genderguess?name=xxx&method=2' => '反向性别',
        '/api/v1/genderguess?name=xxx&method=3' => '随机模式',
        '/api/v1/genderguess?name=xxx&mapping={}' => '自定义映射表',
        '?data=姓名&method=1/2/3' => '明文分享'
    ]]);
}
?>
