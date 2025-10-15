/**
 * HashPoster Admin JavaScript - Modern Vanilla JS Implementation
 * No jQuery dependency, uses ES6+ features
 */

class HashPosterAdmin {
    constructor() {
        this.ajaxUrl = '';
        this.nonce = '';
        this.init();
    }

    init() {
        try {
            console.log('HashPosterAdmin init() called');
            // Wait for hashposterAdmin to be available
            this.waitForHashPosterAdmin().then(() => {
                console.log('hashposterAdmin object found');
                this.ajaxUrl = window.hashposterAdmin?.ajax_url || '';
                this.nonce = window.hashposterAdmin?.nonce || '';
                console.log('AJAX URL:', this.ajaxUrl);
                console.log('Nonce available:', !!this.nonce);
                
                this.initTabs();
                this.initTooltips();
                this.initKeyboardNavigation();
            }).catch(error => {
                console.error('Error waiting for hashposterAdmin:', error);
            });
        } catch (error) {
            console.error('Error in HashPosterAdmin init():', error);
        }
    }

    /**
     * Wait for hashposterAdmin object to be available
     */
    async waitForHashPosterAdmin() {
        return new Promise((resolve) => {
            const checkForHashPosterAdmin = () => {
                if (window.hashposterAdmin) {
                    resolve();
                } else {
                    setTimeout(checkForHashPosterAdmin, 10);
                }
            };
            checkForHashPosterAdmin();
        });
    }

    /**
     * Initialize tab functionality
     */
    initTabs() {
        const tabContainer = document.getElementById('hashposter-tabs');
        if (!tabContainer) return;

        const tabLinks = tabContainer.querySelectorAll('ul li a');
        const tabContents = tabContainer.querySelectorAll('div[id^="tab-"]');

        // Set first tab as active by default
        if (tabLinks.length > 0) {
            this.setActiveTab(tabLinks[0]);
        }

        // Add click handlers
        tabLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                this.setActiveTab(link);
            });
        });
    }

    /**
     * Set active tab
     */
    setActiveTab(activeLink) {
        const tabContainer = document.getElementById('hashposter-tabs');
        if (!tabContainer) return;

        const tabLinks = tabContainer.querySelectorAll('ul li a');
        const tabContents = tabContainer.querySelectorAll('div[id^="tab-"]');

        // Remove active class from all tabs
        tabLinks.forEach(link => link.classList.remove('active'));

        // Add active class to current tab
        activeLink.classList.add('active');

        // Hide all tab content
        tabContents.forEach(content => content.style.display = 'none');

        // Show the current tab content
        const targetId = activeLink.getAttribute('href')?.substring(1);
        const targetContent = document.getElementById(targetId);
        if (targetContent) {
            targetContent.style.display = 'block';
        }
    }

    /**
     * Initialize tooltips
     */
    initTooltips() {
        const tooltipElements = document.querySelectorAll('.tooltip');

        tooltipElements.forEach(element => {
            const title = element.getAttribute('title');
            if (!title) return;

            element.dataset.tipText = title;
            element.removeAttribute('title');

            // Add event listeners
            element.addEventListener('mouseenter', (e) => this.showTooltip(e, title));
            element.addEventListener('mouseleave', () => this.hideTooltip());
            element.addEventListener('mousemove', (e) => this.moveTooltip(e));
        });
    }

    /**
     * Show tooltip
     */
    showTooltip(event, title) {
        this.hideTooltip(); // Remove any existing tooltip

        const tooltip = document.createElement('p');
        tooltip.className = 'hashposter-tooltip';
        tooltip.textContent = title;
        tooltip.style.cssText = `
            position: fixed;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 14px;
            pointer-events: none;
            z-index: 9999;
            max-width: 300px;
            word-wrap: break-word;
        `;

        document.body.appendChild(tooltip);
        this.moveTooltip(event);
        this.fadeIn(tooltip, 200);
    }

    /**
     * Hide tooltip
     */
    hideTooltip() {
        const existingTooltip = document.querySelector('.hashposter-tooltip');
        if (existingTooltip) {
            this.fadeOut(existingTooltip, 200, () => {
                if (existingTooltip.parentNode) {
                    existingTooltip.parentNode.removeChild(existingTooltip);
                }
            });
        }
    }

    /**
     * Move tooltip with mouse
     */
    moveTooltip(event) {
        const tooltip = document.querySelector('.hashposter-tooltip');
        if (!tooltip) return;

        const mouseX = event.pageX + 10;
        const mouseY = event.pageY + 10;

        tooltip.style.left = mouseX + 'px';
        tooltip.style.top = mouseY + 'px';
    }

    /**
     * Initialize keyboard navigation for accessibility
     */
    initKeyboardNavigation() {
        const tabContainer = document.getElementById('hashposter-tabs');
        if (!tabContainer) return;

        const tabLinks = tabContainer.querySelectorAll('ul li a');

        tabLinks.forEach((link, index) => {
            link.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    const nextIndex = (index + 1) % tabLinks.length;
                    tabLinks[nextIndex].focus();
                } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    const prevIndex = index === 0 ? tabLinks.length - 1 : index - 1;
                    tabLinks[prevIndex].focus();
                } else if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.setActiveTab(link);
                }
            });
        });
    }

    /**
     * Fade in element
     */
    fadeIn(element, duration = 300) {
        element.style.opacity = '0';
        element.style.display = 'block';

        const start = performance.now();

        const fade = (timestamp) => {
            const elapsed = timestamp - start;
            const progress = elapsed / duration;

            if (progress < 1) {
                element.style.opacity = progress;
                requestAnimationFrame(fade);
            } else {
                element.style.opacity = '1';
            }
        };

        requestAnimationFrame(fade);
    }

    /**
     * Fade out element
     */
    fadeOut(element, duration = 300, callback = null) {
        const start = performance.now();
        const startOpacity = parseFloat(getComputedStyle(element).opacity) || 1;

        const fade = (timestamp) => {
            const elapsed = timestamp - start;
            const progress = elapsed / duration;

            if (progress < 1) {
                element.style.opacity = startOpacity * (1 - progress);
                requestAnimationFrame(fade);
            } else {
                element.style.opacity = '0';
                element.style.display = 'none';
                if (callback) callback();
            }
        };

        requestAnimationFrame(fade);
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        new HashPosterAdmin();
    });
} else {
    new HashPosterAdmin();
}

// Export for potential use in other scripts
if (typeof window !== 'undefined') {
    window.HashPosterAdmin = HashPosterAdmin;
}