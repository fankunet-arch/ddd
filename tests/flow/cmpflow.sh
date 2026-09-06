#!/bin/bash
# 期间对比页的真实渲染验证
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
T=$(mktemp -d)
cp -r "$APP"/{compare.php,open.php,index.php,dish.php,station.php,login.php,config.php,lib,assets} "$T"/

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
    public static function select(string $sql, array $p=[]): array {
        // 从绑定参数里取出区间，按日期造数据：本期每天 100，上期每天 80
        $from = substr((string)($p[':from'] ?? ''),0,10);
        $to   = substr((string)($p[':to']   ?? ''),0,10);
        if (str_contains($sql,'FROM print_class'))
            return [['print_class_id'=>6,'print_class_name'=>'bebidas'],
                    ['print_class_id'=>11,'print_class_name'=>'热菜']];
        if (str_contains($sql,'FROM menu_item'))
            return [['item_id'=>431,'item_name1'=>'Agua','item_name2'=>'','print_class'=>6,'item_type'=>0,'price_1'=>2.5],
                    ['item_id'=>501,'item_name1'=>'Ramen','item_name2'=>'','print_class'=>11,'item_type'=>0,'price_1'=>9.0]];
        if (!$from) return [];
        // 本期 = 较晚的那一段
        $recent = strtotime($from) >= strtotime('-8 day');
        $base = $recent ? 100.0 : 80.0;
        $out=[]; $d=strtotime($from);
        while ($d < strtotime($to)) {
            $day = date('Y-m-d',$d);
            if (str_contains($sql,'menu_item_id') && str_contains($sql,'GROUP BY menu_item_id')) {
                foreach ([431=>'Agua',501=>'Ramen'] as $id=>$nm)
                    $out[]=['menu_item_id'=>$id,'item_name'=>$nm,'seg'=>'day',
                            'qty'=>$recent?($id==431?9:4):($id==431?5:6),'times'=>3,'amount'=>$base];
                break;
            }
            if (str_contains($sql,'AS pc')) {
                foreach ([6,11] as $pc)
                    $out[]=['pc'=>$pc,'seg'=>'day','orders'=>$recent?12:9,'items'=>2,
                            'qty'=>$recent?30:22,'lines_cnt'=>25,'amount'=>$base];
                break;
            }
            $out[]=['biz_date'=>$day,'seg'=>'day','checks'=>2,'guests'=>4,'actual'=>$base,
                    'original'=>$base,'discount'=>0,'service'=>0,'tax'=>$base/11,
                    'should_amt'=>$base,'ret'=>0];
            $out[]=['biz_date'=>$day,'seg'=>'night','checks'=>3,'guests'=>6,'actual'=>$base*2,
                    'original'=>$base*2,'discount'=>0,'service'=>0,'tax'=>$base*2/11,
                    'should_amt'=>$base*2,'ret'=>0];
            $d = strtotime('+1 day',$d);
        }
        return $out;
    }
    public static function selectOne(string $s, array $p=[]): ?array { return self::select($s,$p)[0]??null; }
}
PHP

