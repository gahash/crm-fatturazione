(function () {
    function closeAll() {
        document.querySelectorAll('.user-menu.open').forEach(function (el) {
            el.classList.remove('open');
            var btn = el.querySelector('.user-menu__btn');
            var dd = el.querySelector('.user-menu__dropdown');
            if (btn) btn.setAttribute('aria-expanded', 'false');
            if (dd) dd.style.display = 'none';
        });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-user-menu="toggle"]');
        if (btn) {
            e.stopPropagation();
            var menu = btn.closest('.user-menu');
            if (menu) {
                var dd = menu.querySelector('.user-menu__dropdown');
                if (menu.classList.contains('open')) {
                    menu.classList.remove('open');
                    btn.setAttribute('aria-expanded', 'false');
                    if (dd) dd.style.display = 'none';
                } else {
                    closeAll();
                    menu.classList.add('open');
                    btn.setAttribute('aria-expanded', 'true');
                    if (dd) dd.style.display = 'block';
                }
            }
            return;
        }

        if (!e.target.closest('.user-menu')) {
            closeAll();
        }
    });
})();
