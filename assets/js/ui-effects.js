(() => {
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const navPath = window.location.pathname.replace(/\/+$/, '');
    document.querySelectorAll('.sidebar nav a').forEach((link) => {
        const linkPath = new URL(link.href, window.location.origin).pathname.replace(/\/+$/, '');
        if (linkPath === navPath) link.setAttribute('aria-current', 'page');
    });

    const revealItems = document.querySelectorAll('.page-heading, .panel, .stat-card');
    if (revealItems.length && !reducedMotion) {
        document.documentElement.classList.add('has-ui-effects');
        revealItems.forEach((item, index) => {
            item.dataset.reveal = '';
            item.style.setProperty('--reveal-delay', `${Math.min(index % 5, 4) * 65}ms`);
        });

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries, activeObserver) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('is-visible');
                    activeObserver.unobserve(entry.target);
                });
            }, { threshold: 0.08 });
            revealItems.forEach((item) => observer.observe(item));
        } else {
            revealItems.forEach((item) => item.classList.add('is-visible'));
        }
    }

    if (!reducedMotion) {
        document.querySelectorAll('.stat-value').forEach((node) => {
            const value = node.textContent.trim();
            if (!/^\d{1,3}(?:\.\d{3})*$|^\d+$/.test(value)) return;
            const target = Number(value.replace(/\./g, ''));
            if (!Number.isSafeInteger(target) || target === 0) return;
            const formatter = new Intl.NumberFormat('de-DE');
            const start = performance.now();
            const duration = 750;
            const animate = (now) => {
                const progress = Math.min((now - start) / duration, 1);
                const eased = 1 - (1 - progress) ** 4;
                node.textContent = formatter.format(Math.round(target * eased));
                if (progress < 1) window.requestAnimationFrame(animate);
            };
            window.requestAnimationFrame(animate);
        });

        if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            let frame = 0;
            window.addEventListener('pointermove', (event) => {
                if (frame) return;
                frame = window.requestAnimationFrame(() => {
                    document.body.style.setProperty('--pointer-x', `${event.clientX}px`);
                    document.body.style.setProperty('--pointer-y', `${event.clientY}px`);
                    frame = 0;
                });
            }, { passive: true });
        }
    }

    document.addEventListener('pointerdown', (event) => {
        const control = event.target.closest('button, .button');
        if (!control || reducedMotion) return;
        const bounds = control.getBoundingClientRect();
        control.style.setProperty('--ripple-x', `${event.clientX - bounds.left}px`);
        control.style.setProperty('--ripple-y', `${event.clientY - bounds.top}px`);
        control.classList.remove('is-rippling');
        void control.offsetWidth;
        control.classList.add('is-rippling');
        window.setTimeout(() => control.classList.remove('is-rippling'), 500);
    });
})();
