(function () {
    'use strict';

    var config = window.madevmaMailer || {};
    var templateVars = config.templateVars || [];
    var systemVars = ['name', 'name1', 'email', 'title', 'title1', 'unsubscribe_url', 'message', 'message1'];

    function byId(id) { return document.getElementById(id); }
    function syncEditors() { try { if (window.tinyMCE && window.tinyMCE.triggerSave) window.tinyMCE.triggerSave(); } catch (error) {} }
    function extractVars(text) {
        var out = [], match, expression = /{{\s*([A-Za-z0-9_\-]+)\s*}}/g;
        while ((match = expression.exec(text || '')) !== null) {
            if (systemVars.indexOf(match[1]) === -1 && out.indexOf(match[1]) === -1) out.push(match[1]);
        }
        return out;
    }
    function bodyText() {
        syncEditors();
        return Array.prototype.reduce.call(document.querySelectorAll('#bodybox textarea'), function (text, area) { return text + ' ' + (area.value || ''); }, '');
    }
    function existingVars() {
        return Array.prototype.map.call(document.querySelectorAll('#varbox [data-varrow]'), function (row) { return row.getAttribute('data-varrow'); }).filter(Boolean);
    }
    function addVarField(variable) {
        if (!variable || systemVars.indexOf(variable) !== -1 || existingVars().indexOf(variable) !== -1) return;
        var box = byId('varbox');
        if (!box) return;
        var empty = box.querySelector('.mad-em-empty-vars');
        if (empty) empty.remove();
        var row = document.createElement('p');
        row.className = 'mad-em-varrow';
        row.setAttribute('data-varrow', variable);
        var label = document.createElement('label');
        var strong = document.createElement('strong');
        strong.className = 'mad-em-var-label';
        strong.textContent = '{{' + variable + '}}';
        var textarea = document.createElement('textarea');
        textarea.className = 'large-text';
        textarea.rows = 3;
        textarea.name = 'var[' + variable + ']';
        textarea.placeholder = config.varPlaceholder || '这里填写全局默认值；如果 CSV 中有同名列，会优先使用每个收件人自己的值。';
        label.appendChild(strong); label.appendChild(document.createElement('br')); label.appendChild(textarea); row.appendChild(label); box.appendChild(row);
    }
    function refreshVars() { extractVars(bodyText()).forEach(addVarField); }
    function allVars() {
        var out = [];
        [templateVars, extractVars(bodyText()), existingVars()].forEach(function (source) {
            source.forEach(function (variable) { if (systemVars.indexOf(variable) === -1 && out.indexOf(variable) === -1) out.push(variable); });
        });
        return out;
    }

    function closeTemplateModal() {
        var modal = byId('madEmTemplateModal'), frame = byId('madEmTemplateFrame');
        if (modal) modal.style.display = 'none';
        if (frame) frame.src = 'about:blank';
    }
    function bindTemplatePage() {
        var modal = byId('madEmTemplateModal'), frame = byId('madEmTemplateFrame'), close = byId('madEmTemplateClose');
        document.querySelectorAll('.mad-em-template-preview').forEach(function (button) {
            button.addEventListener('click', function () { if (frame && modal) { frame.src = button.getAttribute('data-url'); modal.style.display = 'block'; } });
        });
        if (close) close.addEventListener('click', closeTemplateModal);
        if (modal) modal.addEventListener('click', function (event) { if (event.target === modal) closeTemplateModal(); });
    }
    function bindConfirmations() {
        document.querySelectorAll('[data-confirm], [data-confirm-delete]').forEach(function (element) {
            var target = element.matches('form') ? element : element;
            target.addEventListener(element.matches('form') ? 'submit' : 'click', function (event) {
                var message = element.getAttribute('data-confirm') || element.getAttribute('data-confirm-delete') || config.confirmDelete || '确定删除吗？';
                if (!window.confirm(message)) event.preventDefault();
            });
        });
    }
    function bindEventOrder() {
        var body = byId('mad-em-event-order'), dragging = null;
        if (!body) return;
        body.querySelectorAll('tr').forEach(function (row) {
            row.addEventListener('dragstart', function () { dragging = row; row.style.opacity = '.55'; });
            row.addEventListener('dragend', function () { row.style.opacity = ''; dragging = null; });
            row.addEventListener('dragover', function (event) {
                event.preventDefault();
                if (!dragging || dragging === row) return;
                var rect = row.getBoundingClientRect();
                body.insertBefore(dragging, (event.clientY - rect.top) > rect.height / 2 ? row.nextSibling : row);
            });
        });
    }

    window.madEmCloseModal = function () {
        var modal = byId('previewModal'), frame = byId('previewFrame');
        if (modal) modal.style.display = 'none';
        if (frame) { frame.removeAttribute('src'); frame.src = 'about:blank'; }
    };
    window.madEmPreviewSubmit = function () {
        var form = byId('mad-em-send'), modal = byId('previewModal'), panel = byId('testPanel'), frame = byId('previewFrame'), title = byId('previewTitle'), status = byId('previewStatus');
        if (!form) return true;
        if (modal) modal.style.display = 'block'; if (panel) panel.style.display = 'none'; if (frame) frame.style.display = 'block';
        if (title) title.textContent = config.previewTitle || '发送前预览'; if (status) status.textContent = config.previewStatus || '静态预览：变量会保留为 {{变量名}}，不会发送邮件。';
        form.target = 'madEmPreviewFrame'; window.setTimeout(function () { form.removeAttribute('target'); }, 1200); return true;
    };
    window.madEmOpenTest = function () {
        refreshVars();
        var modal = byId('previewModal'), panel = byId('testPanel'), frame = byId('previewFrame'), title = byId('previewTitle'), status = byId('previewStatus'), testVars = byId('testVars');
        if (modal) modal.style.display = 'block'; if (panel) panel.style.display = 'block'; if (frame) frame.style.display = 'none';
        if (title) title.textContent = config.testTitle || '发送测试邮件'; if (status) status.textContent = '';
        if (testVars) {
            testVars.textContent = '';
            var help = document.createElement('p'); help.className = 'description'; help.textContent = config.testHelp || '填写测试邮箱和变量示例值。只有点击下面的“发送测试邮件”才会真正发送。'; testVars.appendChild(help);
            allVars().forEach(function (variable) {
                var p = document.createElement('p'), label = document.createElement('label'), strong = document.createElement('strong'), textarea = document.createElement('textarea');
                strong.textContent = '{{' + variable + '}}'; textarea.className = 'large-text'; textarea.rows = 2; textarea.setAttribute('data-test-var', variable); textarea.placeholder = (config.testPlaceholder || '测试示例值；不填则保留 {{变量名}}').replace('{{变量名}}', '{{' + variable + '}}').replace('{{variable_name}}', '{{' + variable + '}}');
                label.appendChild(strong); label.appendChild(document.createElement('br')); label.appendChild(textarea); p.appendChild(label); testVars.appendChild(p);
            });
        }
        return false;
    };
    function bindSendPage() {
        var form = byId('mad-em-send');
        if (!form) return;
        document.addEventListener('input', function (event) { if (event.target && event.target.closest('#bodybox')) refreshVars(); });
        document.querySelectorAll('input[name="recipient_mode"]').forEach(function (mode) { mode.addEventListener('change', function () {
            var csv = document.querySelector('.recipient-csv'), eventRow = document.querySelector('.recipient-event'), checked = document.querySelector('input[name="recipient_mode"]:checked');
            var useCsv = checked && checked.value === 'csv'; if (csv) csv.style.display = useCsv ? 'table-row' : 'none'; if (eventRow) eventRow.style.display = useCsv ? 'none' : 'table-row';
        }); });
        var modal = byId('previewModal'); if (modal) modal.addEventListener('click', function (event) { if (event.target === modal) window.madEmCloseModal(); });
        var preview = byId('previewBtn'); if (preview) preview.addEventListener('click', window.madEmPreviewSubmit);
        var test = byId('testBtn'); if (test) test.addEventListener('click', window.madEmOpenTest);
        var close = byId('closePreview'); if (close) close.addEventListener('click', window.madEmCloseModal);
        var send = byId('sendTestNow'); if (send) send.addEventListener('click', function (event) {
            event.preventDefault(); syncEditors(); refreshVars();
            var email = byId('testEmail') ? byId('testEmail').value : ''; if (!email) { window.alert(config.emailRequired || '请填写测试邮箱。'); return; }
            var data = new FormData(form); data.delete('mad_em_action'); data.append('action', 'mad_em_test_send'); data.append('nonce', config.previewNonce || ''); data.append('test_email', email);
            document.querySelectorAll('[data-test-var]').forEach(function (field) { var key = field.getAttribute('data-test-var'); data.append('test_var[' + key + ']', field.value || '{{' + key + '}}'); });
            var status = byId('previewStatus'); if (status) status.textContent = config.sending || '正在发送测试邮件...';
            window.fetch(config.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (response) { return response.json(); }).then(function (result) {
                if (status) status.textContent = result && result.data && result.data.message ? result.data.message : (result && result.success ? (config.sent || '测试邮件已发送。') : (config.failed || '测试邮件发送失败。'));
            }).catch(function () { if (status) status.textContent = config.failedPermission || '测试邮件发送失败，请检查 SMTP 设置或后台权限。'; });
        });
        window.setTimeout(refreshVars, 600);
    }

    function ready() { bindTemplatePage(); bindConfirmations(); bindEventOrder(); bindSendPage(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
}());
