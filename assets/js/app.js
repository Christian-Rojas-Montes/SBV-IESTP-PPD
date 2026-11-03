const themeKey = 'sbv-theme';

try {
    if (localStorage.getItem(themeKey) === 'dark') {
        document.documentElement.dataset.theme = 'dark';
    }
} catch (error) {
    console.warn('No se pudo leer la preferencia del tema.', error);
}

document.addEventListener('DOMContentLoaded', () => {
    const themeButtons = document.querySelectorAll('[data-theme-toggle]');
    const updateThemeButtons = () => {
        const isDark = document.documentElement.dataset.theme === 'dark';
        themeButtons.forEach((button) => {
            button.setAttribute('aria-pressed', String(isDark));
            button.setAttribute('aria-label', isDark ? 'Activar modo claro' : 'Activar modo oscuro');
            const icon = button.querySelector('.theme-toggle-icon');
            const label = button.querySelector('.theme-toggle-label');
            if (icon) icon.textContent = isDark ? '☀' : '☾';
            if (label) label.textContent = isDark ? 'Modo claro' : 'Modo oscuro';
        });
    };

    updateThemeButtons();
    themeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const isDark = document.documentElement.dataset.theme !== 'dark';
            if (isDark) {
                document.documentElement.dataset.theme = 'dark';
            } else {
                delete document.documentElement.dataset.theme;
            }

            try {
                localStorage.setItem(themeKey, isDark ? 'dark' : 'light');
            } catch (error) {
                console.warn('No se pudo guardar la preferencia del tema.', error);
            }
            updateThemeButtons();
        });
    });

    const navLinks = document.querySelectorAll('.main-nav a');
    navLinks.forEach((link) => {
        link.addEventListener('click', () => {
            navLinks.forEach((item) => item.classList.remove('active'));
            link.classList.add('active');
        });
    });
});

const appScript = document.currentScript || Array.from(document.scripts).find((script) => script.src.endsWith('/assets/js/app.js'));
if (appScript) {
    const webauthnScript = document.createElement('script');
    webauthnScript.src = new URL('webauthn.js', appScript.src).href;
    webauthnScript.defer = true;
    document.head.appendChild(webauthnScript);
}
