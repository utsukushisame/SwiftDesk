document.addEventListener('DOMContentLoaded', () => {
    // DOM Elements
    const editor = document.getElementById('editor');
    const wordCountEl = document.getElementById('word-count');
    const charCountEl = document.getElementById('char-count');
    const btnCheck = document.getElementById('btn-check');
    const btnClear = document.getElementById('btn-clear');
    const btnFixAll = document.getElementById('btn-fix-all');
    const loadingOverlay = document.getElementById('loading-overlay');
    const statusMessage = document.getElementById('status-message');
    const errorsList = document.getElementById('errors-list');
    const themeToggle = document.getElementById('theme-toggle');
    const tooltip = document.getElementById('error-tooltip');
    const tooltipType = tooltip.querySelector('.tooltip-type');
    const tooltipTitle = tooltip.querySelector('.tooltip-title');
    const tooltipSuggestions = document.getElementById('tooltip-suggestions');

    // State
    let activeErrors = [];
    let currentActiveSpan = null;

    // --- Theme Handling ---
    const updateThemeIcon = (isDark) => {
        themeToggle.innerHTML = isDark 
            ? '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>'
            : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
    };

    // Default to dark mode based on previous CSS, or system pref
    let isDark = true; 
    document.documentElement.setAttribute('data-theme', 'dark');
    updateThemeIcon(true);

    themeToggle.addEventListener('click', () => {
        isDark = !isDark;
        document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
        updateThemeIcon(isDark);
    });


    // --- Editor Handling ---
    
    // Paste as plain text to avoid weird formatting
    editor.addEventListener('paste', (e) => {
        e.preventDefault();
        const text = (e.originalEvent || e).clipboardData.getData('text/plain');
        document.execCommand('insertText', false, text);
    });

    // Update Counters
    const updateCounters = () => {
        const text = editor.innerText || '';
        const chars = text.length;
        const words = text.trim() === '' ? 0 : text.trim().split(/\s+/).length;
        
        charCountEl.textContent = chars;
        wordCountEl.textContent = words;
    };

    editor.addEventListener('input', updateCounters);


    // --- Clear ---
    btnClear.addEventListener('click', () => {
        editor.innerHTML = '';
        updateCounters();
        resetErrors();
        statusMessage.textContent = 'Pripravený';
    });


    // --- Mock NLP Checker ---
    
    // Simulate finding errors. In reality, this would be an API call to a Python NLP backend.
    const mockCheckText = async (text) => {
        return new Promise(resolve => {
            setTimeout(() => {
                const errors = [];
                // Simple mock logic: Find specific words or patterns
                
                // Grammar mock: 'mi' vs 'my'
                const myRegex = /\b(My)\s+sme\b/gi;
                let match;
                while ((match = myRegex.exec(text)) !== null) {
                    if (match[1] === 'mi' || match[1] === 'Mi') {
                         // Let's just create a generic grammar error if someone writes "mi sme"
                    }
                }

                // Let's just create arbitrary errors for demonstration based on common mistakes
                const textLower = text.toLowerCase();
                
                // 1. Punctuation missing before 'že'
                let index = textLower.indexOf(' že ');
                if (index > 0 && text[index - 1] !== ',') {
                    errors.push({
                        id: 'err_' + Date.now() + Math.random(),
                        type: 'punctuation',
                        original: text.substring(Math.max(0, index - 5), index + 3), // context
                        targetText: 'že', // word to highlight
                        title: 'Chýbajúca čiarka',
                        desc: 'Pred spojkou "že" sa spravidla píše čiarka.',
                        suggestions: [', že']
                    });
                }

                // 2. Grammar: "napadlo ma" -> "napadlo mi"
                let idxMna = textLower.indexOf('napadlo ma');
                if (idxMna !== -1) {
                    errors.push({
                        id: 'err_' + Date.now() + Math.random(),
                        type: 'grammar',
                        original: text.substring(idxMna, idxMna + 10),
                        targetText: text.substring(idxMna, idxMna + 10),
                        title: 'Nesprávna väzba',
                        desc: 'Správne je "napadlo mi" (komu, čomu), nie "napadlo ma".',
                        suggestions: ['napadlo mi']
                    });
                }

                // 3. Grammar: "kôli" -> "kvôli"
                let idxKoli = textLower.indexOf('kôli');
                if (idxKoli !== -1) {
                     errors.push({
                        id: 'err_' + Date.now() + Math.random(),
                        type: 'grammar',
                        original: text.substring(idxKoli, idxKoli + 4),
                        targetText: text.substring(idxKoli, idxKoli + 4),
                        title: 'Pravopisná chyba',
                        desc: 'Slovo sa píše s "v".',
                        suggestions: ['kvôli']
                    });
                }
                
                resolve(errors);
            }, 1200); // 1.2s delay
        });
    };

    const resetErrors = () => {
        activeErrors = [];
        errorsList.innerHTML = `
            <div class="empty-state">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                <p>Zatiaľ žiadne chyby. Napíšte text a spustite kontrolu.</p>
            </div>
        `;
        btnFixAll.disabled = true;
        hideTooltip();
    };

    // --- Highlighting Logic ---
    const highlightErrors = (text, errors) => {
        let highlightedHtml = text;
        
        // Very basic replace for demonstration. 
        // In a real app, use character indices to avoid replacing HTML tags.
        errors.forEach(err => {
            const spanClass = err.type === 'grammar' ? 'err-grammar' : 'err-punct';
            const spanHtml = `<span class="${spanClass}" data-err-id="${err.id}">${err.targetText}</span>`;
            
            // Replace first occurrence (simplified)
            highlightedHtml = highlightedHtml.replace(err.targetText, spanHtml);
        });

        // Preserve newlines as br
        editor.innerHTML = highlightedHtml.replace(/\n/g, '<br>');
    };

    const renderSidebarErrors = () => {
        if (activeErrors.length === 0) {
            resetErrors();
            return;
        }

        btnFixAll.disabled = false;
        errorsList.innerHTML = '';
        
        activeErrors.forEach(err => {
            const card = document.createElement('div');
            card.className = `error-card ${err.type === 'grammar' ? 'grammar' : 'punct'}`;
            card.dataset.errId = err.id;
            
            card.innerHTML = `
                <div class="err-card-header">
                    <span>${err.type === 'grammar' ? 'Gramatika' : 'Interpunkcia'}</span>
                </div>
                <div class="err-card-title">${err.title}</div>
                <div class="err-card-context">"...${err.original}..."</div>
                <div class="suggestion-preview">Návrh: <strong>${err.suggestions[0]}</strong></div>
            `;

            card.addEventListener('click', () => {
                // Find span in editor
                const span = document.querySelector(`span[data-err-id="${err.id}"]`);
                if (span) {
                    span.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    // Simulate click to open tooltip
                    openTooltip(span, err);
                }
            });

            errorsList.appendChild(card);
        });
    };

    btnCheck.addEventListener('click', async () => {
        const text = editor.innerText;
        if (!text.trim()) return;

        btnCheck.disabled = true;
        loadingOverlay.classList.remove('hidden');
        statusMessage.textContent = 'Analyzujem...';
        hideTooltip();

        try {
            const errors = await mockCheckText(text);
            activeErrors = errors;
            
            if (errors.length > 0) {
                highlightErrors(text, errors);
                renderSidebarErrors();
                statusMessage.textContent = `Nájdené chyby: ${errors.length}`;
            } else {
                editor.innerHTML = text.replace(/\n/g, '<br>'); // Reset without spans
                resetErrors();
                statusMessage.textContent = 'Text je v poriadku!';
            }
        } catch (e) {
            console.error(e);
            statusMessage.textContent = 'Chyba pri analýze.';
        } finally {
            loadingOverlay.classList.add('hidden');
            btnCheck.disabled = false;
        }
    });

    // --- Tooltip Logic ---
    const showTooltip = (x, y) => {
        tooltip.style.left = `${x}px`;
        tooltip.style.top = `${y + 10}px`; // slightly below
        tooltip.classList.remove('hidden');
    };

    const hideTooltip = () => {
        tooltip.classList.add('hidden');
        if (currentActiveSpan) {
            currentActiveSpan.classList.remove('err-active');
            currentActiveSpan = null;
        }
    };

    const openTooltip = (span, errData) => {
        hideTooltip();
        
        currentActiveSpan = span;
        span.classList.add('err-active');

        tooltipType.textContent = errData.type === 'grammar' ? 'Gramatika' : 'Interpunkcia';
        tooltipTitle.textContent = errData.title;
        
        tooltipSuggestions.innerHTML = '';
        
        const descEl = tooltip.querySelector('.tooltip-desc');
        descEl.textContent = errData.desc;

        errData.suggestions.forEach(sugg => {
            const btn = document.createElement('button');
            btn.className = 'suggestion-btn';
            btn.textContent = sugg;
            btn.addEventListener('click', () => {
                applyFix(span, errData.id, sugg);
            });
            tooltipSuggestions.appendChild(btn);
        });

        const rect = span.getBoundingClientRect();
        // Calculate position relative to document
        const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        
        let x = rect.left + scrollLeft;
        let y = rect.bottom + scrollTop;

        // Ensure it doesn't go off screen
        const tooltipWidth = 250;
        if (x + tooltipWidth > window.innerWidth) {
            x = window.innerWidth - tooltipWidth - 20;
        }

        showTooltip(x, y);
    };

    // Event delegation for spans in editor
    editor.addEventListener('click', (e) => {
        if (e.target.tagName === 'SPAN' && e.target.hasAttribute('data-err-id')) {
            const errId = e.target.getAttribute('data-err-id');
            const errData = activeErrors.find(err => err.id === errId);
            if (errData) {
                openTooltip(e.target, errData);
            }
            e.stopPropagation();
        } else {
            hideTooltip();
        }
    });

    // Hide tooltip when clicking outside
    document.addEventListener('click', (e) => {
        if (!tooltip.contains(e.target) && e.target !== currentActiveSpan) {
            hideTooltip();
        }
    });

    const applyFix = (span, errId, replacementText) => {
        // Replace text in editor
        const textNode = document.createTextNode(replacementText);
        span.parentNode.replaceChild(textNode, span);
        hideTooltip();

        // Remove from active errors
        activeErrors = activeErrors.filter(err => err.id !== errId);
        
        // Re-render sidebar
        renderSidebarErrors();
        
        // Update stats
        updateCounters();
        
        statusMessage.textContent = activeErrors.length > 0 ? `Nájdené chyby: ${activeErrors.length}` : 'Text je v poriadku!';
    };

    // --- Fix All ---
    btnFixAll.addEventListener('click', () => {
        if (activeErrors.length === 0) return;

        // We iterate backwards to not mess up indices if we were using text indices
        // But since we use DOM spans, we can just query them
        [...activeErrors].forEach(err => {
            const span = document.querySelector(`span[data-err-id="${err.id}"]`);
            if (span && err.suggestions.length > 0) {
                applyFix(span, err.id, err.suggestions[0]);
            }
        });
    });

    // --- Keyboard Shortcuts ---
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            btnCheck.click();
        }
    });
});
