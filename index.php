<?php
/**
 * NGender 纯PHP单文件版 - 修复反向性别模式（交换男女得分）
 * 核心：method=2 时交换男性/女性得分，重新计算性别，而非仅改展示
 */
header('Content-Type: text/html; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 前置检查
if (version_compare(PHP_VERSION, '7.0.0', '<')) jsonExit(500, 'PHP版本要求7.0及以上');
if (!extension_loaded('mbstring')) jsonExit(500, '缺少必要扩展：mbstring（php.ini中启用）');

// 核心配置
define('BASE_MALE', 0.581915415729593);
define('BASE_FEMALE', 0.418084584270407);
define('JSON_FILE_PATH', __DIR__ . '/charfreq.json');
define('TIPS_JSON_FILE_PATH', __DIR__ . '/tips.json');

// 整活模式配置（修正method=2的逻辑）
define('METHOD_NORMAL', 0);    // 正常模式：原始算法结果
define('METHOD_REVERSE', 1);   // 反转性别：1-prob + 强制0~0.4区间（改分数）
define('METHOD_OPPOSITE', 2);  // 反向性别：交换男女得分，重新计算性别（核心修复）
define('METHOD_LABELS', [
    METHOD_NORMAL   => '正常',
    METHOD_REVERSE  => '反转性别',
    METHOD_OPPOSITE => '反向性别'
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

// 生成趣味文案（基于最终性别分区）
function getRandomTip($prob, $final_gender, $tipsData) {
    // 强制校验性别格式
    $final_gender = strtolower(trim($final_gender));
    if (!in_array($final_gender, ['male', 'female'])) {
        $final_gender = 'male';
    }
    
    // 性别中文映射（匹配最终性别）
    $g_cn = $final_gender === 'male' ? '男' : '女';
    $rg_cn = $final_gender === 'male' ? '女' : '男';
    
    // 确定置信度分区
    if ($prob > 0.6) {
        $level = 'sure';
    } elseif ($prob >= 0.4) {
        $level = 'uncertain';
    } else {
        $level = 'reverse';
    }
    
    // 生成分区key（基于最终性别，不再用原始性别）
    $tipKey = $final_gender . '_' . $level;
    if (!isset($tipsData[$tipKey]) || empty($tipsData[$tipKey])) {
        $tipKey = $final_gender . '_sure';
    }
    
    // 随机抽取文案并替换占位符
    $tipList = $tipsData[$tipKey];
    $randomTip = $tipList[array_rand($tipList)];
    $randomTip = str_replace(['{targetG}', '{targetRG}'], [$g_cn, $rg_cn], $randomTip);
    
    return $randomTip;
}

// 核心NGender算法类（新增交换得分的方法）
class NGender {
    private $charFreq, $maleTotal, $femaleTotal, $baseMale, $baseFemale;
    public function __construct($cf, $mt, $ft, $bm, $bf) {
        $this->charFreq = $cf; $this->maleTotal = $mt; $this->femaleTotal = $ft;
        $this->baseMale = $bm; $this->baseFemale = $bf;
    }
    
    // 计算单个性别的概率
    private function calcProb($name, $g) {
        $prob = log($g === 'male' ? $this->baseMale : $this->baseFemale);
        $total = $g === 'male' ? $this->maleTotal : $this->femaleTotal;
        for ($i=0; $i<mb_strlen($name, 'UTF-8'); $i++) {
            $c = mb_substr($name, $i, 1, 'UTF-8');
            $cnt = isset($this->charFreq[$c]) ? $this->charFreq[$c] : ['male'=>1, 'female'=>1];
            $p = ($g === 'male' ? $cnt['male'] : $cnt['female']) / $total;
            $prob += log($p <= 0 ? 1e-10 : $p);
        }
        return $prob;
    }
    
    // 正常模式：原始猜测
    public function guess($name) {
        $pM = $this->calcProb($name, 'male'); 
        $pF = $this->calcProb($name, 'female');
        $maxP = max($pM, $pF); 
        $eM = exp($pM - $maxP); 
        $eF = exp($pF - $maxP);
        $pMale = $eM / ($eM + $eF); 
        $pFemale = 1 - $pMale;
        
        return [
            'gender' => $pMale > $pFemale ? 'male' : 'female',
            'prob_male' => round($pMale, 6),  // 男性得分
            'prob_female' => round($pFemale, 6), // 女性得分
            'final_prob' => $pMale > $pFemale ? round($pMale, 6) : round($pFemale, 6)
        ];
    }
    
    // 反向性别模式：交换男女得分，重新计算
    public function guessOpposite($name) {
        $pM = $this->calcProb($name, 'male'); 
        $pF = $this->calcProb($name, 'female');
        $maxP = max($pM, $pF); 
        $eM = exp($pM - $maxP); 
        $eF = exp($pF - $maxP);
        $pMale = $eM / ($eM + $eF); 
        $pFemale = 1 - $pMale;
        
        // 核心：交换男女得分
        $swapMale = $pFemale;
        $swapFemale = $pMale;
        
        return [
            'gender' => $swapMale > $swapFemale ? 'male' : 'female',
            'prob_male' => round($swapMale, 6),
            'prob_female' => round($swapFemale, 6),
            'final_prob' => $swapMale > $swapFemale ? round($swapMale, 6) : round($swapFemale, 6)
        ];
    }
}

// 调整结果的工具函数（适配三种模式）
function adjustResultByMethod($ngender, $name, $method) {
    switch ($method) {
        case METHOD_NORMAL:
            // 正常模式：原始结果
            $res = $ngender->guess($name);
            return [
                'gender' => $res['gender'],
                'final_prob' => $res['final_prob'],
                'method' => $method,
                'method_label' => METHOD_LABELS[$method]
            ];
            
        case METHOD_REVERSE:
            // 反转性别：改分数 + 反转性别
            $res = $ngender->guess($name);
            $prob = 1 - $res['final_prob'];
            $gender = $res['gender'] === 'male' ? 'female' : 'male';
            if ($prob > 0.4) {
                $prob = 0.4 - ($prob - 0.4);
            }
            $prob = round(max(0, min(1, $prob)), 6);
            return [
                'gender' => $gender,
                'final_prob' => $prob,
                'method' => $method,
                'method_label' => METHOD_LABELS[$method]
            ];
            
        case METHOD_OPPOSITE:
            // 反向性别：交换得分，重新计算（核心修复）
            $res = $ngender->guessOpposite($name);
            return [
                'gender' => $res['gender'],
                'final_prob' => $res['final_prob'],
                'method' => $method,
                'method_label' => METHOD_LABELS[$method]
            ];
            
        default:
            $res = $ngender->guess($name);
            return [
                'gender' => $res['gender'],
                'final_prob' => $res['final_prob'],
                'method' => METHOD_NORMAL,
                'method_label' => METHOD_LABELS[METHOD_NORMAL]
            ];
    }
}

// 加载数据
$jsonData = loadJsonData();
$tipsData = loadTipsData();
$ngender = new NGender($jsonData['charFreq'], $jsonData['maleTotal'], $jsonData['femaleTotal'], BASE_MALE, BASE_FEMALE);

// 路由处理
$route = getRoute();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonExit(200);

// 路由1：API接口
if ($route === '/api/v1/genderguess') {
    $name = getParam('name');
    $nolimit = getParam('nolimit');
    $method = isset($_GET['method']) || isset($_POST['method']) ? (int)getParam('method') : METHOD_NORMAL;
    
    if (!in_array($method, [METHOD_NORMAL, METHOD_REVERSE, METHOD_OPPOSITE])) {
        $method = METHOD_NORMAL;
    }
    
    $isNoLimit = in_array(strtolower((string)$nolimit), ['true', '1', 'yes', 'on']);
    
    if (is_null($name) || $name === '') jsonExit(400, '缺少参数name');
    if (!checkName($name, !$isNoLimit)) {
        $errorMsg = $isNoLimit ? '姓名必须是纯中文字符（无字数限制）' : '姓名必须是2-4个纯中文字符';
        jsonExit(400, $errorMsg);
    }
    
    // 获取调整后的结果（适配三种模式）
    $adjusted = adjustResultByMethod($ngender, $name, $method);
    $gCn = $adjusted['gender'] === 'male' ? '男' : '女';
    
    jsonExit(200, '查询成功', [
        'name'=>$name, 
        'gender'=>$adjusted['gender'],
        'gender_cn'=>$gCn,
        'probability'=>$adjusted['final_prob'],
        'fun_tip'=>getRandomTip($adjusted['final_prob'], $adjusted['gender'], $tipsData), 
        'nolimit_used' => $isNoLimit,
        'mode' => $adjusted['method'],
    ]);
}

// 路由2：网页界面
elseif ($route === '/') {
    $inputName = ''; $error = ''; $result = null; $randomTip = '';
    $defaultMethod = METHOD_NORMAL;
    
    // 处理分享链接
    if (isset($_GET['data']) && !empty($_GET['data'])) {
        $rawData = xssFilter(trim($_GET['data']));
        $method = isset($_GET['method']) ? (int)$_GET['method'] : METHOD_NORMAL;
        
        if (str_starts_with($rawData, '#')) {
            $inputName = substr($rawData, 1);
            $method = METHOD_REVERSE;
        } elseif (str_starts_with($rawData, '@')) {
            $inputName = substr($rawData, 1);
            $method = METHOD_OPPOSITE;
        } else {
            $inputName = $rawData;
        }
        
        if (!in_array($method, [METHOD_NORMAL, METHOD_REVERSE, METHOD_OPPOSITE])) {
            $method = METHOD_NORMAL;
        }
        $defaultMethod = $method;
        
        if (checkName($inputName)) {
            $adjusted = adjustResultByMethod($ngender, $inputName, $method);
            $result = [
                'name'=>$inputName, 
                'gender'=>$adjusted['gender'],
                'gender_cn'=>$adjusted['gender']==='male'?'男':'女', 
                'prob'=>$adjusted['final_prob'],
                'method' => $adjusted['method'],
                'method_label' => $adjusted['method_label']
            ];
            $randomTip = getRandomTip($adjusted['final_prob'], $adjusted['gender'], $tipsData);
        } else {
            $error = '分享链接无效，姓名格式错误！';
            $inputName = '';
        }
    }
    
    // 处理表单提交
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $inputName = xssFilter(trim($_POST['name'] ?? ''));
        $method = isset($_POST['method']) ? (int)$_POST['method'] : METHOD_NORMAL;
        
        if (!in_array($method, [METHOD_NORMAL, METHOD_REVERSE, METHOD_OPPOSITE])) {
            $method = METHOD_NORMAL;
        }
        
        if ($inputName === '') {
            $error = '请输入中文姓名！';
        } elseif (!checkName($inputName)) {
            $error = '姓名格式错误！必须是2-4个纯中文字符';
        } else {
            $adjusted = adjustResultByMethod($ngender, $inputName, $method);
            $result = [
                'name'=>$inputName, 
                'gender'=>$adjusted['gender'],
                'gender_cn'=>$adjusted['gender']==='male'?'男':'女', 
                'prob'=>$adjusted['final_prob'],
                'method' => $adjusted['method'],
                'method_label' => $adjusted['method_label']
            ];
            $randomTip = getRandomTip($adjusted['final_prob'], $adjusted['gender'], $tipsData);
        }
    }
    
    // 网页界面输出
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>中文姓名性别猜测</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
            body { font-family: 'Inter', sans-serif; background: #1f2937; color: #f9fafb; }
            .container { max-width: 500px; margin: 60px auto; padding: 0 20px; }
            .card { background: #374151; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
            .gender-male { color: #3b82f6; font-weight: 600; }
            .gender-female { color: #ec4899; font-weight: 600; }
            .prob { font-size: 14px; color: #9ca3af; margin-left: 10px; }
            .animate-fadeInUp { animation: fadeInUp 0.5s ease forwards; }
            .history-item { background: #4b5563; padding: 12px; border-radius: 8px; margin-bottom: 8px; text-align: left; cursor: pointer; }
            .history-item:hover { background: #586575; }
            .history-remove { color: #ef4444; cursor: pointer; font-size: 12px; margin-left: 8px; }
            .share-btn { background: #10b981; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 14px; margin-top: 10px; }
            .share-btn:hover { background: #059669; }
            .copy-tip { font-size: 12px; color: #10b981; margin-top: 6px; display: none; }
            .share-section { background: #4b5563/50; border-radius: 8px; padding: 16px; margin-top: 12px; }
            .method-tag { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; margin-left: 8px; }
            .tag-normal { background: #3b82f6/30; color: #3b82f6; }
            .tag-reverse { background: #ef4444/30; color: #ef4444; }
            .tag-opposite { background: #ec4899/30; color: #ec4899; }
            @keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="card text-center">
                <h1 class="text-2xl font-bold mb-4">中文姓名性别猜测</h1>
                <p class="text-gray-400 mb-8">贝叶斯算法加持 | 仅供娱乐 请勿当真<br>参考项目：<a href="https://github.com/observerss/NGender">observerss:NGender</a><br>开源：<a href="https://github.com/yyswys-yjyj/ngender-php-api">yyswys-yjyj:ngender-php-api</a></p>
                
                <form method="post" action="/" class="mb-6" id="nameForm">
                    <div class="mb-4">
                        <input type="text" name="name" value="<?php echo $inputName; ?>" 
                               placeholder="输入2-4个中文字符（如：赵本山、宋丹丹）" 
                               class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-purple-500"
                               required>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm text-gray-300 mb-2 text-left">模式：</label>
                        <select name="method" class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                            <option value="<?php echo METHOD_NORMAL; ?>" <?php echo $defaultMethod == METHOD_NORMAL ? 'selected' : ''; ?>>正常</option>
                            <option value="<?php echo METHOD_REVERSE; ?>" <?php echo $defaultMethod == METHOD_REVERSE ? 'selected' : ''; ?>>反转性别</option>
                            <option value="<?php echo METHOD_OPPOSITE; ?>" <?php echo $defaultMethod == METHOD_OPPOSITE ? 'selected' : ''; ?>>反向性别</option>
                        </select>
                    </div>
                    <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 px-4 rounded-lg transition duration-300 transform hover:scale-105 active:scale-95">
                        开始猜测性别
                    </button>
                </form>

                <?php if ($error): ?>
                    <div class="bg-red-900/30 border border-red-700/50 rounded-lg p-3 text-red-400 mb-4">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <?php if ($result): ?>
                    <div class="bg-gray-800/50 rounded-lg p-4 mt-4 animate-fadeInUp" id="resultCard">
                        <p class="text-lg mb-2">
                            姓名：<span class="font-bold text-white"><?php echo $result['name']; ?></span>
                        </p>
                        <p class="text-xl">
                            猜测性别：<span class="gender-<?php echo $result['gender']; ?>"><?php echo $result['gender_cn']; ?></span>
                            <span class="prob">置信度：<?php echo $result['prob']; ?></span>
                        </p>
                        <p class="mt-2 text-yellow-400 text-sm"><?php echo $randomTip; ?></p>
                    </div>

                    <div class="share-section animate-fadeInUp">
                        <h3 class="text-lg font-medium mb-3 text-gray-200">分享设置</h3>
                        <div class="mb-4 text-left">
                            <label class="block text-sm text-gray-400 mb-2">分享模式：</label>
                            <select id="shareMethod" class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500">
                                <option value="<?php echo METHOD_NORMAL; ?>" <?php echo $result['method'] == METHOD_NORMAL ? 'selected' : ''; ?>>正常</option>
                                <option value="<?php echo METHOD_REVERSE; ?>" <?php echo $result['method'] == METHOD_REVERSE ? 'selected' : ''; ?>>反转性别</option>
                                <option value="<?php echo METHOD_OPPOSITE; ?>" <?php echo $result['method'] == METHOD_OPPOSITE ? 'selected' : ''; ?>>反向性别</option>
                            </select>
                        </div>
                        <button class="share-btn w-full" onclick="copyShareLink('<?php echo $result['name']; ?>')">复制分享链接</button>
                        <p class="copy-tip" id="copyTip">链接已复制！打开直接看结果</p>
                    </div>
                <?php endif; ?>

                <div class="mt-8" id="historySection">
                    <h3 class="text-lg font-medium mb-4 text-gray-300">查询历史</h3>
                    <div id="historyList" class="max-h-48 overflow-y-auto pr-2"></div>
                    <?php if ($result): ?>
                        <script>window.guessResult = <?php echo json_encode($result); ?>;</script>
                    <?php endif; ?>
                    <button class="text-sm text-gray-400 mt-3 hover:text-white" onclick="clearAllHistory()">清空所有历史</button>
                </div>

                <div class="mt-8 text-sm text-gray-500">
                    <p>API文档：<code class="bg-gray-800 px-2 py-1 rounded">[你的API文档]</code></p>
                    <p>数据源：<code class="bg-gray-800 px-2 py-1 rounded">/charfreq.json</code></p>
                </div>
            </div>
        </div>

        <script>
            const HISTORY_KEY = 'ngender_guess_history';
            let guessResult = window.guessResult || null;

            window.onload = renderHistory;

            document.getElementById('nameForm').addEventListener('submit', function(e) {
                if (guessResult) {
                    saveToHistory(guessResult);
                    guessResult = null;
                }
            });

            function saveToHistory(res) {
                let history = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
                history = history.filter(item => !(item.name === res.name && item.method === res.method));
                const record = {
                    name: res.name,
                    gender: res.gender,
                    genderCn: res.gender_cn,
                    prob: res.prob,
                    method: res.method,
                    methodLabel: res.method_label,
                    time: new Date().toLocaleString('zh-CN', {hour12: false})
                };
                history.unshift(record);
                if (history.length > 15) history = history.slice(0, 15);
                localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
                renderHistory();
            }

            function renderHistory() {
                const historyList = document.getElementById('historyList');
                const history = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');

                if (history.length === 0) {
                    historyList.innerHTML = '<p class="text-gray-500 text-sm py-4">暂无查询记录，猜一个姓名看看吧～</p>';
                    return;
                }

                historyList.innerHTML = '';
                history.forEach((item, index) => {
                    const itemEl = document.createElement('div');
                    itemEl.className = 'history-item';
                    itemEl.innerHTML = `
                        <div class="flex justify-between items-center flex-wrap">
                            <div>
                                <span class="font-medium">${item.name}</span>
                                <span class="gender-${item.gender} ml-2">${item.genderCn}</span>
                                <span class="prob">${item.prob}</span>
                                <span class="method-tag ${item.method == <?php echo METHOD_NORMAL; ?> ? 'tag-normal' : (item.method == <?php echo METHOD_REVERSE; ?> ? 'tag-reverse' : 'tag-opposite')}">
                                    ${item.methodLabel}
                                </span>
                            </div>
                            <span class="text-xs text-gray-400 mt-1 sm:mt-0">${item.time}</span>
                        </div>
                        <div class="text-right mt-1">
                            <span class="history-remove" onclick="removeHistory(${index})">删除</span>
                        </div>
                    `;
                    itemEl.addEventListener('click', () => {
                        document.querySelector('input[name="name"]').value = item.name;
                        document.querySelector('select[name="method"]').value = item.method;
                        document.getElementById('nameForm').submit();
                    });
                    itemEl.querySelector('.history-remove').addEventListener('click', (e) => {
                        e.stopPropagation();
                        removeHistory(index);
                    });
                    historyList.appendChild(itemEl);
                });
            }

            function removeHistory(index) {
                let history = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
                history.splice(index, 1);
                localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
                renderHistory();
            }

            function clearAllHistory() {
                if (confirm('确定要清空所有查询历史吗？清空后不可恢复！')) {
                    localStorage.removeItem(HISTORY_KEY);
                    renderHistory();
                }
            }

            function copyShareLink(name) {
                const method = document.getElementById('shareMethod').value;
                let shareUrl = `${window.location.origin}/?data=${encodeURIComponent(name)}`;
                if (method != <?php echo METHOD_NORMAL; ?>) {
                    shareUrl += `&method=${encodeURIComponent(method)}`;
                }
                navigator.clipboard.writeText(shareUrl).then(() => {
                    const tip = document.getElementById('copyTip');
                    tip.style.display = 'block';
                    setTimeout(() => tip.style.display = 'none', 2000);
                }).catch(() => {
                    alert('复制失败，请手动复制：\n' + shareUrl);
                });
            }
        </script>
    </body>
    </html>
    <?php
}

// 路由3：404
else {
    jsonExit(404, '路由不存在', ['support'=>[
        '/' => '网页界面', 
        '/api/v1/genderguess' => '性别猜测API', 
        '/api/v1/genderguess?name=xxx&nolimit=1' => '解除字数限制',
        '/api/v1/genderguess?name=xxx&method=1' => '反转性别',
        '/api/v1/genderguess?name=xxx&method=2' => '反向性别',
        '?data=姓名&method=1/2' => '明文分享'
    ]]);
}
?>
