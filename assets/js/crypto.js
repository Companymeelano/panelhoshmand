/**
 * پنل هوشمند میلانو — منطق داشبورد سیگنال کریپتو
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
(function (window, document) {
    'use strict';

    const M = window.Meelano;
    if (!M) { return; }

    const el = (id) => M.$('#' + id);

    function setBusy(button, busy, label) {
        if (!button) { return; }
        if (busy) {
            button.dataset.label = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-circle-notch btn__spin"></i> ' + (label || 'در حال پردازش…');
        } else {
            button.disabled = false;
            button.innerHTML = button.dataset.label || button.innerHTML;
        }
    }

    const price = (v) => {
        const n = Number(v) || 0;
        if (n === 0) { return '—'; }
        if (n >= 1000) { return M.money(n); }
        if (n >= 1) { return n.toFixed(3); }
        return n.toFixed(6);
    };

    function filterRows(filters) {
        return (filters || []).map((f) => {
            const icon = f.pass ? 'fa-circle-check' : 'fa-circle-xmark';
            const cls = f.pass ? 'ok' : 'bad';
            return '<li class="' + cls + '"><i class="fa-solid ' + icon + '"></i> ' +
                M.escapeHtml(f.label) + ' <span class="dim">— ' + M.escapeHtml(f.detail) + '</span></li>';
        }).join('');
    }

    function aiRows(ai) {
        if (!ai || !ai.ok) { return '<li class="dim">اجماع AI در دسترس نبود (کلیدها را در تنظیمات فعال کنید).</li>'; }
        let out = (ai.notes || []).map((n) => '<li class="ok"><i class="fa-solid fa-robot"></i> ' + M.escapeHtml(n) + '</li>').join('');
        out += '<li class="' + (ai.agreement ? 'ok' : 'bad') + '"><i class="fa-solid ' + (ai.agreement ? 'fa-handshake' : 'fa-triangle-exclamation') + '"></i> ' +
            (ai.agreement ? 'اجماع AI با سمت تکنیکال هم‌راستاست' : 'هشدار: اجماع AI با تکنیکال هم‌راستا نیست') +
            ' · امتیاز AI: ' + M.toFa(ai.ai_score) + '</li>';
        return out;
    }

    function signalCard(s) {
        const isBuy = s.side === 'BUY';
        const sideCls = isBuy ? 'badge--ok' : 'badge--bad';
        const r = s.risk || {};
        let html = '<div class="card-3d section" style="border-color:' + (isBuy ? 'rgba(52,211,153,.4)' : 'rgba(251,113,133,.4)') + '">';

        html += '<div class="section__head" style="border:none;margin:0;padding:0">' +
            '<h3 class="section__title" style="font-size:16px"><span class="mono" style="color:var(--gold-1)">' + M.escapeHtml(s.symbol) + '</span>' +
            ' <span class="badge ' + sideCls + '">' + (isBuy ? 'خرید BUY' : 'فروش SELL') + '</span>' +
            ' <span class="badge badge--gold">اعتماد ' + M.toFa(Math.round(s.combined_score)) + '</span></h3>' +
            '<span class="mono dim" style="font-size:12px">قیمت: ' + price(s.price) + ' · ۲۴س: ' + M.toFa(Math.round(s.change24 * 10) / 10) + '٪</span></div>';

        // اعداد عملیاتی
        html += '<div class="grid grid--6" style="margin-top:14px">';
        html += kv('ورود', price(r.entry));
        html += kv('استاپ', price(r.stop_loss), 'var(--rose)');
        html += kv('TP1', price(r.take_profit_1), 'var(--emerald)');
        html += kv('TP2', price(r.take_profit_2), 'var(--emerald)');
        html += kv('TP3', price(r.take_profit_3), 'var(--emerald)');
        html += kv('R:R', M.toFa(r.risk_reward_2));
        html += '</div>';
        html += '<div class="grid grid--4" style="margin-top:8px">';
        html += kv('سایز پوزیشن', M.toFa(r.position_percent) + '٪', 'var(--indigo-1)');
        html += kv('امتیاز تکنیکال', M.toFa(Math.round(s.tech_score)));
        html += kv('امتیاز AI', M.toFa(Math.round((s.ai && s.ai.ai_score) || 0)));
        html += kv('فیلترها', M.toFa(s.passed) + '/' + M.toFa(s.total));
        html += '</div>';

        // جزئیات فیلترها + AI
        html += '<div class="grid grid--2" style="margin-top:14px">';
        html += '<div><p class="field-label">فیلترهای سخت‌گیرانه</p><ul class="modal__log" style="max-height:220px">' + filterRows(s.filters) + '</ul></div>';
        html += '<div><p class="field-label">اجماع هوش مصنوعی</p><ul class="modal__log" style="max-height:220px">' + aiRows(s.ai) + '</ul></div>';
        html += '</div>';

        html += '</div>';
        return html;
    }

    function kv(label, value, color) {
        return '<div class="stat"><div class="stat__label">' + M.escapeHtml(label) + '</div>' +
            '<div class="stat__value" style="font-size:15px;' + (color ? 'color:' + color : '') + '">' + M.escapeHtml(String(value)) + '</div></div>';
    }

    function renderSignals(list) {
        const box = el('live_signals');
        const none = el('no_signal');
        if (!list || !list.length) {
            box.innerHTML = '';
            if (none) { none.style.display = ''; }
            return;
        }
        if (none) { none.style.display = 'none'; }
        box.innerHTML = list.map(signalCard).join('');
    }

    async function scanMarket() {
        const btn = el('btn_scan');
        setBusy(btn, true, 'در حال رصد بازار…');
        M.modal.open('رصد کل بازار کریپتو', 'دریافت دادهٔ زندهٔ بازار و اجرای فیلترهای سخت‌گیرانه…');
        const stages = [
            { at: 12, text: 'دریافت فهرست ارزهای پرحجم…' },
            { at: 34, text: 'محاسبهٔ اندیکاتورها (RSI, MACD, بولینگر, ATR)…' },
            { at: 58, text: 'اجرای زنجیرهٔ فیلترهای سخت‌گیرانه…' },
            { at: 80, text: 'اعتبارسنجی با اجماع هوش مصنوعی…' },
            { at: 94, text: 'صدور سیگنال‌های نهایی…' },
        ];
        let i = 0;
        const tick = setInterval(() => {
            if (i < stages.length) { const s = stages[i++]; M.modal.set(s.at, s.text); }
        }, 1200);
        try {
            const data = await M.api('api/scan.php', {});
            clearInterval(tick);
            M.modal.set(100, 'اسکن ' + M.toFa(data.scanned) + ' ارز کامل شد');
            el('scan_meta').textContent = M.toFa(data.scanned) + ' ارز اسکن شد · ' + M.toFa(data.signal_count) + ' سیگنال · ' + M.toFa(Math.round(data.duration_ms)) + 'ms';
            renderSignals(data.signals || []);
            if (!data.signals || !data.signals.length) {
                M.toast('هیچ ارزی از فیلترهای سخت‌گیرانه عبور نکرد — این یعنی فیلترها درست کار می‌کنند.', 'info', 6000);
            } else {
                M.toast(M.toFa(data.signal_count) + ' سیگنال صادر شد', 'ok', 5500);
            }
            setTimeout(() => M.modal.close(), 600);
            M.refreshSystemStatus();
        } catch (err) {
            clearInterval(tick);
            M.modal.close(0);
            M.toast(err.message, 'bad', 7000);
        } finally {
            setBusy(btn, false);
        }
    }

    async function analyzeSingle() {
        const sym = (el('single_symbol').value || '').trim().toUpperCase();
        if (!sym) {
            M.toast('نماد را وارد کنید (مثل BTCUSDT).', 'warn');
            return;
        }
        const btn = el('btn_single');
        setBusy(btn, true, 'در حال تحلیل…');
        M.modal.open('تحلیل ' + sym, 'اجرای فیلترها و اجماع AI…');
        try {
            const data = await M.api('api/scan.php', { symbol: sym });
            const s = data.signal;
            if (s && s.is_signal) {
                renderSignals([s]);
                el('no_signal').style.display = 'none';
                M.toast(sym + ' سیگنال ' + s.side + ' با اعتماد ' + M.toFa(Math.round(s.combined_score)) + ' دارد', 'ok', 6000);
            } else {
                // نمایش ارزیابی حتی وقتی سیگنال نیست
                el('live_signals').innerHTML = rejectCard(s || { symbol: sym });
                el('no_signal').style.display = 'none';
                M.toast(sym + ' از فیلترهای سخت‌گیرانه عبور نکرد (امتیاز ' + M.toFa(Math.round((s && s.tech_score) || 0)) + ')', 'warn', 6000);
            }
            M.modal.set(100, 'تحلیل کامل شد');
            setTimeout(() => M.modal.close(), 500);
        } catch (err) {
            M.modal.close(0);
            M.toast(err.message, 'bad', 7000);
        } finally {
            setBusy(btn, false);
        }
    }

    function rejectCard(s) {
        return '<div class="card-3d section" style="border-color:rgba(251,191,36,.3)">' +
            '<div class="section__head" style="border:none;margin:0;padding:0"><h3 class="section__title">' +
            '<span class="mono" style="color:var(--gold-1)">' + M.escapeHtml(s.symbol) + '</span> ' +
            '<span class="badge badge--gold">سیگنال صادر نشد</span></h3>' +
            '<span class="mono dim">امتیاز تکنیکال: ' + M.toFa(Math.round(s.tech_score || 0)) + ' · فیلترها: ' + M.toFa(s.passed || 0) + '/' + M.toFa(s.total || 0) + '</span></div>' +
            '<p class="help" style="margin-top:10px">' + M.escapeHtml(s.reason || 'شرایط ورود برقرار نیست.') + '</p>' +
            '<ul class="modal__log" style="max-height:200px;margin-top:10px">' + filterRows(s.filters) + '</ul>' +
            '</div>';
    }

    document.addEventListener('DOMContentLoaded', () => {
        el('btn_scan').addEventListener('click', scanMarket);
        el('btn_single').addEventListener('click', analyzeSingle);
        el('single_symbol').addEventListener('keydown', (e) => { if (e.key === 'Enter') { analyzeSingle(); } });
        M.refreshSystemStatus();
    });
}(window, document));
