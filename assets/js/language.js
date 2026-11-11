(() => {
    'use strict';

    const supportedLanguages = new Set(['en', 'fr']);
    const dictionaryCache = new Map();
    const textState = new WeakMap();
    const attributeState = new WeakMap();
    const selector = document.getElementById('languageSelector');
    const scriptUrl = document.currentScript ? document.currentScript.src : '';
    const projectRoot = new URL('../../', scriptUrl);
    let currentLanguage = 'en';
    let currentDictionary = null;
    let observer = null;
    let isApplying = false;
    const titleElement = document.querySelector('title');
    const originalTitle = titleElement ? titleElement.textContent || '' : '';

    function normalize(value) {
        return value.replace(/\s+/g, ' ').trim();
    }

    function loadDictionary(language) {
        if (dictionaryCache.has(language)) {
            return dictionaryCache.get(language);
        }

        const url = new URL(`languages/${language}.json`, projectRoot);
        const request = fetch(url.href, {headers: {'Accept': 'application/json'}})
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Unable to load ${language} translations (${response.status}).`);
                }
                return response.json();
            })
            .then(dictionary => {
                if (!dictionary || typeof dictionary !== 'object'
                    || !dictionary.translations || !dictionary.phrases) {
                    throw new Error(`The ${language} translation dictionary has an invalid shape.`);
                }
                return dictionary;
            })
            .catch(error => {
                dictionaryCache.delete(language);
                throw error;
            });

        dictionaryCache.set(language, request);
        return request;
    }

    function lookup(key) {
        if (!currentDictionary || typeof key !== 'string') {
            return null;
        }
        return Object.prototype.hasOwnProperty.call(currentDictionary.translations, key)
            ? currentDictionary.translations[key]
            : null;
    }

    function translatePhrase(value) {
        if (!currentDictionary) {
            return null;
        }
        const key = currentDictionary.phrases[normalize(value)];
        return key ? lookup(key) : null;
    }

    function excluded(node) {
        const parent = node.parentElement;
        return !parent || Boolean(parent.closest(
            'script,style,noscript,textarea,input,code,pre,' +
            '[contenteditable="true"],[data-no-i18n],[data-user-content]'
        ));
    }

    function writeText(node, language, explicitKey) {
        const state = textState.get(node);
        const current = node.data;
        let original = state ? state.original : current;

        if (state && current !== state.lastApplied) {
            original = current;
        }

        const key = explicitKey || (currentDictionary && currentDictionary.phrases[normalize(original)]);
        const translated = key ? lookup(key) : null;
        if (translated === null) {
            textState.set(node, {original, lastApplied: current});
            return;
        }

        const match = current.match(/^(\s*)([\s\S]*?)(\s*)$/);
        const next = `${match ? match[1] : ''}${translated}${match ? match[3] : ''}`;
        if (node.data !== next) {
            node.data = next;
        }
        textState.set(node, {original, lastApplied: next});
    }

    function translateElementText(element) {
        const explicitKey = element.getAttribute('data-i18n');
        const directTextNodes = Array.from(element.childNodes)
            .filter(node => node.nodeType === Node.TEXT_NODE && normalize(node.data) !== '');

        if (explicitKey) {
            directTextNodes.forEach(node => writeText(node, currentLanguage, explicitKey));
        }

        const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
        let node;
        while ((node = walker.nextNode())) {
            if (excluded(node)) {
                continue;
            }
            const state = textState.get(node);
            const phrase = state && node.data === state.lastApplied ? state.original : node.data;
            const parent = node.parentElement;
            const key = parent && parent.closest('[data-i18n]')
                ? parent.closest('[data-i18n]').getAttribute('data-i18n')
                : null;
            if (key || translatePhrase(phrase) !== null) {
                writeText(node, currentLanguage, key);
            }
        }

        translateAttributes(element);
        element.querySelectorAll('[data-i18n],[data-i18n-placeholder],[data-i18n-title],[data-i18n-aria-label],[placeholder],[title],[aria-label]')
            .forEach(translateElementAttributes);
    }

    function translateAttributes(root) {
        if (root.nodeType === Node.ELEMENT_NODE) {
            translateElementAttributes(root);
        }
    }

    function translateElementAttributes(element) {
        const attributes = [
            ['placeholder', 'data-i18n-placeholder'],
            ['title', 'data-i18n-title'],
            ['aria-label', 'data-i18n-aria-label'],
            ['data-confirm', 'data-i18n-confirm']
        ];

        attributes.forEach(([attribute, keyAttribute]) => {
            if (!element.hasAttribute(attribute)) {
                return;
            }

            let states = attributeState.get(element);
            if (!states) {
                states = new Map();
                attributeState.set(element, states);
            }
            const current = element.getAttribute(attribute) || '';
            const state = states.get(attribute);
            const original = state && state.lastApplied === current ? state.original : current;
            const key = element.getAttribute(keyAttribute)
                || (currentDictionary && currentDictionary.phrases[normalize(original)]);
            const translated = key ? lookup(key) : null;
            if (translated !== null) {
                if (current !== translated) {
                    element.setAttribute(attribute, translated);
                }
                states.set(attribute, {original, lastApplied: translated});
            } else {
                states.set(attribute, {original, lastApplied: current});
            }
        });
    }

    function applyTranslations() {
        if (!currentDictionary || isApplying) {
            return;
        }

        isApplying = true;
        document.documentElement.lang = currentLanguage;
        translateElementText(document.body);

        const title = document.querySelector('title');
        if (title) {
            const titleParts = originalTitle.split(' · ');
            const prefix = title.getAttribute('data-i18n-prefix');
            let translatedHeading = translatePhrase(titleParts[0]);
            if (prefix && titleParts[0].startsWith(prefix)) {
                const translatedPrefix = translatePhrase(prefix);
                if (translatedPrefix !== null) {
                    translatedHeading = translatedPrefix + titleParts[0].slice(prefix.length);
                }
            }
            if (translatedHeading !== null) {
                title.textContent = [translatedHeading, ...titleParts.slice(1)].join(' · ');
            }
        }

        if (selector) {
            selector.value = currentLanguage;
        }
        isApplying = false;
    }

    async function setLanguage(language) {
        const requested = supportedLanguages.has(language) ? language : 'en';
        try {
            currentDictionary = await loadDictionary(requested);
            currentLanguage = requested;
        } catch (error) {
            console.error(error);
            if (requested === 'fr') {
                currentLanguage = 'en';
                try {
                    currentDictionary = await loadDictionary('en');
                } catch (fallbackError) {
                    console.error(fallbackError);
                    return false;
                }
            } else {
                return false;
            }
        }

        try {
            localStorage.setItem('hospital-crm-language', currentLanguage);
        } catch (error) {
            console.warn('Language preference could not be saved in this browser.', error);
        }

        applyTranslations();
        return true;
    }

    function t(key) {
        return lookup(key) || key;
    }

    function getCurrentLanguage() {
        return currentLanguage;
    }

    window.setLanguage = setLanguage;
    window.getCurrentLanguage = getCurrentLanguage;
    window.t = t;

    if (selector) {
        selector.addEventListener('change', event => {
            setLanguage(event.currentTarget.value);
        });
    }

    function startObserver() {
        observer = new MutationObserver(records => {
            if (isApplying) {
                return;
            }
            records.forEach(record => {
                if (record.type === 'attributes') {
                    if (record.attributeName === 'data-i18n') {
                        translateElementText(record.target);
                    } else {
                        translateElementAttributes(record.target);
                    }
                    return;
                }
                if (record.type === 'characterData') {
                    if (!excluded(record.target) && translatePhrase(record.target.data) !== null) {
                        writeText(record.target, currentLanguage, null);
                    }
                    return;
                }
                record.addedNodes.forEach(node => {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        translateElementText(node);
                    } else if (node.nodeType === Node.TEXT_NODE && !excluded(node)) {
                        const parent = node.parentElement;
                        const explicit = parent && parent.closest('[data-i18n]');
                        if (explicit || translatePhrase(node.data) !== null) {
                            writeText(node, currentLanguage, explicit ? explicit.getAttribute('data-i18n') : null);
                        }
                    }
                });
            });
        });
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true,
            attributes: true,
            attributeFilter: [
                'data-i18n',
                'data-i18n-placeholder',
                'data-i18n-title',
                'data-i18n-aria-label',
                'data-i18n-confirm',
                'placeholder',
                'title',
                'aria-label',
                'data-confirm'
            ]
        });
    }

    const initialLanguage = (() => {
        try {
            const saved = localStorage.getItem('hospital-crm-language');
            return supportedLanguages.has(saved) ? saved : 'en';
        } catch (error) {
            return 'en';
        }
    })();

    startObserver();
    setLanguage(initialLanguage);
})();
