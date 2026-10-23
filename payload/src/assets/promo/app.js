(() => {
    'use strict';

    const burgerButton = document.querySelector('.nav__burger');
    const burger = document.querySelector('.burger');
    const burgerBack = document.querySelector('.burger__back');
    const burgerCross = document.querySelector('.burger-menu__cross');
    const burgerLinks = document.querySelectorAll('.burger-menu__link');

    if (!burgerButton || !burger || !burgerBack || !burgerCross) {
        return;
    }

    const setOpen = (open) => {
        burger.classList.toggle('burger_active', open);
        burger.setAttribute('aria-hidden', open ? 'false' : 'true');
        burgerButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.body.classList.toggle('menu-open', open);

        if (open) {
            burgerCross.focus();
        } else {
            burgerButton.focus();
        }
    };

    burgerButton.addEventListener('click', () => setOpen(true));
    burgerBack.addEventListener('click', () => setOpen(false));
    burgerCross.addEventListener('click', () => setOpen(false));
    burgerLinks.forEach((link) => link.addEventListener('click', () => setOpen(false)));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && burger.classList.contains('burger_active')) {
            setOpen(false);
        }
    });
})();
