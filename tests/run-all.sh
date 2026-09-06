#!/bin/bash
# 全套测试，一条命令跑完。都不碰真实数据库 —— POS 用桩，自有数据用临时 SQLite。
#
#   bash tests/run-all.sh
#
# 需要：php（自检和流程测试）、python3 + playwright（自适应检查，没有会自动跳过）
# 浏览器路径可用 CHROME 环境变量覆盖。
set -u
# 关键：不加 pipefail 的话，`python3 ... | tail -2` 取的是 tail 的退出码，
# 脚本崩了也照样算成功 —— 这类「假通过」正是这套测试要防的东西。
set -o pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
fails=0
line(){ printf '\n\033[1m── %s ──\033[0m\n' "$1"; }

line "逻辑自检（不连数据库）"
php "$HERE/selftest.php" | tail -3
php "$HERE/selftest.php" >/dev/null 2>&1 || fails=$((fails+1))

line "真实渲染 · 流程"
for f in "$HERE"/flow/*.sh; do
    n=$(basename "$f")
    [ "$n" = "smoke.sh" ] && continue        # smoke 下面单独跑，还要接着做两项检查
    printf '  %-20s ' "$n"
    if out=$(bash "$f" 2>&1); then echo "${out##*$'\n'}"; else
        echo "${out##*$'\n'}"; echo "$out" | grep '✗' | head -5 | sed 's/^/      /'
        fails=$((fails+1))
    fi
done

line "页面渲染 + 表格对齐 + 自适应"
# smoke.sh 最后一行会打印它把 HTML 存到了哪里，后面两项检查都吃那批文件
smoke=$(bash "$HERE/flow/smoke.sh" 2>&1) || fails=$((fails+1))
echo "$smoke" | grep -E '全部通过|失败' | tail -1 | sed 's/^/  smoke: /'
D=$(echo "$smoke" | tail -1 | sed -n 's/^SAVED://p')
if [ -n "${D:-}" ] && [ -d "$D" ]; then
    php "$HERE/tablecheck.php" "$D"/out_*.html | tail -1 || fails=$((fails+1))
    if python3 -c 'import playwright' 2>/dev/null; then
        python3 "$HERE/responsive.py" "$D" | tail -2 || fails=$((fails+1))
    else
        echo "  （跳过自适应检查：没装 playwright）"
    fi
    rm -rf "$D"
else
    echo "  ✗ smoke.sh 没有输出 HTML 目录，后面两项检查没跑成"; fails=$((fails+1))
fi

line "破坏演练（把铁律逐条破掉，确认自检真的会拦）"
python3 "$HERE/sabotage/run.py" | tail -2 || fails=$((fails+1))

echo
if [ "$fails" = "0" ]; then echo "全部通过"; else echo "有 $fails 组没过"; exit 1; fi
