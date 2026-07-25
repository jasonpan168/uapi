(function () {
  if (!window.uapiPayment) return;

  var activePollers = {};
  var activePopupWatchers = {};

  function toast(msg, type) {
    var el = document.getElementById('uapi-pay-toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'uapi-pay-toast';
      el.className = 'uapi-pay-toast';
      document.body.appendChild(el);
    }
    el.textContent = msg;
    el.setAttribute('data-type', type || 'info');
    el.classList.add('show');
    clearTimeout(el._timer);
    el._timer = setTimeout(function () {
      el.classList.remove('show');
    }, 2600);
  }

  function copyText(text) {
    if (!text) return Promise.reject(new Error('empty'));
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    var ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    return Promise.resolve();
  }

  function ensureModal() {
    var root = document.getElementById('uapi-pay-modal');
    if (root) return root;

    root = document.createElement('div');
    root.id = 'uapi-pay-modal';
    root.className = 'uapi-pay-modal';
    root.innerHTML = [
      '<div class="uapi-pay-modal-mask"></div>',
      '<div class="uapi-pay-modal-panel">',
      '<div class="uapi-pay-modal-head">',
      '<div class="uapi-pay-modal-brand"><span class="dot"></span>UAPI PAY</div>',
      '<button type="button" class="uapi-pay-close" id="uapi-pay-close">×</button>',
      '</div>',
      '<div class="uapi-pay-modal-body">',
      '<div class="uapi-pay-amount" id="uapi-pay-amount">--</div>',
      '<div class="uapi-pay-meta" id="uapi-pay-meta">--</div>',
      '<div class="uapi-pay-order" id="uapi-pay-order">订单：--</div>',
      '<div class="uapi-pay-stage" id="uapi-pay-stage">准备中...</div>',
      '<div class="uapi-pay-loader"></div>',
      '<div class="uapi-pay-actions">',
      '<button type="button" class="uapi-pay-btn-outline" id="uapi-pay-open">打开支付页</button>',
      '<button type="button" class="uapi-pay-btn-outline" id="uapi-pay-copy">复制链接</button>',
      '</div>',
      '</div>',
      '</div>'
    ].join('');

    document.body.appendChild(root);

    function close() {
      root.classList.remove('show');
    }

    root.querySelector('.uapi-pay-modal-mask').addEventListener('click', close);
    root.querySelector('#uapi-pay-close').addEventListener('click', close);

    return root;
  }

  function showModal(data) {
    var modal = ensureModal();
    modal.classList.add('show');

    modal.querySelector('#uapi-pay-amount').textContent = (data.amount || '--');
    modal.querySelector('#uapi-pay-meta').textContent = (data.meta || '--');
    modal.querySelector('#uapi-pay-order').textContent = '订单：' + (data.orderNo || '--');
    modal.querySelector('#uapi-pay-stage').textContent = data.stage || '';

    var openBtn = modal.querySelector('#uapi-pay-open');
    var copyBtn = modal.querySelector('#uapi-pay-copy');

    openBtn.onclick = function () {
      if (data.paymentUrl) window.open(data.paymentUrl, '_blank');
    };
    copyBtn.onclick = function () {
      copyText(data.paymentUrl || '').then(function () {
        toast('支付链接已复制', 'success');
      });
    };
  }

  function setSuccessActions() {
    var modal = document.getElementById('uapi-pay-modal');
    if (!modal) return;
    var actions = modal.querySelector('.uapi-pay-actions');
    if (!actions) return;
    actions.innerHTML = '' +
      '<button type=\"button\" class=\"uapi-pay-btn-outline\" id=\"uapi-pay-back\">返回页面</button>' +
      '<button type=\"button\" class=\"uapi-pay-btn-outline\" id=\"uapi-pay-close-only\">关闭页面</button>';
    var backBtn = modal.querySelector('#uapi-pay-back');
    var closeBtn = modal.querySelector('#uapi-pay-close-only');
    if (backBtn) {
      backBtn.onclick = function () { backToSourcePage(); };
    }
    if (closeBtn) {
      closeBtn.onclick = function () {
        modal.classList.remove('show');
        try { window.close(); } catch (e) {}
      };
    }
  }

  function backToSourcePage() {
    try {
      if (window.opener && !window.opener.closed) {
        try { window.opener.focus(); } catch (e) {}
        window.close();
        return;
      }
    } catch (e) {}
    if (document.referrer) {
      window.location.href = document.referrer;
      return;
    }
    if (window.history.length > 1) {
      window.history.back();
      return;
    }
    window.location.href = '/';
  }

  function setStage(text) {
    var modal = document.getElementById('uapi-pay-modal');
    if (!modal) return;
    var el = modal.querySelector('#uapi-pay-stage');
    if (el) el.textContent = text || '';
  }

  function safeJsonParse(text) {
    try {
      return JSON.parse(String(text || ''));
    } catch (e) {
      return null;
    }
  }

  function postRest(action, data) {
    var base = (window.uapiPayment && window.uapiPayment.restBase) ? String(window.uapiPayment.restBase) : '';
    if (!base) {
      return Promise.resolve({ success: false, data: { message: 'REST 地址未配置' } });
    }
    var route = '';
    if (action === 'uapi_payment_create_order') route = 'create-order';
    if (action === 'uapi_payment_check_order') route = 'check-order';
    if (action === 'uapi_payment_cancel_order') route = 'cancel-order';
    if (!route) {
      return Promise.resolve({ success: false, data: { message: '未知 REST 动作' } });
    }
    return fetch(base.replace(/\/+$/, '/') + route, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(data || {})
    }).then(function (r) {
      return r.text().then(function (txt) {
        var parsed = safeJsonParse(txt);
        if (parsed && typeof parsed === 'object' && Object.prototype.hasOwnProperty.call(parsed, 'success')) {
          return parsed;
        }
        var raw = String(txt || '').replace(/\s+/g, ' ').trim();
        return { success: false, data: { message: raw ? ('接口返回非 JSON：' + raw.slice(0, 120)) : '接口返回非 JSON' } };
      });
    }).catch(function (e) {
      return { success: false, data: { message: (e && e.message) ? e.message : 'REST 请求失败' } };
    });
  }

  function postForm(action, data) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('nonce', window.uapiPayment.nonce);
    Object.keys(data || {}).forEach(function (k) {
      body.set(k, data[k]);
    });

    return fetch(window.uapiPayment.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.text().then(function (txt) {
        var parsed = safeJsonParse(txt);
        if (parsed && typeof parsed === 'object') {
          return parsed;
        }
        return postRest(action, data);
      });
    }).catch(function () {
      return postRest(action, data);
    });
  }

  function stopPolling(id) {
    if (activePollers[id]) {
      clearInterval(activePollers[id]);
      delete activePollers[id];
    }
    if (activePopupWatchers[id]) {
      clearInterval(activePopupWatchers[id]);
      delete activePopupWatchers[id];
    }
  }

  function startPolling(localOrderId, clientKey, onPaid) {
    if (!localOrderId) return;
    stopPolling(localOrderId);

    var started = Date.now();
    activePollers[localOrderId] = setInterval(function () {
      postForm('uapi_payment_check_order', {
        local_order_id: String(localOrderId),
        client_key: String(clientKey || '')
      })
        .then(function (res) {
          if (!res || !res.success || !res.data) {
            var m = (res && res.data && (res.data.message || res.data.error)) ? (res.data.message || res.data.error) : '查单失败';
            setStage('查单失败：' + m);
            return;
          }
          var st = String(res.data.status || 'pending');

          if (st === 'paid') {
            stopPolling(localOrderId);
            setStage(window.uapiPayment.i18n.paid || '支付成功');
            if (typeof onPaid === 'function') onPaid();
            return;
          }
          if (st === 'expired') {
            stopPolling(localOrderId);
            setStage(window.uapiPayment.i18n.expired || '订单已过期');
            toast(window.uapiPayment.i18n.expired || '订单已过期', 'warn');
            return;
          }

          if (Date.now() - started > 10 * 60 * 1000) {
            stopPolling(localOrderId);
            setStage('等待超时，请稍后手动查询');
            toast('等待超时，请稍后手动查询', 'warn');
          }
        })
        .catch(function () {
          setStage('查询中...');
        });
    }, 3000);
  }

  function handlePayButton(btn) {
    if (!btn || btn.disabled) return;

    var amount = btn.getAttribute('data-amount') || '0';
    var chain = btn.getAttribute('data-chain') || 'bsc';
    var currency = btn.getAttribute('data-currency') || 'USDT';
    var objectType = btn.getAttribute('data-object-type') || 'content';
    var objectKey = btn.getAttribute('data-object-key') || '';
    var successText = btn.getAttribute('data-success-text') || '支付成功';
    var downloadId = btn.getAttribute('data-download-id') || '';

    btn.disabled = true;
    showModal({
      amount: amount + ' ' + currency,
      meta: String(chain).toUpperCase() + ' / ' + String(currency).toUpperCase(),
      stage: window.uapiPayment.i18n.creating || '正在创建支付订单...',
      orderNo: '--',
      paymentUrl: ''
    });

    postForm('uapi_payment_create_order', {
      amount: amount,
      chain: chain,
      currency: currency,
      object_type: objectType,
      object_key: objectKey
    }).then(function (res) {
      btn.disabled = false;

      if (!res || !res.success || !res.data) {
        var err = (res && res.data && res.data.message) ? res.data.message : '创建订单失败';
        if (/currency\s+'?usdc'?[\s\S]*not enabled/i.test(String(err || ''))) {
          err = window.uapiPayment.i18n.usdcDisabled || '当前商户未开通 USDC 收款，请切换 USDT 或在商户后台开启 USDC';
        }
        setStage(err);
        toast(err, 'error');
        return;
      }

      var paymentUrl = String(res.data.payment_url || '');
      var orderNo = String(res.data.order_no || '--');
      var localOrderId = Number(res.data.local_order_id || 0);
      var clientKey = String(res.data.client_key || '');

      showModal({
        amount: amount + ' ' + currency,
        meta: String(chain).toUpperCase() + ' / ' + String(currency).toUpperCase(),
        stage: window.uapiPayment.i18n.waiting || '请在新窗口完成支付，系统自动确认中',
        orderNo: orderNo,
        paymentUrl: paymentUrl
      });

      var w = window.open(paymentUrl, '_blank');
      if (!w) {
        toast(window.uapiPayment.i18n.popupBlocked || '浏览器阻止了弹窗', 'warn');
      } else {
        activePopupWatchers[localOrderId] = setInterval(function () {
          if (!w || w.closed) {
            stopPolling(localOrderId);
            postForm('uapi_payment_cancel_order', {
              local_order_id: String(localOrderId),
              client_key: String(clientKey || '')
            }).then(function () {
              setStage(window.uapiPayment.i18n.popupClosed || '支付窗口已断开，订单已取消');
              toast(window.uapiPayment.i18n.popupClosed || '支付窗口已断开，订单已取消', 'warn');
            }).catch(function () {
              setStage(window.uapiPayment.i18n.popupClosed || '支付窗口已断开，订单已取消');
              toast(window.uapiPayment.i18n.popupClosed || '支付窗口已断开，订单已取消', 'warn');
            });
          }
        }, 900);
      }

      startPolling(localOrderId, clientKey, function () {
        toast(successText, 'success');
        setSuccessActions();
        setStage('支付成功，请返回页面或关闭当前页面');

        if (downloadId) {
          var d = document.getElementById(downloadId);
          if (d) d.classList.remove('uapi-hidden');
        }

      });
    }).catch(function (e) {
      btn.disabled = false;
      var errMsg = (e && e.message) ? e.message : '请求失败';
      setStage(errMsg);
      toast(errMsg, 'error');
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.uapi-pay-btn');
    if (!btn) return;
    e.preventDefault();
    handlePayButton(btn);
  });
})();
