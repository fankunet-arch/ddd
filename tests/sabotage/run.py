"""逐条把铁律故意破掉，确认 selftest 真的会失败。
每条破坏写成一个函数，避免 shell 里层层转义把自己坑了 —— 上一版就是
转义写错导致破坏根本没生效，于是把一条真失效的检查误判成「假检查」。"""
import os, re, shutil, subprocess, sys, tempfile

# 仓库根目录：本文件在 <root>/tests/sabotage/ 里，往上三层
APP = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
assert os.path.isfile(os.path.join(APP, 'lib/db.php')), f'找不到程序根目录：{APP}'

def edit(root, rel, fn):
    p = os.path.join(root, rel)
    s = open(p, encoding='utf-8').read()
    s2 = fn(s)
    assert s2 != s, f'破坏没生效: {rel}'
    open(p, 'w', encoding='utf-8').write(s2)

def append(root, rel, text):
    # 必须往【已存在】的文件里追加。少了这个断言，路径写错时会凭空建出一个新文件，
    # 破坏「生效」了、自检也确实报错，于是一条根本没测到东西的用例显示为通过。
    p = os.path.join(root, rel)
    assert os.path.isfile(p), f'要破坏的文件不存在: {rel}'
    with open(p, 'a', encoding='utf-8') as f:
        f.write(text)

