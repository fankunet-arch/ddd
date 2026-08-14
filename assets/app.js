/**
 * 菜品搜索下拉框
 *
 * 菜品字典（约 500 条）在页面加载时已随页面下发，搜索全部在浏览器内完成，
 * 不再请求服务器，因此输入即时出结果，也不会给数据库增加任何压力。
 *
 * 交互：
 *   - 点击输入框：展开全部菜品供直接选择
 *   - 输入文字  ：按菜名或编号模糊匹配，实时过滤
 *   - ↑ ↓ 选择，Enter 确认，Esc 关闭
 *   - 选中后自动切换到「单菜品明细」模式；清空则回到「排行榜」模式
 */
(function () {
  var input = document.getElementById('itemSearch');
  if (!input) return;

  var hidden  = document.getElementById('itemId');
  var mode    = document.getElementById('mode');
  var list    = document.getElementById('itemList');
  var clearBt = document.getElementById('itemClear');
  var items   = window.MENU_ITEMS || [];
  var shown   = [];
  var sel     = -1;

  function match(kw) {
    if (!kw) return items.slice(0, 200);
    kw = kw.toLowerCase();
    var out = [];
    for (var i = 0; i < items.length && out.length < 200; i++) {
      var it = items[i];
      if (it.name.toLowerCase().indexOf(kw) >= 0 || String(it.id).indexOf(kw) === 0) {
        out.push(it);
      }
    }
    return out;
  }

  function render(kw) {
    shown = match(kw);
    sel = -1;
    list.innerHTML = '';
    if (!shown.length) {
      var li = document.createElement('li');
      li.className = 'none';
      li.textContent = '没有匹配的菜品';
      list.appendChild(li);
    } else {
      shown.forEach(function (it, idx) {
        var li = document.createElement('li');
        li.dataset.idx = idx;
        var left = document.createElement('span');
        var no = document.createElement('span');
        no.className = 'no';
        no.textContent = '#' + it.id;
        left.appendChild(no);
        left.appendChild(document.createTextNode(it.name));
        var pc = document.createElement('span');
        pc.className = 'pc';
        pc.textContent = it.pc;
        li.appendChild(left);
        li.appendChild(pc);
        list.appendChild(li);
      });
    }
    list.hidden = false;
  }

  function pick(idx) {
    var it = shown[idx];
    if (!it) return;
    input.value = it.name;
    hidden.value = it.id;
    mode.value = 'item';
    close();
  }

  function close() {
    list.hidden = true;
    sel = -1;
  }

  function highlight() {
    Array.prototype.forEach.call(list.children, function (li, i) {
      li.classList.toggle('sel', i === sel);
    });
    if (sel >= 0 && list.children[sel]) {
      list.children[sel].scrollIntoView({ block: 'nearest' });
    }
  }

  input.addEventListener('focus', function () { render(input.value.trim()); });
  input.addEventListener('input', function () {
    // 手改了文字就作废之前选中的 ID，避免文字与 ID 对不上
    hidden.value = '';
    mode.value = 'rank';
    render(input.value.trim());
  });

  input.addEventListener('keydown', function (e) {
    if (list.hidden && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
      render(input.value.trim());
      return;
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault(); sel = Math.min(sel + 1, shown.length - 1); highlight();
    } else if (e.key === 'ArrowUp') {
      e.preventDefault(); sel = Math.max(sel - 1, 0); highlight();
    } else if (e.key === 'Enter') {
      if (!list.hidden && sel >= 0) { e.preventDefault(); pick(sel); }
    } else if (e.key === 'Escape') {
      close();
    }
  });

  list.addEventListener('mousedown', function (e) {
    var li = e.target.closest('li[data-idx]');
    if (li) { e.preventDefault(); pick(parseInt(li.dataset.idx, 10)); }
  });

  clearBt.addEventListener('click', function () {
    input.value = '';
    hidden.value = '';
    mode.value = 'rank';
    input.focus();
    render('');
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest('.combo')) close();
  });
})();
