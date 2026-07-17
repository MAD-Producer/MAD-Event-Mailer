(function () {
    'use strict';
    function syncEventItem(input) {
        var item = input.closest('.mad-em-event-item');
        if (item) item.classList.toggle('is-selected', input.checked);
    }
    function ready() {
        document.querySelectorAll('.mad-em-register-wrap').forEach(function (wrap) {
            wrap.querySelectorAll('.mad-em-tab').forEach(function (button) {
                button.addEventListener('click', function () {
                    wrap.querySelectorAll('.mad-em-tab').forEach(function (tab) { tab.classList.remove('active'); });
                    wrap.querySelectorAll('.mad-em-panel').forEach(function (panel) { panel.classList.remove('active'); });
                    button.classList.add('active');
                    var panel = wrap.querySelector('.mad-em-panel[data-panel="' + button.dataset.target + '"]');
                    if (panel) panel.classList.add('active');
                });
            });
            wrap.querySelectorAll('.mad-em-event-item input[type="checkbox"]').forEach(function (input) {
                syncEventItem(input); input.addEventListener('change', function () { syncEventItem(input); });
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
}());
