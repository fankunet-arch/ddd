# 营业数据查询

针对现有 POS 数据库（MySQL 5.5 / PHP 8.2）的营业数据查询工具，**纯查询，不写库**。

功能：
- 按日期区间统计营业额与人数，分**白天 / 晚上 / 全天**三档，也可只看当天
- 查询指定菜品在区间内的点单情况
- 点单最多 / 最少的 10 个菜品，以及**每个岗位**的最多 / 最少 10 个菜品

菜品页的「岗位」下拉框有两种用法：
- **全部岗位**（默认）：先给出岗位汇总表，再**逐个列出每个岗位**的最多 / 最少 10 个菜品
- **选中某个岗位**：整页只统计该岗位，最多 / 最少 10 个都收窄到这个岗位内

岗位汇总表每行末尾都有「只看这个岗位」的直达链接。

菜名过长时会截断成省略号（全宽表格约 300px，岗位左右并排时约 170px），
**鼠标悬停可看到完整菜名**。截断只是显示层面的，导出或复制得到的仍是完整名称。

---

## 一、安全性：只做查询

程序**不会**对数据库做任何写入、删除或结构变更。三道防线：

1. **代码层**：全程序只有 `lib/db.php` 一个数据库出口，只提供 `select()`，
   没有 `exec()`、没有事务、不开启多语句执行。
2. **语句层**：每条 SQL 执行前都要通过 `assertReadOnly()` —— 必须以 `SELECT` 开头，
   不得含分号，不得出现 `INSERT / UPDATE / DELETE / DROP / ALTER / TRUNCATE /
   CREATE / REPLACE / GRANT / CALL / INTO OUTFILE` 等任何写操作关键字
   （注释会先被剥离，防止关键字藏在注释里绕过）。
3. **数据库层**（需要你配合）：请在 `config.php` 里填一个**只有 SELECT 权限的账号**：

   ```sql
   CREATE USER 'report_ro'@'%' IDENTIFIED BY '你的密码';
   GRANT SELECT ON coolroid.* TO 'report_ro'@'%';
   FLUSH PRIVILEGES;
   ```

   这样即使程序出任何问题，数据库也会直接拒绝写操作。

所有用户输入（日期、菜品 ID、就餐方式）一律走 PDO 参数绑定，表名走白名单校验。

## 二、安装

1. 把整个目录放到 Web 服务器可访问的位置。
2. 编辑 `config.php`，填入数据库地址、库名、只读账号密码。
3. 浏览器打开 `index.php`。

无需 Composer，无外部依赖，无需写权限（不产生任何缓存或日志文件）。

### 对 PHP 扩展的要求

只需要 **`pdo_mysql` 或 `mysqli` 其中之一**，有哪个用哪个，`config.php` 里
`driver => 'auto'` 会自动选（优先 PDO，没有就用 mysqli）。两条路功能完全一致。

所以如果你的环境只装了 `mysqli`，**不需要为了这个程序去改 php.ini**。

不依赖 `mbstring`、`iconv`、`intl`、`curl`、`openssl` 等任何其他扩展。

环境诊断（完全独立，不依赖任何数据库扩展，一定跑得起来）：

```bash
php tests/env.php
```

它会报出：PHP 读的是哪个 php.ini、扩展目录在哪、目录里有哪些数据库扩展文件、
哪些扩展加载了、程序能不能跑、以及出 500 时去哪里找真正的错误。

> **命令行和 Web 服务器往往用不同的 php.ini**，所以请在浏览器里也访问一次
> `tests/env.php`，对比两边报出的路径 —— 改错文件是"改了没效果"最常见的原因。

### 部署后先跑体检

```bash
php tests/checkdb.php
```

也可以直接用浏览器访问 `tests/checkdb.php`。它会依次检查：
PHP 扩展是否齐全、配置是否填好、数据库能否连上、账号是不是只读、
需要的 6 张表在不在、实时表有多大、数据时间范围、
以及实跑近 7 天的四条统计查询并报出耗时和结果抽样。
全程只执行 SELECT。

出问题时它会直接指出是哪一环，比在页面上看一句"查询失败"好定位得多。

### 逻辑自检（不需要连数据库）

```bash
php tests/selftest.php
```

## 三、统计口径

以下口径均已用你导出的 **89,139 条真实账单（2024-01-22 ~ 2026-08-13，931 个营业日）** 验证。

### 时段划分

| 时段 | 范围 | 判定依据 |
|---|---|---|
| 白天 | 08:00 – 17:30 | **开台时间** `order_start_time` |
| 晚上 | 18:00 – 次日 02:00 | 同上 |
| 时段外 | 其余时间 | 同上 |