CASES = [
    ('在页面里另开数据库连接',
     lambda r: append(r, 'index.php', '\n<?php $x = new PDO("mysql:host=h"); ?>\n')),
    ('给 Db 加写入口 exec()',
     lambda r: edit(r, 'lib/db.php', lambda s: s.replace(
         'public static function select(',
         'public static function exec(string $q): void {}\n    public static function select(', 1))),
    ('在页面里写 INSERT 语句',
     lambda r: append(r, 'station.php', '\n<?php $sql = "INSERT INTO t VALUES(1)"; ?>\n')),
    ('在页面里写 CREATE TABLE',
     lambda r: append(r, 'dish.php', '\n<?php $sql = "CREATE TABLE t (a INT)"; ?>\n')),
    ('把功能参数抄回 config.php',
     lambda r: edit(r, 'config.php', lambda s: s.replace(
         'return [', "return [\n    'ack_hours' => 6,", 1))),
    ('用了 settings.php 里没有的配置项',
     lambda r: edit(r, 'open.php', lambda s: s.replace(
         '$cfg        = Db::config();',
         "$cfg        = Db::config();\n$zz = $cfg['brand_new_key'];", 1))),
    ('删掉时区设定',
     lambda r: edit(r, 'lib/db.php', lambda s: s.replace(
         'date_default_timezone_set', 'noop_tz'))),
    ('涨跌只剩颜色、去掉箭头',
     lambda r: edit(r, 'compare.php', lambda s: s.replace('▲', 'up').replace('▼', 'down'))),
    ('把输入框字号改到 16px 以下',
     lambda r: edit(r, 'assets/app.css', lambda s: s.replace(
         'font-size:16px;background:#fff', 'font-size:14px;background:#fff'))),
    ('手机与桌面各写各的格式化函数',
     lambda r: edit(r, 'open.php', lambda s: s.replace('$fmt($r)', 'array_merge([],[])', 1))),
    ('删掉注意事项.md',
     lambda r: os.remove(os.path.join(r, '注意事项.md'))),
    ('README 不再指向注意事项.md',
     lambda r: edit(r, 'README.md', lambda s: s.replace('注意事项.md', '某文档'))),
    ('给统计 SQL 加上 JOIN',
     lambda r: edit(r, 'lib/biz.php', lambda s: s.replace(
         'FROM {$table}\n                  WHERE',
         'FROM {$table} JOIN menu_item USING (menu_item_id)\n                  WHERE', 1))),
    ('对时间列套函数（用不上索引）',
     lambda r: edit(r, 'lib/biz.php', lambda s: s.replace(
         "'order_start_time >= :from'", "'DATE(order_start_time) >= :from'", 1))),
    ('把写操作接到主库那一侧',
     lambda r: append(r, 'index.php', '\n<?php $sql = "UPDATE order_head SET x = 1"; ?>\n')),
    ('让 Store 去连 MySQL',
     lambda r: edit(r, 'lib/store.php', lambda s: s.replace(
         "$dsn = 'sqlite:' . $path;", "$dsn = 'mysql:host=x'; $c = new mysqli('x');", 1))),
    ('让 Store 去查主库的表',
     lambda r: edit(r, 'lib/store.php', lambda s: s.replace(
         'public static function select(string $sql',
         'public static function posHack(): array { return self::select("SELECT 1 FROM history_order_head"); }\n'
         '    public static function select(string $sql', 1))),
    ('把数据文件挪进程序目录（那通常就在网站里）',
     lambda r: edit(r, 'lib/store.php', lambda s: s.replace(
         "$out[] = dirname($app) . DIRECTORY_SEPARATOR . self::DIR_NAME;",
         "$out[] = $app . DIRECTORY_SEPARATOR . self::DIR_NAME;", 1))),
    ('自有存储那侧去引用主库查询',
     lambda r: edit(r, 'lib/meat.php', lambda s: s.replace(
         'return Store::selectOne(', 'Db::select("SELECT 1"); return Store::selectOne(', 1))),
    ('在周报表页里自己拼 SQL（迟早会把两边写进同一条）',
     lambda r: append(r, 'meatweek.php', '\n<?php $sql = "SELECT 1 FROM t"; ?>\n')),
    ('拿掉「采购不等于消耗」那句提醒',
     lambda r: edit(r, 'meatweek.php', lambda s: s.replace('不是「实际消耗量」', '就是消耗量'))),
    ('周报表数字列表头去掉 class="n"',
     lambda r: edit(r, 'meatweek.php', lambda s: s.replace(
         '<th class="n">合计 kg</th>', '<th>合计 kg</th>', 1))),
    ('滚动平均把「没数据」当成 0（会造出假的暴涨）',
     lambda r: edit(r, 'lib/meat.php', lambda s: s.replace(
         'static fn($v) => $v !== null', 'static fn($v) => true', 1))),
    ('库存把取出量算反（存入和盘点搞混）',
     lambda r: edit(r, 'lib/stock.php', lambda s: s.replace(
         "$used = (float) $prev['qty'] + $in - (float) $r['qty'];",
         "$used = (float) $r['qty'] + $in - (float) $prev['qty'];", 1))),
    ('库存把「盘出来比账面多」悄悄当成 0',
     lambda r: edit(r, 'lib/stock.php', lambda s: s.replace(
         "'negative' => $used < -0.0001,", "'negative' => false,", 1))),
    ('库存把已作废的流水也算进结存',
     lambda r: edit(r, 'lib/stock.php', lambda s: s.replace(
         'WHERE item = :it AND deleted_at IS NULL', 'WHERE item = :it', 1))),
    ('存入允许填 0（等于什么都没记）',
     lambda r: edit(r, 'lib/stock.php', lambda s: s.replace(
         "$qty == 0.0 && $kind === self::IN", "false", 1))),
    ('库存动作给个默认值（选反了用量就算反）',
     lambda r: edit(r, 'lib/stock.php', lambda s: s.replace(
         "$kind = trim((string) ($in['move_kind'] ?? ''));",
         "$kind = trim((string) ($in['move_kind'] ?? 'in'));", 1))),
    ('拿掉「账面不是实时库存」那句提醒',
     lambda r: edit(r, 'stocknow.php', lambda s: s.replace(
         '不等于「现在冰箱里有多少」', '就是现在的库存'))),
    ('把库存和采购接起来',
     lambda r: edit(r, 'lib/stock.php', lambda s: s.replace(
         'final class Stock', "require_once __DIR__ . '/meat.php';\nfinal class Stock", 1)
         .replace('public static function itemLabel(string $code): string\n    {',
                  'public static function itemLabel(string $code): string\n    {\n        Meat::kinds();', 1))),
    ('当前库存页长出写库语句',
     lambda r: append(r, 'stocknow.php', '\n<?php $sql = "UPDATE stock_move SET qty = 0"; ?>\n')),
    ('mode 写错时默认成「存入即用量」（本该盘的品类会悄悄不盘）',
     lambda r: edit(r, 'lib/stock.php', lambda s: s.replace(
         "$mode === self::MODE_DIRECT ? self::MODE_DIRECT : self::MODE_COUNT",
         "$mode === self::MODE_COUNT ? self::MODE_COUNT : self::MODE_DIRECT", 1))),
    ('允许给「存入即用量」的品类记盘点',
     lambda r: edit(r, 'lib/stock.php', lambda s: s.replace(
         "if ($kind === self::COUNT && self::isDirect($item)) {",
         "if (false) {", 1))),
    ('盘点进度把不盘点的品类也算进「还差」',
     lambda r: edit(r, 'lib/stock.php', lambda s: s.replace(
         "$missing   = array_values(array_diff($needCount, $done));",
         "$missing   = array_values(array_diff(array_keys(self::items()), $done));", 1))),
    ('自动选址不再避开网站可访问目录',
     lambda r: edit(r, 'lib/store.php', lambda s: s.replace(
         'if (self::insideDocRoot($dir) !== null || !self::dirUsable($dir)) {',
         'if (!self::dirUsable($dir)) {', 1))),
    ('退回通用文件名 app.db（谁的库都分不出来）',
     lambda r: edit(r, 'lib/store.php', lambda s: s.replace(
         "public const FILE_NAME = 'salesreport.db';",
         "public const FILE_NAME = 'app.db';", 1))),
    ('打开别人的数据库也照建表不误',
     lambda r: edit(r, 'lib/store.php', lambda s: s.replace(
         "if ($who === 'foreign') {", 'if (false) {', 1))),
    ('升级后不接管老位置的数据（看着就像数据全没了）',
     lambda r: edit(r, 'lib/store.php', lambda s: s.replace(
         '            self::adoptLegacy($path);', '            /* nope */;', 1))),
    ('数据文件落在网站目录里也不报警',
     lambda r: edit(r, 'lib/store.php', lambda s: s.replace(
         "return ($d === $r || strncmp($d, $r . '/', strlen($r) + 1) === 0) ? $doc : null;",
         'return null;', 1))),
    ('前缀相同就误判成「在网站目录里」（/var/www 与 /var/wwwdata）',
     lambda r: edit(r, 'lib/store.php', lambda s: s.replace(
         "strncmp($d, $r . '/', strlen($r) + 1) === 0",
         'strncmp($d, $r, strlen($r)) === 0', 1))),
    ('库存页不再提示数据文件放错位置',
     lambda r: edit(r, 'stock.php', lambda s: s.replace(
         '<?php storeBanner(); ?>', '', 1))),
    ('把样式表引用改回写死路径（浏览器会一直用旧缓存）',
     lambda r: edit(r, 'login.php', lambda s: s.replace(
         '''<link rel="stylesheet" href="<?= h(asset('assets/app.css')) ?>">''',
         '<link rel="stylesheet" href="assets/app.css">', 1))),
    ('asset() 在文件读不到时退回不带版本号的地址',
     lambda r: edit(r, 'lib/view.php', lambda s: s.replace(
         "return $cache[$rel] = $rel . '?v=' . rawurlencode($v);",
         "return $cache[$rel] = $rel;", 1))),
    ('周报表把已作废的采购也算进去',
     lambda r: edit(r, 'lib/meat.php', lambda s: s.replace(
         "if (($r['deleted_at'] ?? null) !== null) {\n                continue;",
         "if (false) {\n                continue;", 1))),
    # ---- 发票导入 ----
    ('导入时把退货行当成正常采购（合计会和发票对不上）',
     lambda r: edit(r, 'lib/meatimport.php', lambda s: s.replace(
         "($w !== null && $w < 0) || ($m !== null && $m < 0)", 'false', 1))),
    ('未税金额不折算成含税就入库（和营业额不是一个口径）',
     lambda r: edit(r, 'lib/meatimport.php', lambda s: s.replace(
         '$total   = round($m * (1 + $r), 2);', '$total   = round($m, 2);', 1))),
    ('认税率时不再排除「单价」（「未税单价」里也有 VAT，会被当成税率）',
     lambda r: edit(r, 'lib/meatimport.php', lambda s: s.replace(
         "'vat'       => ['税率', 'vat', '!' => ['单价', 'unit price', '金额', 'amount']],",
         "'vat'       => ['税率', 'vat'],", 1))),
    ('认发票号时不再排除「日期」（「发票日期」会被当成发票号）',
     lambda r: edit(r, 'lib/meatimport.php', lambda s: s.replace(
         "'invoice'   => ['发票号', 'invoice', '!' => ['日期', 'date']],",
         "'invoice'   => ['发票号', 'invoice'],", 1))),
    ('认金额时不再排除「单价」（含税单价和含税金额只差一个字）',
     lambda r: edit(r, 'lib/meatimport.php', lambda s: s.replace(
         "'money_inc' => ['含税金额', 'line amount incl', 'amount incl',\n"
         "                        '!' => ['单价', 'unit price', '均价', 'average']],",
         "'money_inc' => ['含税金额', 'line amount incl', 'amount incl', '含税'],", 1))),
    ('去掉均价合理性检查（认错列就再也没有信号了）',
     lambda r: edit(r, 'lib/meatimport.php', lambda s: s.replace(
         "if ($s['per_kg'] !== null && ($s['per_kg'] < $lo || $s['per_kg'] > $hi)) {",
         'if (false) {', 1))),
    ('认不出品类就随便猜一个',
     lambda r: edit(r, 'lib/meatimport.php', lambda s: s.replace(
         "return isset($known[$t]) ? $t : null;",
         "return isset($known[$t]) ? $t : (string) array_key_first($known);", 1))),
    ('导入不带去重指纹（同一份文件导两次数据翻倍）',
     lambda r: edit(r, 'lib/meat.php', lambda s: s.replace(
         "':ik' => $key,", "':ik' => null,", 1))),
    ('去掉 import_key 的唯一索引（只剩 PHP 里判，绕过就重复）',
     lambda r: edit(r, 'lib/store.php', lambda s: s.replace(
         'CREATE UNIQUE INDEX IF NOT EXISTS idx_mp_impkey',
         'CREATE INDEX IF NOT EXISTS idx_mp_impkey', 1))),
    ('老库不补 import_key 这一列（升级后页面报 no such column）',
     lambda r: edit(r, 'lib/store.php', lambda s: s.replace(
         "self::addColumn($pdo, 'meat_purchase', 'import_key', 'TEXT');", ';', 1))),
    ('出错了却把已经写进去的那半批提交掉（不是回滚）',
     lambda r: edit(r, 'lib/store.php', lambda s: s.replace(
         '            $pdo->rollBack();', '            $pdo->commit();', 1))),
    ('上传后直接入库，跳过核对那一步',
     lambda r: edit(r, 'meatimport.php', lambda s: s.replace(
         "} elseif ($act === 'import') {", '} elseif (false) {', 1))),
    ('不校验上传的到底是不是这次传上来的文件',
     lambda r: edit(r, 'meatimport.php', lambda s: s.replace(
         'if (!is_uploaded_file($tmp)) {', 'if (false) {', 1))),
    ('Excel 序号 1–60 也当日期（那段会差一天）',
     lambda r: edit(r, 'lib/xlsx.php', lambda s: s.replace(
         'if ($n < 61 || $n > 60000) {', 'if ($n < 1 || $n > 60000) {', 1))),
    ('xlsx 解析放开外部实体（XXE）',
     lambda r: edit(r, 'lib/xlsx.php', lambda s: s.replace(
         'LIBXML_NONET | LIBXML_NOENT', '0', 1))),
]

fails = 0
print('把铁律逐条破掉，看自检拦不拦得住：')
for name, breaker in CASES:
    root = tempfile.mkdtemp()
    shutil.copytree(APP, root, dirs_exist_ok=True)
    try:
        breaker(root)
    except AssertionError as e:
        print(f'  ✗ {name} —— 破坏脚本自己没生效（{e}）'); fails += 1
        shutil.rmtree(root, ignore_errors=True); continue
    out = subprocess.run([sys.executable and 'php', 'tests/selftest.php'],
                         cwd=root, capture_output=True, text=True).stdout
    if '失败 0 项' in out:
        print(f'  ✗ {name} —— 破坏后自检仍然通过（这条检查是假的）'); fails += 1
    else:
        print(f'  ✓ {name} —— 自检正确报错')
    shutil.rmtree(root, ignore_errors=True)

print()
print('全部通过：每条铁律都真的拦得住' if fails == 0 else f'有 {fails} 条是假检查')
sys.exit(1 if fails else 0)
