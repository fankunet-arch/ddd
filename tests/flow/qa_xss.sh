#!/bin/bash
# 体检 1：数据库里的字符串是操作员随手填的，塞入恶意内容后页面必须仍然安全
set -e
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
T=$(mktemp -d)
cp -r "$APP"/{open.php,index.php,dish.php,station.php,login.php,config.php,lib,assets} "$T"/

cat > "$T/lib/db.php" <<'PHP'
<?php
declare(strict_types=1);
final class Db {
    private static array $cfg=[]; private static array $over=[];
    public static function config(): array {
        if (!self::$cfg) { self::$over=(array)require __DIR__.'/../config.php';
            self::$cfg=self::$over+(array)require __DIR__.'/settings.php';
            self::$cfg['password']='pw'; }
        return self::$cfg;
    }
    public static function defaults(): array { return (array) require __DIR__.'/settings.php'; }
    public static function overrides(): array { self::config(); return self::$over; }
    public static function assertReadOnly(string $s): void {}
    public static function availableDrivers(): array { return ['mysqli']; }
    public static function driverName(): string { return 'mysqli'; }

    // 各种恶意/畸形字符串
    private static function evil(int $i): string {
        $e = [
            '<script>window.XSS1=1</script>',
            '"><img src=x onerror="window.XSS2=1">',
            "'; alert(1); //",
            '</script><script>window.XSS3=1</script>',
            '<!--<script>window.XSS4=1</script>-->',
            "\xC3\x28 坏UTF8",                       // 非法 UTF-8 序列
            str_repeat('长', 300),                    // 超长名字
            '&lt;已转义过&gt;&amp;',                   // 双重转义陷阱
        ];
        return $e[$i % count($e)];
    }
    public static function select(string $sql, array $p = []): array {
        if (str_contains($sql,'FROM order_head'))
            return [['order_head_id'=>1,'t0'=>date('Y-m-d H:i:s'),'guests'=>2,
                     'table_name'=>self::evil(0),'employee'=>self::evil(1),'amount'=>10.0,
                     'checks'=>1,'eat_type'=>0,'status'=>0,'settled'=>0],
                    ['order_head_id'=>2,'t0'=>date('Y-m-d H:i:s'),'guests'=>4,
                     'table_name'=>self::evil(3),'employee'=>self::evil(5),'amount'=>20.0,
                     'checks'=>1,'eat_type'=>0,'status'=>0,'settled'=>0]];
        if (str_contains($sql,'AS combo_qty'))
            return [['order_head_id'=>1,'combo_qty'=>2,'drink_qty'=>2,'drink_amount'=>5.0,
                     'dish_qty'=>4,'lines_cnt'=>3]];
        if (str_contains($sql,'FROM print_class'))
            return [['print_class_id'=>6,'print_class_name'=>self::evil(2)],
                    ['print_class_id'=>11,'print_class_name'=>self::evil(4)]];
        if (str_contains($sql,'FROM menu_item')) {
            $out=[];
            foreach ([1890,431,432,501,777,900] as $k=>$id)
                $out[] = ['item_id'=>$id,'item_name1'=>self::evil($k),'item_name2'=>'',
                          'print_class'=>($k%2?6:11),'item_type'=>($id==900?1:0),'price_1'=>9.9];
            return $out;
        }
        if (str_contains($sql,'AS seg') || str_contains($sql,'biz_date'))
            return [['biz_date'=>date('Y-m-d'),'seg'=>'day','checks'=>2,'guests'=>6,
                     'actual'=>100.0,'original'=>110.0,'discount'=>-10.0,'service'=>0.0,
                     'tax'=>9.0,'should_amt'=>100.0,'ret'=>0.0,
                     'menu_item_id'=>431,'item_name'=>self::evil(0),'qty'=>3.0,'times'=>2,
                     'amount'=>30.0,'pc'=>6,'orders'=>2,'items'=>2,'lines_cnt'=>3]];
        return [];
    }
    public static function selectOne(string $s, array $p=[]): ?array { return self::select($s,$p)[0]??null; }
}
PHP