- **营业日**：凌晨 02:00 之前的单归入**前一天**。晚市跨零点的单子不会被算到第二天。
- 全库实测：白天 51,178 单 / 晚上 37,948 单 / 时段外 **13 单**（全部在 17:30–17:53 之间）。
  时段外的单子会**单列一行**显示，不会凭空消失，保证 `全天 = 白天 + 晚上 + 时段外` 恒等。
- 已验证：**逐日单独查询之和 == 整段一次查询**，区间边界无重复、无遗漏。

时段可在 `config.php` 里调整（`day_start` / `day_end` / `night_start` / `night_end`）。
改 `night_end` 时必须同步改 `day_cut_hour`，两者要保持一致。

### 营业额

以 **`actual_amount`（实收）** 为准。页面同时列出金额构成便于对账：

```
应收 should_amount = 原价 original_amount + 折扣 discount_amount   ← 折扣以负数记录
实收 actual_amount = 应收 should_amount   − 退单 return_amount
```

真实数据 5000 条抽样，两条恒等式 5000/5000 成立。

> **税额是价内含税**（IVA 10%），已经包含在实收里，页面单独列出仅供参考，**不要再加上去**。

### 人数

**必须按 `order_head_id` 去重。** 一张单被拆成多个 `check_id` 结账时，
每个 check 都重复写了相同的 `customer_num`。

实测（最近 90 天）：直接 `SUM(customer_num)` = 24,758 人，
按订单去重后 = **22,125 人**，直接相加会**虚高 11.9%**。

程序内层子查询按 `order_head_id` 归并：人数取 `MAX`，金额取 `SUM`，时间取 `MIN`。

### 菜品点单

明细表里混着大量非菜品行，以下全部剔除：

| 剔除对象 | 条件 | 说明 |
|---|---|---|
| 送厨房标记行 | `menu_item_id = -3` | 形如 `**999 Enviado 19:16**` |
| 付款方式行 | `menu_item_id = -4` | 形如 `EFECTIVO` |
| 退菜 | `is_return_item = 1` | |
| 套餐 / 做法子项 | `condiment_belong_item <> 0` | 实测为**负数**（如 `-77`），可用开关重新计入 |
| 做法 / 口味项 | `menu_item.item_type = 1` | 如 `S/Pepino`（不要黄瓜），共 172 个，不是菜 |
| 数量为 0 的行 | `quantity <= 0` | |

> ⚠️ **`is_send` 不是"赠送"，是"已送厨房"** —— 84% 的正常菜品都是 1。
> 千万不要拿它当赠送过滤，否则菜会被全部滤光。程序没有使用这个字段。

菜品金额 = `SUM(actual_price × quantity)`。
**不使用 `sales_amount`** —— 该字段在历史明细和实时明细里实测全是 `0` 或 `NULL`。

### 岗位

**岗位 = `print_class`（打印分类／出品部门）**，共 14 个：
Kitchen、Sushi 1–7、POS 1、POS 2、bebidas、热菜、铁板、油锅。

> ⚠️ **明细表自带的 `print_class` 字段是废的** —— 历史明细（2024-01）和实时明细（2025-07）
> 抽样中该字段全部是 `0` 或 `NULL`，从来没有真实岗位 ID。
>
> 因此岗位来自 **`menu_item.print_class`**：单独查一次 `menu_item`（666 行）和
> `print_class`（14 行）两张小字典表，在 PHP 内存里做映射，**不与大表 JOIN**。
>
> 副作用：如果某道菜后来被调整过所属岗位，历史统计会按**当前**岗位归类。

## 四、性能设计

需求要求"数据表单独统计，非必要每次只统计一张表"，实现如下：

| 页面 | 查询的表 | 说明 |
|---|---|---|
| 营业额统计 | `history_order_head`（+ 可选 `order_head`） | 无任何 JOIN |
| 菜品统计 | `history_order_detail`（+ 可选 `order_detail`） | 无任何 JOIN |
| 字典 | `menu_item`、`print_class` | 各几百行，单独查询，内存映射 |

- 营业额与菜品统计**完全分开**，互不影响，不会一次拉起两张大表。
- 时间条件写成 `列 >= :from AND 列 < :to`，**不对索引列做任何函数运算**，
  可以吃满 `ids_order_start_time`（账单头）和 `idx_order_time`（明细）两个现有索引。
- 菜品排行**只查一次表**：一次取回全部菜品 × 时段的汇总（约 600 菜 × 3 时段 ≈ 1800 行），
  Top10 / Bottom10 / 各岗位排行全部在 PHP 内存里算出，不再回数据库。
  这也绕开了 MySQL 5.5 没有窗口函数、无法写 `ROW_NUMBER()` 分组取 TopN 的限制。
