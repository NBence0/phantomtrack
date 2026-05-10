/**
 * Log System V6.0 - God Mode & Deep Telemetry
 * PhantomTrack verzió - Per-Galéria támogatással
 */

// 0. GLOBAL ERROR HANDLER
window.onerror = function(message, source, lineno, colno, error) {
    if (!window.galleryId) return;
    fetch('/log.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'client_error',
            category: 'error',
            gallery_id: window.galleryId,
            data: {
                message: message,
                file: source,
                line: lineno,
                col: colno,
                stack: error ? error.stack : ''
            }
        })
    }).catch(() => {});
};

const LogSystem = {
    startTime: Date.now(),
    lastHiddenTime: null,
    clickCount: 0,
    clickTimer: null,
    lastClickElement: null,

    init: function() {
        if (!window.galleryId) {
            console.warn('LogSystem: Nincs window.galleryId definiálva!');
            return;
        }

        this.trackDetailedInteractions();
        this.trackPerformance();
        this.trackVisibility();
        this.trackHardware();
        this.trackPageExit();

        console.log('👁️ PhantomTrack LogSystem V6.0 Active for Gallery ' + window.galleryId);
    },

    send: function(action, data = {}, category = 'general', useBeacon = false) {
        if (!window.galleryId) return;

        const payload = JSON.stringify({
            action: action,
            category: category,
            gallery_id: window.galleryId,
            data: {
                ...data,
                page_url: window.location.href,
                timestamp_client: new Date().toISOString()
            }
        });

        const endpoint = '/log.php';

        if (useBeacon && navigator.sendBeacon) {
            const blob = new Blob([payload], { type: 'application/json' });
            navigator.sendBeacon(endpoint, blob);
        } else {
            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: payload
            }).catch(err => console.warn('Logging failed:', err));
        }
    },

    trackDetailedInteractions: function() {
        document.addEventListener('click', (e) => {
            const el = e.target;
            
            if (el === this.lastClickElement) {
                this.clickCount++;
            } else {
                this.clickCount = 1;
                this.lastClickElement = el;
            }

            clearTimeout(this.clickTimer);
            this.clickTimer = setTimeout(() => {
                if (this.clickCount >= 3) {
                    this.send('rage_click', {
                        clicks: this.clickCount,
                        selector: this.getSelector(el),
                        text: el.innerText ? el.innerText.substring(0, 30) : '',
                        section: this.getSection(el)
                    }, 'interaction');
                }
                this.clickCount = 0;
            }, 1000);

            const isInteractive = el.tagName === 'A' || el.tagName === 'BUTTON' || 
                                  el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || 
                                  el.closest('a') || el.closest('button') || 
                                  el.onclick != null || 
                                  (el.tagName === 'IMG' && el.closest('.gallery-container')); 

            if (!isInteractive) {
                const selection = window.getSelection().toString();
                if (selection.length === 0) {
                    this.send('dead_click', {
                        selector: this.getSelector(el),
                        x: e.clientX,
                        y: e.clientY + window.scrollY
                    }, 'interaction');
                }
            }
        });

        document.addEventListener('contextmenu', (e) => {
            const el = e.target;
            const baseData = {
                x: e.clientX,
                y: e.clientY + window.scrollY,
                section: this.getSection(el)
            };

            if (el.tagName === 'IMG') {
                this.send('image_context_menu', {
                    ...baseData,
                    selector: 'img',
                    text: el.src.split('/').pop(),
                    metadata: { src: el.src }
                }, 'security');
            } else if (el.tagName === 'A') {
                this.send('link_context_menu', {
                    ...baseData,
                    selector: 'a',
                    text: el.innerText,
                    metadata: { href: el.href }
                }, 'interaction');
            }
        });

        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                this.send('save_page_attempt', { selector: 'window' }, 'security');
            }
        });

        document.addEventListener('copy', () => {
            const text = document.getSelection().toString();
            if (text.length > 0) {
                this.send('copy_text', {
                    text_preview: text.substring(0, 50) + (text.length > 50 ? '...' : ''),
                    length: text.length
                }, 'interaction');
            }
        });
    },

    trackHardware: function() {
        if ('getBattery' in navigator) {
            navigator.getBattery().then(battery => {
                this.send('battery_status', {
                    level: Math.round(battery.level * 100) + '%',
                    charging: battery.charging
                }, 'system');
            });
        }
        if ('connection' in navigator) {
            const conn = navigator.connection;
            this.send('network_info', {
                type: conn.effectiveType,
                saveData: conn.saveData,
                rtt: conn.rtt
            }, 'system');
        }
    },

    trackPerformance: function() {
        window.addEventListener('load', () => {
            this.send('device_info', {
                viewport: { width: window.innerWidth, height: window.innerHeight },
                screen: `${window.screen.width}x${window.screen.height}`,
                language: navigator.language
            }, 'general');

            setTimeout(() => {
                const perf = performance.getEntriesByType("navigation")[0];
                if (perf) {
                    this.send('page_performance', {
                        ttfb: Math.round(perf.responseStart - perf.requestStart),
                        dom_load: Math.round(perf.domContentLoadedEventEnd - perf.responseEnd),
                        full_load: Math.round(perf.loadEventEnd - perf.startTime),
                        resources: performance.getEntriesByType("resource").length
                    }, 'performance');
                }
            }, 1000);
        });
    },

    trackVisibility: function() {
        document.addEventListener("visibilitychange", () => {
            if (document.hidden) {
                this.lastHiddenTime = Date.now();
                this.send('tab_hidden', {}, 'system');
            } else {
                if (this.lastHiddenTime) {
                    const duration = Date.now() - this.lastHiddenTime;
                    this.send('tab_visible', { duration: duration }, 'system');
                }
            }
        });
    },

    trackPageExit: function() {
        window.addEventListener('beforeunload', () => {
            const timeOnPage = Date.now() - this.startTime;
            const docHeight = Math.max(
                document.body.scrollHeight, document.documentElement.scrollHeight,
                document.body.offsetHeight, document.documentElement.offsetHeight,
                document.body.clientHeight, document.documentElement.clientHeight
            );
            const scroll = Math.round((window.scrollY / (docHeight - window.innerHeight)) * 100);

            this.send('page_exit', {
                time_ms: timeOnPage,
                time_sec: Math.round(timeOnPage / 1000),
                scroll_depth_percent: isNaN(scroll) ? 0 : scroll
            }, 'analytics', true);
        });
    },

    logImageView: function(imageName, index) {
        this.send('lightbox_view', { image: imageName, index: index }, 'gallery');
    },

    getSection: function(el) {
        if (!el) return 'unknown';
        if (el.closest('.infobox')) return 'infobox';
        if (el.closest('.thumbnails')) return 'gallery';
        if (el.closest('#lightbox')) return 'lightbox';
        if (el.closest('.forum')) return 'forum';
        return 'body';
    },

    getSelector: function(el) {
        if (!el) return 'unknown';
        let s = el.tagName.toLowerCase();
        if (el.id) s += `#${el.id}`;
        else if (el.className) s += `.${el.className.split(' ')[0]}`;
        return s;
    }
};

document.addEventListener('DOMContentLoaded', () => {
    LogSystem.init();
    window.LogSystem = LogSystem;
});
