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
        var empty = box.querySelector('.madevma-mailer-empty-vars');
        if (empty) empty.remove();
        var row = document.createElement('p');
        row.className = 'madevma-mailer-varrow';
        row.setAttribute('data-varrow', variable);
        var label = document.createElement('label');
        var strong = document.createElement('strong');
        strong.className = 'madevma-mailer-var-label';
        strong.textContent = '{{' + variable + '}}';
        var textarea = document.createElement('textarea');
        textarea.className = 'large-text';
        textarea.rows = 3;
        textarea.name = 'var[' + variable + ']';
        textarea.placeholder = config.varPlaceholder || 'Enter a global default value. A matching CSV column takes priority for each recipient.';
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
        var modal = byId('madevmaTemplateModal'), frame = byId('madevmaTemplateFrame');
        if (modal) modal.style.display = 'none';
        if (frame) frame.src = 'about:blank';
    }
    function bindTemplatePage() {
        var modal = byId('madevmaTemplateModal'), frame = byId('madevmaTemplateFrame'), close = byId('madevmaTemplateClose');
        document.querySelectorAll('.madevma-mailer-template-preview').forEach(function (button) {
            button.addEventListener('click', function () { if (frame && modal) { frame.src = button.getAttribute('data-url'); modal.style.display = 'block'; } });
        });
        if (close) close.addEventListener('click', closeTemplateModal);
        if (modal) modal.addEventListener('click', function (event) { if (event.target === modal) closeTemplateModal(); });
    }
    function bindConfirmations() {
        document.querySelectorAll('[data-confirm], [data-confirm-delete]').forEach(function (element) {
            var target = element.matches('form') ? element : element;
            target.addEventListener(element.matches('form') ? 'submit' : 'click', function (event) {
                var message = element.getAttribute('data-confirm') || element.getAttribute('data-confirm-delete') || config.confirmDelete || 'Are you sure you want to delete this?';
                if (!window.confirm(message)) event.preventDefault();
            });
        });
    }
    function bindEventOrder() {
        var body = byId('madevma-mailer-event-order'), dragging = null;
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

    window.madevmaCloseModal = function () {
        var modal = byId('previewModal'), frame = byId('previewFrame');
        if (modal) modal.style.display = 'none';
        if (frame) { frame.removeAttribute('src'); frame.src = 'about:blank'; }
    };
    window.madevmaPreviewSubmit = function () {
        var form = byId('madevma-mailer-send'), modal = byId('previewModal'), panel = byId('testPanel'), frame = byId('previewFrame'), title = byId('previewTitle'), status = byId('previewStatus');
        if (!form) return true;
        if (modal) modal.style.display = 'block'; if (panel) panel.style.display = 'none'; if (frame) frame.style.display = 'block';
        if (title) title.textContent = config.previewTitle || 'Preview'; if (status) status.textContent = config.previewStatus || 'Static preview: variables remain as {{variable_name}} and no email will be sent.';
        form.target = 'madevmaPreviewFrame'; window.setTimeout(function () { form.removeAttribute('target'); }, 1200); return true;
    };
    window.madevmaOpenTest = function () {
        refreshVars();
        var modal = byId('previewModal'), panel = byId('testPanel'), frame = byId('previewFrame'), title = byId('previewTitle'), status = byId('previewStatus'), testVars = byId('testVars');
        if (modal) modal.style.display = 'block'; if (panel) panel.style.display = 'block'; if (frame) frame.style.display = 'none';
        if (title) title.textContent = config.testTitle || 'Send Test Email'; if (status) status.textContent = '';
        if (testVars) {
            testVars.textContent = '';
            var help = document.createElement('p'); help.className = 'description'; help.textContent = config.testHelp || 'Enter a test email address and sample variable values. Email is sent only when you click Send Test Email below.'; testVars.appendChild(help);
            allVars().forEach(function (variable) {
                var p = document.createElement('p'), label = document.createElement('label'), strong = document.createElement('strong'), textarea = document.createElement('textarea');
                strong.textContent = '{{' + variable + '}}'; textarea.className = 'large-text'; textarea.rows = 2; textarea.setAttribute('data-test-var', variable); textarea.placeholder = (config.testPlaceholder || 'Test sample value; leave blank to keep {{variable_name}}').replace('{{variable_name}}', '{{' + variable + '}}');
                label.appendChild(strong); label.appendChild(document.createElement('br')); label.appendChild(textarea); p.appendChild(label); testVars.appendChild(p);
            });
        }
        return false;
    };
    function bindSendPage() {
        var form = byId('madevma-mailer-send');
        if (!form) return;
        document.addEventListener('input', function (event) { if (event.target && event.target.closest('#bodybox')) refreshVars(); });
        document.querySelectorAll('input[name="recipient_mode"]').forEach(function (mode) { mode.addEventListener('change', function () {
            var csv = document.querySelector('.recipient-csv'), eventRow = document.querySelector('.recipient-event'), checked = document.querySelector('input[name="recipient_mode"]:checked');
            var useCsv = checked && checked.value === 'csv'; if (csv) csv.style.display = useCsv ? 'table-row' : 'none'; if (eventRow) eventRow.style.display = useCsv ? 'none' : 'table-row';
        }); });
        var modal = byId('previewModal'); if (modal) modal.addEventListener('click', function (event) { if (event.target === modal) window.madevmaCloseModal(); });
        var preview = byId('previewBtn'); if (preview) preview.addEventListener('click', window.madevmaPreviewSubmit);
        var test = byId('testBtn'); if (test) test.addEventListener('click', window.madevmaOpenTest);
        var close = byId('closePreview'); if (close) close.addEventListener('click', window.madevmaCloseModal);
        var send = byId('sendTestNow'); if (send) send.addEventListener('click', function (event) {
            event.preventDefault(); syncEditors(); refreshVars();
            var email = byId('testEmail') ? byId('testEmail').value : ''; if (!email) { window.alert(config.emailRequired || 'Please enter a test email address.'); return; }
            var data = new FormData(form); data.delete('madevma_action'); data.append('action', 'madevma_test_send'); data.append('nonce', config.previewNonce || ''); data.append('test_email', email);
            document.querySelectorAll('[data-test-var]').forEach(function (field) { var key = field.getAttribute('data-test-var'); data.append('test_var[' + key + ']', field.value || '{{' + key + '}}'); });
            var status = byId('previewStatus'); if (status) status.textContent = config.sending || 'Sending test email...';
            window.fetch(config.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (response) { return response.json(); }).then(function (result) {
                if (status) status.textContent = result && result.data && result.data.message ? result.data.message : (result && result.success ? (config.sent || 'Test email sent.') : (config.failed || 'Test email failed.'));
            }).catch(function () { if (status) status.textContent = config.failedPermission || 'Test email failed. Please check SMTP settings or admin permissions.'; });
        });
        window.setTimeout(refreshVars, 600);
    }

    function ready() { bindTemplatePage(); bindConfirmations(); bindEventOrder(); bindSendPage(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
}());
