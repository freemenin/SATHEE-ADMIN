"use strict";

document.addEventListener('DOMContentLoaded', function() {
    const links = [].slice.call(document.querySelectorAll('.docs-nav__links a'));

    links.forEach(link => {
        if (link.hash === window.location.hash) {
            link.classList.add('docs-nav__link--current');

            const section = document.querySelector(link.hash);

            window.addEventListener('load', () => {
                setTimeout(() => {
                    window.scrollTo({top: section.offsetTop});
                }, 0);
            });
        } else {
            link.classList.remove('docs-nav__link--current');
        }
    });

    window.addEventListener('hashchange', () => {
        links.forEach(link => {
            if (link.hash === window.location.hash) {
                link.classList.add('docs-nav__link--current');

                const section = document.querySelector(link.hash);
                window.scrollTo({top: section.offsetTop});
            } else {
                link.classList.remove('docs-nav__link--current');
            }
        });

        const nav = document.querySelector('.docs-nav');

        nav.classList.remove('docs-nav--open');
    });

    document.querySelector('.docs-header__menu').addEventListener('click', () => {
        const nav = document.querySelector('.docs-nav');

        if (nav.classList.contains('docs-nav--open')) {
            nav.classList.remove('docs-nav--open');
        } else {
            nav.classList.add('docs-nav--open');
        }
    });

    document.querySelector('.docs-nav-backdrop').addEventListener('click', () => {
        const nav = document.querySelector('.docs-nav');

        nav.classList.remove('docs-nav--open');
    });
});
