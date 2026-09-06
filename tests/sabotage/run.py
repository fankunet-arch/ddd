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
    ('把数据文件挪进网站目录',
     lambda r: edit(r, 'lib/store.php', lambda s: s.replace(
         "dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data'", "__DIR__ . '/data'", 1))),
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