PORT=8230
php -S 127.0.0.1:$PORT -t "$T" >/dev/null 2>&1 & SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf "$T"' EXIT
for i in $(seq 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && break; sleep 0.2; done
J="$T/c.txt"; fails=0
chk(){ if echo "$2" | grep -q "$3"; then echo "  ✓ $1"; else echo "  ✗ $1"; fails=$((fails+1)); fi }
nochk(){ if echo "$2" | grep -q "$3"; then echo "  ✗ $1"; fails=$((fails+1)); else echo "  ✓ $1"; fi }

tok=$(curl -s -b "$J" -c "$J" "http://127.0.0.1:$PORT/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$tok&password=pw" "http://127.0.0.1:$PORT/login.php"

echo "期间对比页："
# 未提交时的引导页
first=$(curl -s -b "$J" "http://127.0.0.1:$PORT/compare.php")
chk "首次打开有引导说明" "$first" '选好期间后点'
chk "导航里有入口" "$first" 'compare.php'

# 默认：近 7 天 vs 前 7 天
p=$(curl -s -b "$J" "http://127.0.0.1:$PORT/compare.php?go=1&preset=7")
chk "渲染完成" "$p" '</body>'
nochk "无 PHP 报错" "$p" 'Fatal error\|Warning:\|Notice:\|Uncaught'
chk "标出本期" "$p" '本期'
chk "标出上期" "$p" '上期'
chk "两期都是 7 天" "$p" '7 天'
chk "有涨跌显示" "$p" 'class="trend'
chk "有分时段对比" "$p" '分时段对比'
chk "有逐日对照" "$p" '逐日对照'
chk "逐日对照标出星期" "$p" '周[一二三四五六日]'
# 本期每天 300（白100+夜200），上期 240 → 涨 25%
chk "涨跌率算对（25.0%）" "$p" '25.0%'
chk "涨跌额带箭头和正号" "$p" '▲ +'
nochk "默认不查菜品明细" "$p" '菜品点单量变化'
nochk "默认不查岗位" "$p" '岗位单量变化'

# 星期几必须一一对齐
rows=$(echo "$p" | tr -d '\n' | grep -o '逐日对照.*</table>' | head -1)
pairs=$(echo "$rows" | grep -o '周[一二三四五六日]' | paste - - | awk '$1!=$2' | wc -l)
[ "$pairs" = "0" ] && echo "  ✓ 逐日对照星期几全部对齐" \
  || { echo "  ✗ 有 $pairs 行星期几没对齐"; fails=$((fails+1)); }

# 勾选菜品与岗位
p2=$(curl -s -b "$J" "http://127.0.0.1:$PORT/compare.php?go=1&preset=7&with_dish=1&with_station=1")
chk "勾选后出现菜品对比" "$p2" '菜品点单量变化'
chk "勾选后出现岗位对比" "$p2" '岗位单量变化'
chk "菜品分卖多/卖少两栏" "$p2" '卖得更多了'
chk "菜品对比里有 Agua" "$p2" 'Agua'
# 同一个菜不能同时出现在「卖得更多」和「卖得更少」里（菜品少于 30 个时最容易踩）
up=$(echo "$p2" | tr -d '\n' | grep -o '卖得更多了.*卖得更少了' | grep -c 'Ramen' || true)
dn=$(echo "$p2" | tr -d '\n' | sed 's/.*卖得更少了//' | grep -c 'Agua' || true)
[ "$up" = "0" ] && echo "  ✓ 跌了的菜不出现在「卖得更多」里" \
  || { echo "  ✗ Ramen（跌了）混进了「卖得更多」"; fails=$((fails+1)); }
[ "$dn" = "0" ] && echo "  ✓ 涨了的菜不出现在「卖得更少」里" \
  || { echo "  ✗ Agua（涨了）混进了「卖得更少」"; fails=$((fails+1)); }
nochk "分时段表不出现未翻译的 total" "$p2" '>total'
chk "持平显示成「持平」而不是 0 0.0%" "$p2" '持平'
# 颜色之外还必须有箭头和正负号 —— 约 8% 的男性分不出红绿
chk "涨用 ▲ 标出" "$p2" '▲'
chk "跌用 ▼ 标出" "$p2" '▼'
chk "涨跌带正负号" "$p2" '▲ +'
chk "涨用绿色类" "$p2" 'class="trend up"'
chk "跌用红色类" "$p2" 'class="trend down"'
nochk "默认不带红涨绿跌的 ru 类" "$p2" 'trend up ru'
# 快捷下拉必须真的生效（表单会带上旧的 start/end）
for n in 14 30; do
  pp=$(curl -s -b "$J" "http://127.0.0.1:$PORT/compare.php?go=1&preset=$n&start=2026-08-27&end=2026-09-02")
  if echo "$pp" | grep -q "· $n 天"; then echo "  ✓ 切到近 $n 天真的生效"
  else echo "  ✗ 切到近 $n 天没生效"; fails=$((fails+1)); fi
done
# 垃圾日期不能白屏（prevRange 在校验之前就被调用）
gd=$(curl -s -b "$J" "http://127.0.0.1:$PORT/compare.php?go=1&preset=&start=abc&end=xyz")
chk "垃圾日期不白屏" "$gd" '</body>'
chk "垃圾日期给出错误提示" "$gd" '日期格式不正确'

# 自选日期 + 自动对比期
p3=$(curl -s -b "$J" "http://127.0.0.1:$PORT/compare.php?go=1&preset=&start=2026-08-01&end=2026-08-31")
chk "自选区间可用" "$p3" '2026-08-01'
chk "自动算出上期起点 07-01" "$p3" '2026-07-01'
chk "自动算出上期终点 07-31" "$p3" '2026-07-31'

# 手动指定对比期（去年同月）
p4=$(curl -s -b "$J" "http://127.0.0.1:$PORT/compare.php?go=1&preset=&start=2026-08-01&end=2026-08-31&manual=1&pstart=2025-08-01&pend=2025-08-31")
chk "手动对比期生效" "$p4" '2025-08-01'
chk "手动对比期终点" "$p4" '2025-08-31'

# 两期不等长要警告
p5=$(curl -s -b "$J" "http://127.0.0.1:$PORT/compare.php?go=1&preset=&start=2026-08-01&end=2026-08-07&manual=1&pstart=2026-07-01&pend=2026-07-20")
chk "不等长时给出警告" "$p5" '两期天数不一样'
chk "不等长时提示看日均" "$p5" '日均'
chk "多出来的天标为无对应" "$p5" '无对应'

# 超过上限要拦
p6=$(curl -s -b "$J" "http://127.0.0.1:$PORT/compare.php?go=1&preset=&start=2026-01-01&end=2026-12-31")
chk "本期超上限被拦" "$p6" '日期跨度不能超过'
p7=$(curl -s -b "$J" "http://127.0.0.1:$PORT/compare.php?go=1&preset=&start=2026-08-01&end=2026-08-07&manual=1&pstart=2020-01-01&pend=2026-08-07")
chk "对比期超上限也被拦" "$p7" '日期跨度不能超过'

# 含今天要提示
p8=$(curl -s -b "$J" "http://127.0.0.1:$PORT/compare.php?go=1&preset=7")
chk "本期含今天时给出提醒" "$p8" '今天还没营业完'

echo
[ "$fails" = "0" ] && echo "全部通过" || { echo "失败 $fails 项"; exit 1; }
