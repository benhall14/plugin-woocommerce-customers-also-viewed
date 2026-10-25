(() => {
    const field = document.querySelector('[data-copy-shortcode-field]');
    const button = document.querySelector('[data-copy-shortcode]');
    const status = document.querySelector('[data-copy-shortcode-status]');

    if (!field || !button || !status) {
        return;
    }

    const copyShortcode = async () => {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(field.value);
            } else {
                field.focus();
                field.select();
                field.setSelectionRange(0, field.value.length);
                if (!document.execCommand('copy')) {
                    throw new Error('Copy command failed');
                }
            }

            status.textContent = 'Shortcode copied.';
            button.textContent = 'Copied';
        } catch (error) {
            field.focus();
            field.select();
            status.textContent = 'Copy unavailable. Select the shortcode and copy it.';
        }
    };

    field.addEventListener('click', copyShortcode);
    button.addEventListener('click', copyShortcode);
})();
