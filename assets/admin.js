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
    function bindAiTemplatePage() {
        var mode = byId('madevma-ai-mode'), row = byId('madevma-ai-base-row'), base = byId('madevma-ai-base-template');
        if (!mode || !row) return;
        function sync() {
            var useGeneral = mode.value === 'general';
            row.style.display = useGeneral ? '' : 'none';
            if (base) base.required = useGeneral;
        }
        mode.addEventListener('change', sync);
        sync();
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
    function bindRecipientGrid() {
        var form = byId('madevma-recipient-grid-form');
        if (!form) return;
        var body = form.querySelector('tbody'), add = byId('madevma-add-recipient-row');
        if (!body) return;
        var recipientTemplateFields = config.recipientTemplateFields || {};

        function nextIndex() {
            var max = -1;
            body.querySelectorAll('[name]').forEach(function (field) {
                var match = field.name.match(/recipient_grid\[(\d+)\]/);
                if (match) max = Math.max(max, parseInt(match[1], 10));
            });
            return max + 1;
        }

        function rowIndex(row) {
            var field = row.querySelector('[name*="recipient_grid["]');
            var match = field && field.name.match(/recipient_grid\[(\d+)\]/);
            return match ? parseInt(match[1], 10) : 0;
        }

        function templateSelect(row) {
            return row.querySelector('[data-recipient-template]');
        }

        function collectVariableValues(row) {
            var values = row._recipientVariableCache || {};
            row.querySelectorAll('[data-recipient-variable]').forEach(function (field) {
                values[field.getAttribute('data-recipient-variable')] = field.value || '';
            });
            return values;
        }

        function addVariableMessage(box, message) {
            var text = document.createElement('p');
            text.className = 'description madevma-recipient-variable-empty';
            text.textContent = message;
            box.appendChild(text);
        }

        function renderVariableFields(row, values) {
            var box = row.querySelector('[data-recipient-variable-fields]'), select = templateSelect(row);
            if (!box) return;
            var templateId = select && select.value ? String(select.value) : '';
            var fieldSet = recipientTemplateFields[templateId] || {};
            var variables = Array.isArray(fieldSet.editable) ? fieldSet.editable : [];
            var automatic = Array.isArray(fieldSet.automatic) ? fieldSet.automatic : [];
            var rowNumber = rowIndex(row);
            row._recipientVariableCache = values || {};
            box.textContent = '';
            if (!templateId) {
                addVariableMessage(box, config.recipientSelectTemplate || 'Select an email template to load its variables.');
                return;
            }
            if (automatic.length) {
                var automaticMessage = document.createElement('p');
                automaticMessage.className = 'description madevma-recipient-automatic-vars';
                automaticMessage.appendChild(document.createTextNode((config.recipientAutomaticLabel || 'Automatically filled by the template system:') + ' '));
                automatic.forEach(function (variable) {
                    var code = document.createElement('code');
                    code.textContent = '{{' + variable + '}}';
                    automaticMessage.appendChild(code);
                    automaticMessage.appendChild(document.createTextNode(' '));
                });
                box.appendChild(automaticMessage);
            }
            if (!variables.length) {
                addVariableMessage(box, config.recipientNoVars || 'This template has no editable recipient variables. System values are filled automatically.');
                return;
            }
            variables.forEach(function (variable) {
                var label = document.createElement('label');
                label.className = 'madevma-recipient-variable-field';
                var caption = document.createElement('span');
                var code = document.createElement('code');
                var textarea = document.createElement('textarea');
                code.textContent = '{{' + variable + '}}';
                textarea.rows = 2;
                textarea.name = 'recipient_grid[' + rowNumber + '][variables][' + variable + ']';
                textarea.setAttribute('data-recipient-variable', variable);
                textarea.value = values && Object.prototype.hasOwnProperty.call(values, variable) ? values[variable] : '';
                caption.appendChild(code);
                label.appendChild(caption);
                label.appendChild(textarea);
                box.appendChild(label);
            });
        }

        function clearRow(row, index) {
            row.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace(/recipient_grid\[\d+\]/, 'recipient_grid[' + index + ']');
                var suffix = field.name.replace(/^recipient_grid\[\d+\]/, '');
                if (suffix === '[id]') field.value = '0';
                else if (suffix === '[status]') field.value = 'subscribed';
                else if (suffix === '[template_id]') field.value = '';
                else if (suffix === '[email]' || suffix === '[name]' || suffix === '[events]' || suffix.indexOf('[variables]') === 0) field.value = '';
            });
            renderVariableFields(row, {});
        }

        body.querySelectorAll('tr').forEach(function (row) { renderVariableFields(row, collectVariableValues(row)); });
        body.addEventListener('change', function (event) {
            if (!event.target.matches('[data-recipient-template]')) return;
            var row = event.target.closest('tr');
            if (row) renderVariableFields(row, collectVariableValues(row));
        });

        if (add) add.addEventListener('click', function () {
            var source = body.querySelector('tr');
            if (!source) return;
            var row = source.cloneNode(true);
            clearRow(row, nextIndex());
            body.appendChild(row);
        });

        body.addEventListener('click', function (event) {
            var button = event.target.closest('.madevma-remove-recipient-row');
            if (!button) return;
            var row = button.closest('tr');
            if (!row) return;
            if (body.querySelectorAll('tr').length > 1) row.remove();
            else clearRow(row, nextIndex());
        });

        form.addEventListener('submit', function (event) {
            var missingTemplate = false;
            body.querySelectorAll('tr').forEach(function (row) {
                var email = row.querySelector('input[type="email"]'), name = row.querySelector('input[name$="[name]"]'), select = templateSelect(row);
                if (((email && email.value) || '').trim() === '' && ((name && name.value) || '').trim() === '') return;
                if (!select || !select.value) missingTemplate = true;
            });
            if (missingTemplate) {
                event.preventDefault();
                window.alert(config.recipientTemplateRequired || 'Each recipient row must have an email template. Select a template before saving.');
            }
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

    function ready() { bindTemplatePage(); bindAiTemplatePage(); bindConfirmations(); bindEventOrder(); bindRecipientGrid(); bindSendPage(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
}());