- 日期跨度上限 92 天（约 3 个月），在 `config.php` 的 `max_range_days` 调整。

数据量参考：931 个营业日共 89,139 单，单日最多 228 单，3 个月约 9,000 单，压力很小。

页面底部会显示每条查询的耗时，方便你确认实际表现。

## 五、⚠️ 需要你确认的一件事：实时表的数据量

"包含当天未日结数据"选项会额外查询 `order_head` / `order_detail` 两张实时表。
**这两张表没有时间索引**（只有主键），如果它们在日结后不清空、长期累积，
这个查询会变成全表扫描。

你导出的 `order_detail` 样本里有一条 `order_detail_id = 1387928`、
时间是 `2025-07-14` 的记录 —— 也就是一年多前的数据仍在实时表里。
请跑一下这条（只读）语句确认：

```sql
SELECT COUNT(*) AS rows_now,
       MIN(order_time) AS oldest,
       MAX(order_time) AS newest
FROM order_detail;
```

- **几千行以内** → 保持默认，勾选"包含当天未日结数据"没问题。
- **几十万行以上** → 建议平时**取消勾选**该选项（只查历史表），只在需要看当天实时数据时临时勾上；
  或者请 POS 厂商给 `order_detail.order_time` 和 `order_head.order_start_time` 加索引
  （这属于改表结构，本程序不会做，需要你自己决定）。

历史表 `history_order_head` / `history_order_detail` 索引齐全，不受此影响。

## 六、目录结构

```
config.php          数据库连接 + 营业时段配置（唯一需要修改的文件）
index.php           营业额 / 人数统计页
dish.php            菜品点单统计页
lib/db.php          只读数据库访问层（唯一的数据库出口）
lib/biz.php         SQL 构造与业务口径
lib/report.php      汇总、排行计算（纯内存，不访问数据库）
lib/view.php        页面公共组件
assets/app.css      样式
assets/app.js       菜品搜索下拉框（字典随页面下发，搜索全在浏览器完成）
tests/env.php       PHP 环境诊断（完全独立，不依赖任何扩展）
tests/checkdb.php   数据库连接与数据体检（需要数据库）
tests/selftest.php  逻辑自检（不需要数据库）
```

`lib/db.php` 里 `PdoDriver` 和 `MysqliDriver` 两个类实现同一个 `DbDriver` 接口，
上层代码只认 `Db::select()`，完全不感知底层用的是哪个扩展。
mysqli 不支持 `:name` 命名参数，驱动内部会转换成 `?` 位置参数 ——
转换时会跳过单引号字符串，避免把 `'08:00:00'` 里的冒号误当成占位符（有专门的测试覆盖）。

## 八、常见问题

**`Undefined constant PDO::MYSQL_ATTR_MULTI_STATEMENTS`**

这个常量由 pdo_mysql 扩展注册，扩展没加载时它就不存在。程序已改成
`defined()` 判断后再使用，缺了不影响功能 —— 真正拦截多语句的是 SQL 里的分号检查。

**`could not find driver` / 没有可用的 MySQL 扩展**

pdo_mysql 和 mysqli 都没加载。**启用 `extension=mysqli` 就够了**，不必启用 pdo_mysql。

**启用 `extension=pdo_mysql` 后 Web 服务器返回 500**

不用管它 —— 把这行改回注释状态，只要 `extension=mysqli` 是启用的，程序照常工作。

如果你确实想查 500 的原因，跑 `php tests/env.php`，它会列出扩展目录里实际有哪些
文件。Windows 上最常见的原因是 `C:\php…\ext\php_pdo_mysql.dll` 不存在，
或者 dll 的线程安全模式（TS/NTS）与 PHP 本体不匹配。

**页面能打开但查不出数据**

先跑 `php tests/checkdb.php`，第 7 节会显示数据库里实际的数据时间范围 ——
很可能是查询的日期超出了这个范围。

## 七、已知口径说明

- `status` 字段全库只有一个值 `2`，无法用来区分作废单，程序**不做**该字段过滤。
- `rvc_center` 只有一个营业点（`Comedor`），因此没有做营业点维度。
  将来若开分店，需要在 `Biz::buildSalesSql()` 里加 `rvc_center_id` 分组。
- `eat_type`：`0` = 堂食（78,535 单），`3` = 外带 `Llevar`（10,597 单），
  另有 `1`/`2` 共 7 单。页面提供筛选，默认统计全部。
- 全库有 1,672 张 `actual_amount = 0` 的账单（占 1.9%，多为开台未消费）。
  默认计入账单数，可勾选"排除 0 元账单"剔除。
