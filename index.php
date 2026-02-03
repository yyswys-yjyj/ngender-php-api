<?php
/**
 * NGender 纯PHP单文件版 - 读取JSON字典格式数据
 * 复刻原Python版贝叶斯中文姓名性别猜测算法
 * 支持：无问号API + 网页界面 + 防XSS + LocalStorage历史（带结果） + 明文分享?data=xxx + API解除字数限制
 * 数据来源：根目录charfreq.json | PHP7.0+ | 依赖mbstring扩展
 * 原项目：https://github.com/observerss/ngender
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

// 工具函数：姓名验证（重构：增加长度限制开关）
// $limitLength=true：2-4纯中文 | $limitLength=false：纯中文（不限字数）
function checkName($name, $limitLength = true) {
    if ($limitLength) {
        return preg_match('/^[\x{4e00}-\x{9fa5}]{2,4}$/u', $name);
    } else {
        return preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $name);
    }
}

// 工具函数：多方式获取参数（GET/POST/JSON）
function getParam($key) {
    if (isset($_GET[$key])) return xssFilter($_GET[$key]);
    if (isset($_POST[$key])) return xssFilter($_POST[$key]);
    $json = json_decode(file_get_contents('php://input'), true);
    return json_last_error() === JSON_ERROR_NONE && isset($json[$key]) ? xssFilter($json[$key]) : null;
}

// 工具函数：加载并解析charfreq.json
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

// 工具函数：生成随机趣味文案（按置信度分区：0.6+确信/0.4-0.6不确定/0.4-反向）
function getRandomTip($prob, $gender) {
    $g = $gender === 'male' ? '男' : '女';
    $rg = $gender === 'male' ? '女' : '男';
    $tips = [
        'sure' => ["纯纯的{$g}孩纸，毫无争议！", "这名字刻着{$g}性烙印，稳得一批～", "妥妥的{$g}生，系统拍胸脯保证！", "这包{$g}性倾向的！", "绝对是{$g}性姓名～"],
        'uncertain' => ["雌雄难辨，有点像{$g}孩纸，但系统拿捏不准～", "{$g}性倾向，但{$rg}性特征也很明显", "薛定谔的性别，既像{$g}又像{$rg}～", "中性值拉满，建议直接问本人😂", "系统陷入沉思：这名字我分不清啊！"],
        'reverse' => ["反向预警：看着像{$g}，实际大概率是{$rg}！", "别被名字骗了，妥妥的{$rg}性隐藏款～", "系统翻车：名义{$g}，实际{$rg}概率更高！", "表面{$g}，内核{$rg}～", "这名字反着来的概率更大😜"]
    ];
    if ($prob > 0.6) return $tips['sure'][array_rand($tips['sure'])];
    elseif ($prob >= 0.4) return $tips['uncertain'][array_rand($tips['uncertain'])];
    else return $tips['reverse'][array_rand($tips['reverse'])];
}

// 核心NGender贝叶斯算法类
class NGender {
    private $charFreq, $maleTotal, $femaleTotal, $baseMale, $baseFemale;
    public function __construct($cf, $mt, $ft, $bm, $bf) {
        $this->charFreq = $cf; $this->maleTotal = $mt; $this->femaleTotal = $ft;
        $this->baseMale = $bm; $this->baseFemale = $bf;
    }
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
    public function guess($name) {
        $pM = $this->calcProb($name, 'male'); $pF = $this->calcProb($name, 'female');
        $maxP = max($pM, $pF); $eM = exp($pM - $maxP); $eF = exp($pF - $maxP);
        $pMale = $eM / ($eM + $eF); $pFemale = 1 - $pMale;
        return $pMale > $pFemale ? ['gender'=>'male', 'prob'=>round($pMale, 6)] : ['gender'=>'female', 'prob'=>round($pFemale, 6)];
    }
}

// 加载JSON数据并初始化算法
$jsonData = loadJsonData();
$ngender = new NGender($jsonData['charFreq'], $jsonData['maleTotal'], $jsonData['femaleTotal'], BASE_MALE, BASE_FEMALE);

// 路由处理
$route = getRoute();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonExit(200);

// 路由1：API接口 /api/v1/genderguess（新增nolimit参数支持）
if ($route === '/api/v1/genderguess') {
    $name = getParam('name');
    $nolimit = getParam('nolimit');
    // 判断是否开启解除字数限制（支持true/1/yes/on，不区分大小写）
    $isNoLimit = in_array(strtolower((string)$nolimit), ['true', '1', 'yes', 'on']);
    
    if (is_null($name) || $name === '') jsonExit(400, '缺少参数name');
    // 根据nolimit参数调整校验规则
    if (!checkName($name, !$isNoLimit)) {
        $errorMsg = $isNoLimit ? '姓名必须是纯中文字符（无字数限制）' : '姓名必须是2-4个纯中文字符';
        jsonExit(400, $errorMsg);
    }
    
    $res = $ngender->guess($name);
    $gCn = $res['gender'] === 'male' ? '男' : '女';
    jsonExit(200, '查询成功', [
        'name'=>$name, 'gender'=>$res['gender'], 'gender_cn'=>$gCn,
        'probability'=>$res['prob'], 'fun_tip'=>getRandomTip($res['prob'], $res['gender']),
        'nolimit_used' => $isNoLimit // 新增返回是否使用了解除字数限制
    ]);
}

// 路由2：根路径 / 网页界面（核心：处理分享链接?data=xxx）
elseif ($route === '/') {
    $inputName = ''; $error = ''; $result = null; $randomTip = '';
    // 处理分享链接：?data=姓名 明文解析
    if (isset($_GET['data']) && !empty($_GET['data'])) {
        $inputName = xssFilter(trim($_GET['data']));
        if (checkName($inputName)) { // 网页端仍保留2-4字限制
            $guessRes = $ngender->guess($inputName);
            $result = [
                'name'=>$inputName, 'gender'=>$guessRes['gender'],
                'gender_cn'=>$guessRes['gender']==='male'?'男':'女', 'prob'=>$guessRes['prob']
            ];
            $randomTip = getRandomTip($guessRes['prob'], $guessRes['gender']);
        } else {
            $error = '分享链接无效，姓名格式错误！';
            $inputName = '';
        }
    }
    // 处理表单提交
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $inputName = xssFilter(trim($_POST['name'] ?? ''));
        if ($inputName === '') $error = '请输入中文姓名！';
        elseif (!checkName($inputName)) $error = '姓名格式错误！必须是2-4个纯中文字符'; // 网页端仍保留2-4字限制
        else {
            $guessRes = $ngender->guess($inputName);
            $result = [
                'name'=>$inputName, 'gender'=>$guessRes['gender'],
                'gender_cn'=>$guessRes['gender']==='male'?'男':'女', 'prob'=>$guessRes['prob']
            ];
            $randomTip = getRandomTip($guessRes['prob'], $guessRes['gender']);
        }
    }
    // 网页界面输出
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>中文姓名性别猜测 | 仅供娱乐</title>
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
            @keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="card text-center">
                <h1 class="text-2xl font-bold mb-4">中文姓名性别猜测</h1>
                <p class="text-gray-400 mb-8">贝叶斯算法 | 仅供娱乐 请勿当真<br>参考项目：<a href="https://github.com/observerss/NGender">observerss/NGender</a></p>
                
                <form method="post" action="/" class="mb-6" id="nameForm">
                    <div class="mb-4">
                        <input type="text" name="name" value="<?php echo $inputName; ?>" 
                               placeholder="输入2-4个中文字符（如：赵本山、宋丹丹）" 
                               class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-purple-500"
                               required>
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
                        <p class="text-lg mb-2">姓名：<span class="font-bold text-white"><?php echo $result['name']; ?></span></p>
                        <p class="text-xl">
                            猜测性别：<span class="gender-<?php echo $result['gender']; ?>"><?php echo $result['gender_cn']; ?></span>
                            <span class="prob">置信度：<?php echo $result['prob']; ?></span>
                        </p>
                        <p class="mt-2 text-yellow-400 text-sm"><?php echo $randomTip; ?></p>
                        <button class="share-btn" onclick="copyShareLink('<?php echo $result['name']; ?>')">复制分享链接</button>
                        <p class="copy-tip" id="copyTip">链接已复制！打开直接看结果</p>
                    </div>
                <?php endif; ?>

                <!-- 历史记录区域（带猜测结果） -->
                <div class="mt-8" id="historySection">
                    <h3 class="text-lg font-medium mb-4 text-gray-300">查询历史 <span class="text-sm text-gray-400">(含结果)</span></h3>
                    <div id="historyList" class="max-h-48 overflow-y-auto pr-2"></div>
                    <?php if ($result): ?>
                        <script>window.guessResult = <?php echo json_encode($result); ?>;</script>
                    <?php endif; ?>
                    <button class="text-sm text-gray-400 mt-3 hover:text-white" onclick="clearAllHistory()">清空所有历史</button>
                </div>

                <div class="mt-8 text-sm text-gray-500">
                    <p>API接口：<code class="bg-gray-800 px-2 py-1 rounded">/api/v1/genderguess?name=某某某</code></p>
                    <p>解除字数限制：<code class="bg-gray-800 px-2 py-1 rounded">/api/v1/genderguess?name=某某某&nolimit=1</code></p>
                    <p class="mt-2 text-gray-400">数据来源：<code class="bg-gray-800 px-2 py-1 rounded">/charfreq.json</code></p>
                </div>
            </div>
        </div>

        <script>
            // 本地存储KEY & 全局结果对象
            const HISTORY_KEY = 'ngender_guess_history';
            let guessResult = window.guessResult || null;

            // 页面加载立即渲染历史记录
            window.onload = renderHistory;

            // 表单提交后，保存带结果的记录到LocalStorage
            document.getElementById('nameForm').addEventListener('submit', function(e) {
                if (guessResult) {
                    saveToHistory(guessResult);
                    guessResult = null; // 重置避免重复保存
                }
            });

            /**
             * 保存记录到LocalStorage - 包含【姓名、性别、置信度、查询时间】
             * @param {Object} res 猜测结果 {name, gender, gender_cn, prob}
             */
            function saveToHistory(res) {
                let history = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
                // 去重：重复姓名移除旧记录，新记录置顶
                history = history.filter(item => item.name !== res.name);
                // 拼接完整记录（加查询时间）
                const record = {
                    name: res.name,
                    gender: res.gender,
                    genderCn: res.gender_cn,
                    prob: res.prob,
                    time: new Date().toLocaleString('zh-CN', {hour12: false})
                };
                history.unshift(record);
                // 限制最多保存15条记录，避免冗余
                if (history.length > 15) history = history.slice(0, 15);
                // 保存到本地
                localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
                // 重新渲染
                renderHistory();
            }

            /**
             * 渲染历史记录 - 展示所有信息，点击重查，带删除按钮
             */
            function renderHistory() {
                const historyList = document.getElementById('historyList');
                const history = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');

                // 无历史记录
                if (history.length === 0) {
                    historyList.innerHTML = '<p class="text-gray-500 text-sm py-4">暂无查询记录，猜一个姓名看看吧～</p>';
                    return;
                }

                // 有历史记录，循环渲染
                historyList.innerHTML = '';
                history.forEach((item, index) => {
                    const itemEl = document.createElement('div');
                    itemEl.className = 'history-item';
                    // 渲染：姓名 + 性别（带颜色类） + 置信度 + 时间 + 删除按钮
                    itemEl.innerHTML = `
                        <div class="flex justify-between items-center">
                            <div>
                                <span class="font-medium">${item.name}</span>
                                <span class="gender-${item.gender} ml-2">${item.genderCn}</span>
                                <span class="prob">${item.prob}</span>
                            </div>
                            <span class="text-xs text-gray-400">${item.time}</span>
                        </div>
                        <div class="text-right mt-1">
                            <span class="history-remove" onclick="e=>e.stopPropagation(); removeHistory(${index})">删除</span>
                        </div>
                    `;
                    // 点击历史项：填充姓名并自动提交查询
                    itemEl.addEventListener('click', () => {
                        document.querySelector('input[name="name"]').value = item.name;
                        document.getElementById('nameForm').submit();
                    });
                    // 删除按钮阻止冒泡（避免触发重查）
                    itemEl.querySelector('.history-remove').addEventListener('click', (e) => {
                        e.stopPropagation();
                        removeHistory(index);
                    });
                    historyList.appendChild(itemEl);
                });
            }

            /**
             * 删除单条历史记录
             * @param {Number} index 记录索引
             */
            function removeHistory(index) {
                let history = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
                history.splice(index, 1);
                localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
                renderHistory();
            }

            /**
             * 清空所有历史记录（带确认）
             */
            function clearAllHistory() {
                if (confirm('确定要清空所有查询历史吗？清空后不可恢复！')) {
                    localStorage.removeItem(HISTORY_KEY);
                    renderHistory();
                }
            }

            /**
             * 生成明文分享链接?data=xxx + 复制到剪贴板
             * @param {String} name 要分享的姓名
             */
            function copyShareLink(name) {
                // 生成格式：当前域名?data=姓名（明文，直接打开即可解析）
                const shareUrl = `${window.location.origin}/?data=${encodeURIComponent(name)}`;
                // 复制到剪贴板
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

// 路由3：404未匹配
else {
    jsonExit(404, '路由不存在', ['support'=>[
        '/' => '网页界面', 
        '/api/v1/genderguess' => '性别猜测API', 
        '/api/v1/genderguess?name=xxx&nolimit=1' => '性别猜测API（解除字数限制）',
        '?data=姓名' => '明文分享'
    ]]);
}
?>