PORT=8210
php -S 127.0.0.1:$PORT -t "$T" >/dev/null 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf "$T"' EXIT
for i in $(seq 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break; sleep 0.2; done

J="$T/c.txt"; fails=0
tok=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$tok&password=pw" "http://127.0.0.1:$PORT/login.php"

echo "体检 1：数据库里的恶意字符串"
D=$(date +%F)
for page in "open.php" "index.php?start=$D&end=$D" "dish.php?start=$D&end=$D" \
            "dish.php?start=$D&end=$D&pc=6" "station.php?start=$D&end=$D"; do
  html=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/$page")
  name="${page%%\?*}"
  # 1) 注入的标签必须是转义后的文本，不能是真的标签
  if echo "$html" | grep -q '<script>window\.XSS'; then
     echo "  ✗ $name 出现未转义的 <script> 注入"; fails=$((fails+1))
  else echo "  ✓ $name 注入的 <script> 已转义"; fi
  # 2) 不能出现真的事件属性（转义后是 onerror=&quot;）
  if echo "$html" | grep -q 'onerror="'; then
     echo "  ✗ $name 出现属性注入"; fails=$((fails+1))
  else echo "  ✓ $name 无属性注入"; fi
  # 3) 页面必须真的渲染出来（不是空白/500）
  if echo "$html" | grep -q '</body>'; then echo "  ✓ $name 正常渲染完成"
  else echo "  ✗ $name 渲染中断（可能 500 或非法 UTF-8 导致输出被截断）"; fails=$((fails+1)); fi
  # 4) 不能有 PHP 报错泄漏
  if echo "$html" | grep -qi 'Fatal error\|Warning:\|Notice:\|Deprecated:'; then
     echo "  ✗ $name 泄漏 PHP 错误信息"; fails=$((fails+1))
  else echo "  ✓ $name 无 PHP 错误泄漏"; fi
done

# 菜品下拉的 JSON 内嵌在 <script> 里，必须不能被 </script> 截断
html=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/dish.php?start=$D&end=$D")
# JSON 内嵌在 <script> 里：整个数组字面量中不得出现任何裸的 < 或 >，
# 否则菜名里的 <script>/<!-- 会让 HTML 解析器进入 script-data-escaped 状态
json=$(echo "$html" | tr -d '\n' | grep -o 'window.MENU_ITEMS = .*;' | head -1)
if [ -z "$json" ]; then
  echo "  ✗ 没找到菜品 JSON"; fails=$((fails+1))
elif echo "${json%;}" | grep -q '[<>]'; then
  echo "  ✗ 菜品 JSON 里有裸的尖括号，可能截断 <script> 块"; fails=$((fails+1))
elif echo "$json" | grep -q 'u003C'; then
  echo "  ✓ 菜品 JSON 里的尖括号已转成 \\uXXXX"
else
  echo "  ✗ 菜品 JSON 转义情况异常"; fails=$((fails+1)); fi

# 最终判定：用真浏览器加载，看注入的脚本到底有没有执行
echo "  ---- 浏览器实测 ----"
python3 - "$PORT" "$J" <<'PYEOF' || fails=$((fails+1))
import os, sys, re
from playwright.sync_api import sync_playwright
port, jar = sys.argv[1], sys.argv[2]
cookies=[]
for line in open(jar):
    line = line.lstrip('#').replace('HttpOnly_','').strip()
    if not line or line.startswith('#'): continue
    p=line.split('\t')
    if len(p)==7:
        cookies.append({'name':p[5],'value':p[6],'domain':'127.0.0.1','path':p[2]})
import datetime
d=datetime.date.today().isoformat()
pages=['open.php',f'index.php?start={d}&end={d}',f'dish.php?start={d}&end={d}',
       f'dish.php?start={d}&end={d}&pc=6',f'station.php?start={d}&end={d}']
bad=0
with sync_playwright() as pw:
    b=pw.chromium.launch(executable_path=os.environ.get('CHROME','/opt/pw-browsers/chromium'))
    ctx=b.new_context(); ctx.add_cookies(cookies)
    pg=ctx.new_page()
    errs=[]
    pg.on('pageerror', lambda e: errs.append(str(e)))
    for u in pages:
        pg.goto(f'http://127.0.0.1:{port}/{u}')
        hit=pg.evaluate("[1,2,3,4].filter(i=>window['XSS'+i]!==undefined)")
        if hit:
            print(f'  \u2717 {u} 注入脚本被执行了: XSS{hit}'); bad+=1
        else:
            print(f'  \u2713 {u} 注入脚本未执行')
    if errs:
        print('  \u2717 浏览器控制台报错:', errs[:3]); bad+=1
    else:
        print('  \u2713 浏览器控制台无报错')
    b.close()
sys.exit(1 if bad else 0)
PYEOF

echo
[ "$fails" = "0" ] && echo "全部通过" || { echo "失败 $fails 项"; exit 1; }
