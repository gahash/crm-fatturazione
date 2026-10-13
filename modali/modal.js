(function () {
    function openModal(id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('active');
        modal.classList.add('show');
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('active');
        modal.classList.remove('show');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    document.addEventListener('click', function (e) {
        var openBtn = e.target.closest('[data-modal-open]');
        if (openBtn) {
            var target = openBtn.getAttribute('data-modal-open');
            if (target) {
                openModal(target);
            }
            return;
        }

        var closeBtn = e.target.closest('[data-modal-close]');
        if (closeBtn) {
            var modal = closeBtn.closest('.modal');
            closeModal(modal);
            return;
        }

        var modalEl = e.target.classList && e.target.classList.contains('modal') ? e.target : null;
        if (modalEl) {
            closeModal(modalEl);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.active, .modal.show').forEach(function (modal) {
                closeModal(modal);
            });
        }
    });
})();
