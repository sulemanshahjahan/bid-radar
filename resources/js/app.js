if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}

function legacyCopy(text) {
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    document.execCommand('copy');
    area.remove();
}

// Copy text to the clipboard and show a toast (used by the Copy buttons).
// Falls back to execCommand if the Clipboard API is missing, refuses, or stalls.
window.copyText = async (text, label = 'Copied') => {
    try {
        if (!navigator.clipboard) throw new Error('no clipboard api');
        await Promise.race([
            navigator.clipboard.writeText(text),
            new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), 1000)),
        ]);
    } catch {
        legacyCopy(text);
    }
    window.dispatchEvent(new CustomEvent('copied', { detail: label }));
};
