(function () {
    'use strict';
    function syncEventItem(input) {
        var item = input.closest('.madevma-mailer-event-item');
        if (item) item.classList.toggle('is-selected', input.checked);
    }
    function ready() {
        document.querySelectorAll('.madevma-mailer-register-wrap').forEach(function (wrap) {
            wrap.querySelectorAll('.madevma-mailer-tab').forEach(function (button) {
                button.addEventListener('click', function () {
                    wrap.querySelectorAll('.madevma-mailer-tab').forEach(function (tab) { tab.classList.remove('active'); });
                    wrap.querySelectorAll('.madevma-mailer-panel').forEach(function (panel) { panel.classList.remove('active'); });
                    button.classList.add('active');
                    var panel = wrap.querySelector('.madevma-mailer-panel[data-panel="' + button.dataset.target + '"]');
                    if (panel) panel.classList.add('active');
                });
            });
            wrap.querySelectorAll('.madevma-mailer-event-item input[type="checkbox"]').forEach(function (input) {
                syncEventItem(input); input.addEventListener('change', function () { syncEventItem(input); });
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
}());
