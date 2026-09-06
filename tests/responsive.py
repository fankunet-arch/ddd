"""在多种真实设备宽度下检查页面：不得横向溢出、点击目标够大、文字不过小。"""
import sys, os, glob
from playwright.sync_api import sync_playwright

# 浏览器路径可用环境变量 CHROME 覆盖（换机器时多半要改）
CHROME = os.environ.get('CHROME', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome')

# 真实机型宽度
DEVICES = [
    ('iPhone SE',    375, 667,  True),
    ('iPhone 14',    390, 844,  True),
    ('Android 常见', 360, 800,  True),
    ('iPad 竖屏',    768, 1024, True),
    ('笔记本',      1280, 800,  False),
    ('宽屏',        1600, 900,  False),
]

pages = sorted(glob.glob(sys.argv[1] + '/out_*.html'))
shot_dir = sys.argv[2] if len(sys.argv) > 2 else None

fails = []
with sync_playwright() as p:
    b = p.chromium.launch(executable_path=CHROME, args=['--no-sandbox'])
    for name, w, hgt, mobile in DEVICES:
        ctx = b.new_context(viewport={'width': w, 'height': hgt},
                            device_scale_factor=2 if mobile else 1,
                            is_mobile=mobile, has_touch=mobile)
        pg = ctx.new_page()
        for f in pages:
            label = os.path.basename(f)[4:-5]
            pg.goto('file://' + os.path.abspath(f))
            pg.wait_for_timeout(120)

            # 1) 页面不得横向溢出
            over = pg.evaluate("""() => {
                const d = document.documentElement;
                return d.scrollWidth - d.clientWidth;
            }""")
            if over > 1:
                fails.append(f'{name}({w}px) {label}: 页面横向溢出 {over}px')
                # 找出是谁撑的
                who = pg.evaluate("""() => {
                    const vw = document.documentElement.clientWidth, out = [];
                    document.querySelectorAll('body *').forEach(e => {
                        const r = e.getBoundingClientRect();
                        if (r.right > vw + 1 && r.width > 0)
                            out.push(e.tagName + '.' + (e.className||'').toString().slice(0,40)
                                     + ' right=' + Math.round(r.right));
                    });
                    return out.slice(0, 4);
                }""")
                for x in who:
                    fails.append(f'    └ {x}')

            # 2) 滚动容器自身不得超出视口（表格比容器宽是预期的，那正是滚动的意义）
            spill = pg.evaluate("""() => {
                const vw = document.documentElement.clientWidth, out = [];
                document.querySelectorAll('.tablewrap').forEach(wr => {
                    const r = wr.getBoundingClientRect();
                    if (r.right > vw + 1) out.push(Math.round(r.right - vw));
                });
                return out;
            }""")
            if spill:
                fails.append(f'{name}({w}px) {label}: 表格容器超出视口 {spill}px')

            if mobile:
                # 3) 可点击元素高度不小于 38px（手指友好）
                small = pg.evaluate("""() => {
                    const out = [];
                    document.querySelectorAll('a,button,select,input,summary').forEach(e => {
                        const r = e.getBoundingClientRect();
                        if (r.width === 0 || r.height === 0) return;
                        if (e.closest('.note,.meta,.foot,table')) return;   // 正文里的链接不算
                        // 复选框本身小没关系，真正的点击区是外层 label
                        if (e.type === 'checkbox' || e.type === 'radio') return;
                        if (r.height < 38) out.push(e.tagName + '.' +
                            (e.className||'').toString().slice(0,26) + '=' + Math.round(r.height));
                    });
                    return [...new Set(out)].slice(0, 5);
                }""")
                if small:
                    fails.append(f'{name}({w}px) {label}: 点击目标偏小 {small}')

                # 4) 正文字号不小于 12px
                tiny = pg.evaluate("""() => {
                    const out = [];
                    document.querySelectorAll('td,th,p,label,a,span').forEach(e => {
                        if (!e.textContent.trim()) return;
                        const r = e.getBoundingClientRect();
                        if (r.width === 0 || r.height === 0) return;   // 隐藏元素不算
                        const fs = parseFloat(getComputedStyle(e).fontSize);
                        if (fs && fs < 11.5) out.push(e.tagName + '.' +
                            (e.className||'').toString().slice(0,26) + '=' + fs);
                    });
                    return [...new Set(out)].slice(0, 5);
                }""")
                if tiny:
                    fails.append(f'{name}({w}px) {label}: 字号过小 {tiny}')

        ctx.close()

    # 截图单独一轮 —— full_page 截图会临时改视口，混在测量里会干扰设备模拟
    if shot_dir:
        os.makedirs(shot_dir, exist_ok=True)
        for name, w, hgt, mobile in DEVICES:
            ctx = b.new_context(viewport={'width': w, 'height': hgt},
                                device_scale_factor=2 if mobile else 1,
                                is_mobile=mobile, has_touch=mobile)
            pg = ctx.new_page()
            for f in pages:
                label = os.path.basename(f)[4:-5]
                if label not in ('开台核对', '营业额-区间', '登录页', '岗位-单量排名', '菜品-排行榜'):
                    continue
                pg.goto('file://' + os.path.abspath(f))
                pg.wait_for_timeout(150)
                pg.screenshot(path=f'{shot_dir}/{label}_{w}.png', full_page=True)
            ctx.close()
    b.close()

if fails:
    print(f'  ✗ {len(fails)} 处问题：')
    for x in fails[:40]:
        print('   ', x)
    sys.exit(1)
print(f'  ✓ {len(pages)} 个页面 × {len(DEVICES)} 种设备宽度：'
      f'无横向溢出、表格不越界、点击目标与字号达标')
